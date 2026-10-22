<?php

namespace App\Services\RhMobile;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Bandeja unificada de RH: junta lo pendiente de revision de los 4 tipos de
 * recurso gestionables desde la app (solicitud, vacaciones, documento,
 * incorporacion) en un solo listado paginado, cada quien acotado por
 * permiso + alcance organizacional. No hay una tabla "pendientes": es una
 * vista calculada sobre los 4 modelos, igual criterio que
 * App\Services\Expedientes\ExpedienteService. Ver seccion 7 del encargo
 * movil y docs/RH_MOBILE_API.md.
 *
 * Limite por tipo: acota cada consulta a un numero razonable de candidatos
 * antes de fusionar/paginar en memoria (organizacion tipica: cientos, no
 * millones de solicitudes pendientes simultaneas). Si el volumen crece
 * mucho, esto debe migrar a una vista SQL materializada con UNION.
 */
class RhPendientesService
{
    private const LIMITE_POR_TIPO = 300;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly IncorporacionService $incorporacion,
        private readonly SolicitudesService $solicitudes,
    ) {}

    /**
     * @param  array<string, mixed>  $filtros  tipo, q, sucursal_id, departamento_id, page, per_page
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function bandeja(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $tipo = $filtros['tipo'] ?? 'todos';
        $items = [];

        // Solicitudes y vacaciones son el MISMO recurso (solicitudes_internas):
        // las vacaciones son un `tipo` del motor unificado, no una tabla
        // aparte. Se gobiernan con la MISMA SolicitudInternaPolicy + alcance de
        // módulo que /rh/solicitudes y el Portal web (P1-12), nunca con un gate
        // o alcance distinto por pantalla. La baja de colaborador se excluye:
        // se gestiona en el Cierre laboral, no se aprueba/rechaza aquí (P1-9).
        if (in_array($tipo, ['todos', 'solicitud', 'vacaciones'], true) && Gate::forUser($usuario)->allows('viewAny', SolicitudInterna::class)) {
            $solicitudes = $this->solicitudes($usuario, $filtros, soloVacaciones: $tipo === 'vacaciones', sinVacaciones: $tipo === 'solicitud');
            $items = array_merge($items, $solicitudes->all());
        }

        if (in_array($tipo, ['todos', 'documento'], true) && $usuario->can('rh.documentos.ver')) {
            $documentos = $this->documentos($usuario, $filtros);
            $items = array_merge($items, $documentos->all());
        }

        if (in_array($tipo, ['todos', 'incorporacion'], true) && $usuario->can('rh.incorporaciones.ver')) {
            $incorporaciones = $this->incorporaciones($usuario, $filtros);
            $items = array_merge($items, $incorporaciones->all());
        }

        $coleccion = collect($items)->sortByDesc('creado_en')->values();

        $porPagina = max(1, min(100, (int) ($filtros['per_page'] ?? 15)));
        $pagina = max(1, (int) ($filtros['page'] ?? 1));

        return (new LengthAwarePaginator(
            $coleccion->forPage($pagina, $porPagina)->values(),
            $coleccion->count(),
            $porPagina,
            $pagina,
        ))->setPath('');
    }

    /**
     * @return array{solicitudes: int, vacaciones: int, documentos: int, incorporaciones: int, total: int}
     */
    public function resumenConteos(User $usuario): array
    {
        $vacaciones = 0;
        $solicitudes = 0;

        if (Gate::forUser($usuario)->allows('viewAny', SolicitudInterna::class)) {
            // Conteo directo en SQL (sin materializar), mismo alcance de módulo
            // que la bandeja y /rh/solicitudes. Las vacaciones son un subtipo
            // de solicitud, así que la baldosa «vacaciones» es un subconjunto
            // de las solicitudes abiertas — nunca se cuentan dos veces en el
            // total (P1-11: antes leía la tabla legacy y daba 0).
            $base = $this->solicitudes->limitarRevisionPorAlcance($this->queryBasePendientes(), $usuario);

            $vacaciones = (clone $base)->where('tipo', TipoSolicitudInterna::Vacaciones->value)->count();
            $solicitudes = $base->count() - $vacaciones;
        }

        $documentos = $usuario->can('rh.documentos.ver') ? $this->documentos($usuario, [])->count() : 0;
        $incorporaciones = $usuario->can('rh.incorporaciones.ver') ? $this->incorporaciones($usuario, [])->count() : 0;

        return [
            'solicitudes' => $solicitudes,
            'vacaciones' => $vacaciones,
            'documentos' => $documentos,
            'incorporaciones' => $incorporaciones,
            'total' => $solicitudes + $vacaciones + $documentos + $incorporaciones,
        ];
    }

    /**
     * Base común de «pendientes de revisión» del motor unificado: estados
     * abiertos (Recibida / Pendiente de autorizar), sin la baja de colaborador
     * (vive en el Cierre laboral, P1-9). El alcance de módulo se aplica aparte
     * con SolicitudesService::limitarRevisionPorAlcance().
     *
     * @return Builder<SolicitudInterna>
     */
    private function queryBasePendientes(): Builder
    {
        return SolicitudInterna::query()
            ->whereIn('estado', [EstadoSolicitudInterna::Enviada->value, EstadoSolicitudInterna::EnRevision->value])
            ->where('tipo', '!=', TipoSolicitudInterna::BajaColaborador->value);
    }

    /**
     * Solicitudes internas abiertas dentro del alcance de revisión del usuario.
     * Las vacaciones son un `tipo` de solicitud, no una tabla aparte: la app
     * las abre y aprueba por el MISMO flujo unificado (`/rh/solicitudes/*`),
     * por eso el item siempre viaja como `tipo: 'solicitud'` (titulado según su
     * tipo real, p. ej. «Vacaciones»). `$soloVacaciones`/`$sinVacaciones`
     * recortan la sub-pestaña que pidió la app sin cambiar ese contrato.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function solicitudes(User $usuario, array $filtros, bool $soloVacaciones = false, bool $sinVacaciones = false): Collection
    {
        $query = $this->queryBasePendientes()
            ->with([
                'colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id',
                'colaborador.sucursalPrincipal:id,nombre',
                'colaborador.puesto:id,nombre',
                'usuario.colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id',
                'usuario.colaborador.sucursalPrincipal:id,nombre',
                'usuario.colaborador.puesto:id,nombre',
            ]);

        if ($soloVacaciones) {
            $query->where('tipo', TipoSolicitudInterna::Vacaciones->value);
        }

        if ($sinVacaciones) {
            $query->where('tipo', '!=', TipoSolicitudInterna::Vacaciones->value);
        }

        $query = $this->solicitudes->limitarRevisionPorAlcance($query, $usuario);
        $query = $this->aplicarFiltrosComunes($query, $filtros, 'usuario');

        if ($busqueda = $filtros['q'] ?? null) {
            // Folio, motivo o el colaborador (buscador único, aditivo para la app).
            $buscador = app(BuscadorColaboradoresService::class);
            $query->where(function (Builder $q) use ($busqueda, $buscador): void {
                $q->where('folio', 'like', "%{$busqueda}%")->orWhere('motivo', 'like', "%{$busqueda}%");
                $buscador->aplicarPorColumna($q, (string) $busqueda, 'colaborador_id', 'or');
                $buscador->aplicarPorUsuario($q, (string) $busqueda, 'user_id', 'or');
            });
        }

        return $query->orderByDesc('created_at')->limit(self::LIMITE_POR_TIPO)->get()
            ->map(fn (SolicitudInterna $s) => $this->item([
                'id' => "solicitud:{$s->id}",
                'tipo' => 'solicitud',
                'resource_id' => $s->id,
                'prioridad' => 'normal',
                'titulo' => $s->tipo->etiqueta(),
                'colaborador' => $this->colaboradorResumen($s->personaSolicitante()),
                'resumen' => Str::limit((string) $s->motivo, 120),
                'creado_en' => $s->created_at?->toIso8601String(),
                'acciones_permitidas' => $this->accionesRapidasSolicitud($usuario),
            ]));
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function documentos(User $usuario, array $filtros): Collection
    {
        $query = EmployeeDocument::query()
            ->whereIn('status', EstadoDocumento::porRevisarValues())
            ->with(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id', 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.puesto:id,nombre', 'tipo:id,nombre']);

        $query = $this->limitarPorAlcanceViaColaborador($query, $usuario, 'colaborador_id');
        $query = $this->aplicarFiltrosComunes($query, $filtros, 'colaborador');

        return $query->orderByDesc('created_at')->limit(self::LIMITE_POR_TIPO)->get()
            ->map(fn (EmployeeDocument $d) => $this->item([
                'id' => "documento:{$d->id}",
                'tipo' => 'documento',
                'resource_id' => $d->id,
                'prioridad' => 'normal',
                'titulo' => $d->tipo->nombre ?? 'Documento',
                'colaborador' => $this->colaboradorResumen($d->colaborador),
                'resumen' => 'Documento por revisar (v'.$d->version.')',
                'creado_en' => $d->created_at?->toIso8601String(),
                'acciones_permitidas' => $this->accionesRapidasDocumento($usuario),
            ]));
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function incorporaciones(User $usuario, array $filtros): Collection
    {
        $query = Colaborador::query()
            ->where('estatus', EstadoUsuario::EnIncorporacion->value)
            ->whereNull('incorporacion_decision')
            ->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre']);

        $query = $this->alcance->limitarColaboradoresPorAlcance($query, $usuario);
        $query = $this->aplicarFiltrosComunes($query, $filtros, null);

        $candidatos = $query->orderByDesc('created_at')->limit(self::LIMITE_POR_TIPO)->get();

        return $candidatos
            ->filter(fn (Colaborador $colaborador) => $this->incorporacion->estado($colaborador) === 'completo')
            ->map(fn (Colaborador $colaborador) => $this->item([
                'id' => "incorporacion:{$colaborador->id}",
                'tipo' => 'incorporacion',
                'resource_id' => $colaborador->id,
                'prioridad' => 'normal',
                'titulo' => 'Incorporación lista para revisión final',
                'colaborador' => $this->colaboradorResumen($colaborador),
                'resumen' => 'Todos los documentos obligatorios están aprobados.',
                'creado_en' => $colaborador->created_at?->toIso8601String(),
                'acciones_permitidas' => $this->accionesRapidasIncorporacion($usuario),
            ]))
            ->values();
    }

    /**
     * Illuminate\Support\Collection no es covariante en su tipo de valor
     * (ver https://phpstan.org/blog/whats-up-with-template-covariant), asi
     * que un array con forma literal (los ->map() de arriba) no se acepta
     * donde se declaro `Collection<int, array<string, mixed>>` aunque sea
     * estructuralmente compatible. Este metodo ensancha el tipo en la
     * frontera de la funcion, que si es una operacion valida para PHPStan.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function item(array $item): array
    {
        return $item;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function limitarPorAlcanceViaColaborador($query, User $usuario, string $columnaColaboradorId)
    {
        if ($this->alcance->tieneAlcanceGlobal($usuario)) {
            return $query;
        }

        $idsVisibles = $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)->pluck('id');

        return $query->whereIn($columnaColaboradorId, $idsVisibles);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filtros
     * @param  string|null  $relacionUsuario  Nombre de la relacion hacia User, o null si el propio modelo es User.
     * @return Builder<TModel>
     */
    private function aplicarFiltrosComunes($query, array $filtros, ?string $relacionUsuario)
    {
        $sucursalId = $filtros['sucursal_id'] ?? null;
        $departamentoId = $filtros['departamento_id'] ?? null;

        if ($sucursalId === null && $departamentoId === null) {
            return $query;
        }

        $aplicar = function ($q) use ($sucursalId, $departamentoId): void {
            if ($sucursalId !== null) {
                $q->where('sucursal_principal_id', $sucursalId);
            }
            if ($departamentoId !== null) {
                $q->where('departamento_id', $departamentoId);
            }
        };

        return $relacionUsuario === null
            ? $query->where($aplicar)
            : $query->whereHas($relacionUsuario, $aplicar);
    }

    /**
     * @return array<string, mixed>
     */
    private function colaboradorResumen(?Colaborador $colaborador): array
    {
        if ($colaborador === null) {
            return ['id' => null, 'nombre' => null, 'numero_empleado' => null, 'puesto' => null, 'sucursal' => null];
        }

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function accionesRapidasSolicitud(User $usuario): array
    {
        return array_values(array_filter([
            'ver',
            $usuario->can('rh.solicitudes.aprobar') ? 'aprobar' : null,
            $usuario->can('rh.solicitudes.rechazar') ? 'rechazar' : null,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function accionesRapidasDocumento(User $usuario): array
    {
        return array_values(array_filter([
            'ver',
            $usuario->can('rh.documentos.aprobar') ? 'aprobar' : null,
            $usuario->can('rh.documentos.rechazar') ? 'rechazar' : null,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function accionesRapidasIncorporacion(User $usuario): array
    {
        return array_values(array_filter([
            'ver',
            $usuario->can('rh.incorporaciones.aprobar') ? 'aprobar' : null,
            $usuario->can('rh.incorporaciones.rechazar') ? 'rechazar' : null,
        ]));
    }
}
