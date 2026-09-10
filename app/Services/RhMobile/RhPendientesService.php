<?php

namespace App\Services\RhMobile;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoSolicitudVacaciones;
use App\Models\EmployeeDocument;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Incorporacion\IncorporacionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
    ) {}

    /**
     * @param  array<string, mixed>  $filtros  tipo, q, sucursal_id, departamento_id, page, per_page
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function bandeja(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $tipo = $filtros['tipo'] ?? 'todos';
        $items = collect();
        $conteos = ['solicitudes' => 0, 'vacaciones' => 0, 'documentos' => 0, 'incorporaciones' => 0];

        if (in_array($tipo, ['todos', 'solicitud'], true) && $usuario->can('rh.solicitudes.ver')) {
            $solicitudes = $this->solicitudes($usuario, $filtros);
            $conteos['solicitudes'] = $solicitudes->count();
            $items = $items->merge($solicitudes);
        }

        if (in_array($tipo, ['todos', 'vacaciones'], true) && $usuario->can('rh.vacaciones.ver')) {
            $vacaciones = $this->vacaciones($usuario, $filtros);
            $conteos['vacaciones'] = $vacaciones->count();
            $items = $items->merge($vacaciones);
        }

        if (in_array($tipo, ['todos', 'documento'], true) && $usuario->can('rh.documentos.ver')) {
            $documentos = $this->documentos($usuario, $filtros);
            $conteos['documentos'] = $documentos->count();
            $items = $items->merge($documentos);
        }

        if (in_array($tipo, ['todos', 'incorporacion'], true) && $usuario->can('rh.incorporaciones.ver')) {
            $incorporaciones = $this->incorporaciones($usuario, $filtros);
            $conteos['incorporaciones'] = $incorporaciones->count();
            $items = $items->merge($incorporaciones);
        }

        $items = $items->sortByDesc('creado_en')->values();

        $porPagina = max(1, min(100, (int) ($filtros['per_page'] ?? 15)));
        $pagina = max(1, (int) ($filtros['page'] ?? 1));

        $paginador = new LengthAwarePaginator(
            $items->forPage($pagina, $porPagina)->values(),
            $items->count(),
            $porPagina,
            $pagina,
        );

        return $paginador->through(fn (array $item) => $item)->setPath('');
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{solicitudes: int, vacaciones: int, documentos: int, incorporaciones: int, total: int}
     */
    public function resumenConteos(User $usuario): array
    {
        $solicitudes = $usuario->can('rh.solicitudes.ver') ? $this->solicitudes($usuario, [])->count() : 0;
        $vacaciones = $usuario->can('rh.vacaciones.ver') ? $this->vacaciones($usuario, [])->count() : 0;
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
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function solicitudes(User $usuario, array $filtros): Collection
    {
        $query = SolicitudInterna::query()
            ->whereIn('estado', [EstadoSolicitudInterna::Enviada->value, EstadoSolicitudInterna::EnRevision->value])
            ->with(['usuario:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id', 'usuario.sucursalPrincipal:id,nombre', 'usuario.puesto:id,nombre']);

        $query = $this->limitarPorAlcanceViaUsuario($query, $usuario, 'user_id');
        $query = $this->aplicarFiltrosComunes($query, $filtros, 'usuario');

        if ($busqueda = $filtros['q'] ?? null) {
            $query->where(fn ($q) => $q->where('folio', 'like', "%{$busqueda}%")->orWhere('motivo', 'like', "%{$busqueda}%"));
        }

        return $query->orderByDesc('created_at')->limit(self::LIMITE_POR_TIPO)->get()
            ->map(fn (SolicitudInterna $s) => [
                'id' => "solicitud:{$s->id}",
                'tipo' => 'solicitud',
                'resource_id' => $s->id,
                'prioridad' => 'normal',
                'titulo' => $s->tipo->etiqueta(),
                'colaborador' => $this->colaboradorResumen($s->usuario),
                'resumen' => $s->motivo !== null ? \Illuminate\Support\Str::limit($s->motivo, 120) : null,
                'creado_en' => $s->created_at?->toIso8601String(),
                'acciones_permitidas' => $this->accionesRapidasSolicitud($usuario),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function vacaciones(User $usuario, array $filtros): Collection
    {
        $query = SolicitudVacaciones::query()
            ->where('estado', EstadoSolicitudVacaciones::Pendiente->value)
            ->with(['usuario:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id', 'usuario.sucursalPrincipal:id,nombre', 'usuario.puesto:id,nombre']);

        $query = $this->limitarPorAlcanceViaUsuario($query, $usuario, 'user_id');
        $query = $this->aplicarFiltrosComunes($query, $filtros, 'usuario');

        return $query->orderByDesc('created_at')->limit(self::LIMITE_POR_TIPO)->get()
            ->map(fn (SolicitudVacaciones $v) => [
                'id' => "vacaciones:{$v->id}",
                'tipo' => 'vacaciones',
                'resource_id' => $v->id,
                'prioridad' => 'normal',
                'titulo' => 'Solicitud de vacaciones',
                'colaborador' => $this->colaboradorResumen($v->usuario),
                'resumen' => "Del {$v->fecha_inicio->toDateString()} al {$v->fecha_fin->toDateString()} ({$v->dias_solicitados} días)",
                'creado_en' => $v->created_at?->toIso8601String(),
                'acciones_permitidas' => $this->accionesRapidasVacacion($usuario),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function documentos(User $usuario, array $filtros): Collection
    {
        $query = EmployeeDocument::query()
            ->whereIn('status', [EstadoDocumento::Cargado->value, EstadoDocumento::EnRevision->value, EstadoDocumento::CambioSolicitado->value])
            ->with(['usuario:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id', 'usuario.sucursalPrincipal:id,nombre', 'usuario.puesto:id,nombre', 'tipo:id,nombre']);

        $query = $this->limitarPorAlcanceViaUsuario($query, $usuario, 'user_id');
        $query = $this->aplicarFiltrosComunes($query, $filtros, 'usuario');

        return $query->orderByDesc('created_at')->limit(self::LIMITE_POR_TIPO)->get()
            ->map(fn (EmployeeDocument $d) => [
                'id' => "documento:{$d->id}",
                'tipo' => 'documento',
                'resource_id' => $d->id,
                'prioridad' => 'normal',
                'titulo' => $d->tipo?->nombre ?? 'Documento',
                'colaborador' => $this->colaboradorResumen($d->usuario),
                'resumen' => 'Documento por revisar (v'.$d->version.')',
                'creado_en' => $d->created_at?->toIso8601String(),
                'acciones_permitidas' => $this->accionesRapidasDocumento($usuario),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function incorporaciones(User $usuario, array $filtros): Collection
    {
        $query = User::query()
            ->where('estatus', \App\Enums\EstadoUsuario::EnIncorporacion->value)
            ->whereNull('incorporacion_decision')
            ->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre']);

        $query = $this->alcance->limitarUsuariosPorAlcance($query, $usuario);
        $query = $this->aplicarFiltrosComunes($query, $filtros, null);

        $candidatos = $query->orderByDesc('created_at')->limit(self::LIMITE_POR_TIPO)->get();

        return $candidatos
            ->filter(fn (User $colaborador) => $this->incorporacion->estado($colaborador) === 'completo')
            ->map(fn (User $colaborador) => [
                'id' => "incorporacion:{$colaborador->id}",
                'tipo' => 'incorporacion',
                'resource_id' => $colaborador->id,
                'prioridad' => 'normal',
                'titulo' => 'Incorporación lista para revisión final',
                'colaborador' => $this->colaboradorResumen($colaborador),
                'resumen' => 'Todos los documentos obligatorios están aprobados.',
                'creado_en' => $colaborador->created_at?->toIso8601String(),
                'acciones_permitidas' => $this->accionesRapidasIncorporacion($usuario),
            ])
            ->values();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    private function limitarPorAlcanceViaUsuario($query, User $usuario, string $columnaUserId)
    {
        if ($this->alcance->tieneAlcanceGlobal($usuario)) {
            return $query;
        }

        $idsVisibles = $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuario)->pluck('id');

        return $query->whereIn($columnaUserId, $idsVisibles);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<string, mixed>  $filtros
     * @param  string|null  $relacionUsuario  Nombre de la relacion hacia User, o null si el propio modelo es User.
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
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
    private function colaboradorResumen(?User $colaborador): array
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
    private function accionesRapidasVacacion(User $usuario): array
    {
        return array_values(array_filter([
            'ver',
            $usuario->can('rh.vacaciones.aprobar') ? 'aprobar' : null,
            $usuario->can('rh.vacaciones.rechazar') ? 'rechazar' : null,
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
