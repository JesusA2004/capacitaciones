<?php

namespace App\Services\Sistemas;

use App\Enums\EstadoAvisoAltaSistemas;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Enums\OrigenAvisoAltaSistemas;
use App\Models\AvisoAltaSistemas;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Notificaciones\AvisoAltaSistemasService;
use App\Support\FechaTexto;
use App\Support\Paginacion;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bandeja «Pendientes de alta en sistemas» (docs/SISTEMAS_PENDIENTES_ALTA.md):
 * cada persona que entró a contratación queda pendiente hasta que Sistemas
 * marca que ya le dio sus usuarios en las demás plataformas. El correo es
 * un aviso; la bandeja es el control. Misma regla para web y (si se agrega)
 * la API móvil.
 *
 * - Ver: sistemas.altas.ver (Sistemas, RH, super_admin), acotado por
 *   AlcanceOrganizacionalService.
 * - Atender / reabrir: sistemas.altas.atender (Sistemas, super_admin).
 *
 * @phpstan-type FilaPendiente array<string, mixed>
 */
class PendientesAltaSistemasService
{
    public const FILTROS = ['pendientes', 'atendidos', 'todos'];

    /** Ventana por defecto del comando de regularización (días hacia atrás). */
    public const DIAS_REGULARIZACION = 60;

    public function __construct(
        private readonly AvisoAltaSistemasService $avisos,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
        private readonly FotoColaboradorService $fotos,
    ) {}

    /**
     * @param  array{filtro?: string|null, buscar?: string|null}  $filtros
     * @return LengthAwarePaginator<int, FilaPendiente>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $this->exigirVer($usuario);

        $filtro = in_array($filtros['filtro'] ?? null, self::FILTROS, true) ? (string) $filtros['filtro'] : 'pendientes';
        $buscar = trim((string) ($filtros['buscar'] ?? ''));

        $query = $this->consulta($usuario)
            ->with([
                'colaborador.puesto:id,nombre', 'colaborador.departamento:id,nombre',
                'colaborador.sucursalPrincipal.empresa', 'colaborador.jefe.puesto:id,nombre',
                'colaborador.user', 'colaborador.candidatoOrigen', 'atendidoPor:id,name',
            ])
            ->when($filtro === 'pendientes', fn (Builder $q) => $q->whereNull('atendido_en'))
            ->when($filtro === 'atendidos', fn (Builder $q) => $q->whereNotNull('atendido_en'))
            // Cada palabra debe aparecer en el nombre, los apellidos o el
            // número de empleado («ana lópez» encuentra a Ana López Prueba).
            ->when($buscar !== '', fn (Builder $q) => $q->whereHas('colaborador', function (Builder $c) use ($buscar): void {
                foreach (preg_split('/\s+/', $buscar) ?: [] as $palabra) {
                    $like = sprintf('%%%s%%', $palabra);
                    $c->where(fn (Builder $w) => $w->where('name', 'like', $like)
                        ->orWhere('apellidos', 'like', $like)
                        ->orWhere('numero_empleado', 'like', $like));
                }
            }));

        // Pendientes: lo más antiguo primero (lo que más urge). Atendidos: lo último.
        $query = $filtro === 'pendientes'
            ? $query->orderBy('created_at')->orderBy('id')
            : $query->orderByDesc('atendido_en')->orderByDesc('id');

        return $query
            ->paginate(Paginacion::porPagina())
            ->withQueryString()
            ->through(fn (AvisoAltaSistemas $aviso): array => $this->fila($aviso, $usuario));
    }

    /**
     * Detalle de un aviso (a donde lleva «Abrir y copiar datos» del correo).
     * Solo dentro del alcance de quien consulta.
     *
     * @return FilaPendiente
     */
    public function detalle(AvisoAltaSistemas $aviso, User $usuario): array
    {
        $this->exigirVer($usuario);

        if (! $this->consulta($usuario)->whereKey($aviso->id)->exists()) {
            throw new AuthorizationException('Esta persona está fuera de tu alcance.');
        }

        $aviso->loadMissing([
            'colaborador.puesto:id,nombre', 'colaborador.departamento:id,nombre',
            'colaborador.sucursalPrincipal.empresa', 'colaborador.jefe.puesto:id,nombre',
            'colaborador.user', 'colaborador.candidatoOrigen', 'atendidoPor:id,name',
        ]);

        return $this->fila($aviso, $usuario);
    }

    /**
     * @return array{pendientes: int, atendidos: int, atrasados: int}
     */
    public function totales(User $usuario): array
    {
        $this->exigirVer($usuario);

        return [
            'pendientes' => $this->consulta($usuario)->whereNull('atendido_en')->count(),
            'atendidos' => $this->consulta($usuario)->whereNotNull('atendido_en')->count(),
            'atrasados' => $this->consulta($usuario)->whereNull('atendido_en')->where('created_at', '<=', now()->subDays(3))->count(),
        ];
    }

    /**
     * Contador del menú: solo para quien ATIENDE (Sistemas). Nunca lanza.
     */
    public function contarPendientes(User $usuario): int
    {
        try {
            if (! $usuario->can(AvisoAltaSistemasService::PERMISO_ATENDER)) {
                return 0;
            }

            return $this->consulta($usuario)->whereNull('atendido_en')->count();
        } catch (Throwable $e) {
            Log::warning('PendientesAltaSistemasService: no se pudo contar los pendientes.', ['user_id' => $usuario->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    public function marcarAtendido(AvisoAltaSistemas $aviso, User $actor, ?string $notas = null): AvisoAltaSistemas
    {
        $this->exigirAtender($actor, $aviso);

        $notas = $notas !== null && trim($notas) !== '' ? trim($notas) : null;

        DB::transaction(function () use ($aviso, $actor, $notas): void {
            $bloqueado = AvisoAltaSistemas::query()->where('id', $aviso->id)->lockForUpdate()->first();

            if ($bloqueado === null) {
                throw ValidationException::withMessages(['aviso' => 'Este pendiente ya no existe.']);
            }

            if ($bloqueado->atendido_en !== null) {
                throw ValidationException::withMessages(['aviso' => 'Este pendiente ya estaba marcado como dado de alta.']);
            }

            $bloqueado->update(['atendido_en' => now(), 'atendido_por' => $actor->id, 'notas_atencion' => $notas]);
        });

        $this->auditoria->registrar('sistemas_alta_atendida', $aviso->colaborador, $actor, [
            'aviso_id' => $aviso->id,
            'colaborador_id' => $aviso->colaborador_id,
            'con_notas' => $notas !== null,
        ]);

        return $aviso->refresh();
    }

    /** Deshacer «Marcar como dado de alta» (se marcó por error). */
    public function reabrir(AvisoAltaSistemas $aviso, User $actor): AvisoAltaSistemas
    {
        $this->exigirAtender($actor, $aviso);

        if ($aviso->atendido_en === null) {
            throw ValidationException::withMessages(['aviso' => 'Este pendiente sigue abierto.']);
        }

        $antes = $aviso->atendido_en->toIso8601String();
        $aviso->update(['atendido_en' => null, 'atendido_por' => null, 'notas_atencion' => null]);

        $this->auditoria->registrar('sistemas_alta_reabierta', $aviso->colaborador, $actor, [
            'aviso_id' => $aviso->id,
            'colaborador_id' => $aviso->colaborador_id,
            'atendido_antes_en' => $antes,
        ]);

        return $aviso->refresh();
    }

    /**
     * Regularización (comando sistemas:avisos-alta-pendientes): crea el
     * pendiente de quien llegó a contratación o más adelante SIN aviso —
     * candidatos en contratación/contratados y colaboradores activos que
     * ingresaron en los últimos $dias. Idempotente (clave única por origen).
     * Sin $crear solo reporta; el correo sale únicamente con $enviarCorreo.
     *
     * @return list<array{colaborador_id: int, nombre: string, origen: string, fecha_ingreso: string|null}>
     */
    public function regularizar(int $dias, bool $crear, bool $enviarCorreo): array
    {
        $desde = now()->subDays(max(1, $dias))->startOfDay();
        $faltantes = [];

        // 1) Candidatos que ya entraron a contratación (o ya están contratados).
        Candidato::query()
            ->whereNotNull('colaborador_id')
            // La plantilla histórica ya laboraba: nunca genera aviso de alta.
            ->where('historico', false)
            ->whereIn('estado', [EstadoCandidato::EnContratacion->value, EstadoCandidato::Contratado->value])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('avisos_alta_sistemas')
                ->where('origen_tipo', OrigenAvisoAltaSistemas::Candidato->value)
                ->whereColumn('origen_id', 'candidatos.id'))
            ->orderBy('id')
            ->each(function (Candidato $candidato) use ($desde, &$faltantes): void {
                $colaborador = Colaborador::query()->where('id', $candidato->colaborador_id)->first();

                if ($colaborador === null || ! $this->vigente($colaborador, $desde) || $this->tieneAviso($colaborador)) {
                    return;
                }

                $faltantes[$colaborador->id] = ['colaborador' => $colaborador, 'origen' => OrigenAvisoAltaSistemas::Candidato, 'origen_id' => $candidato->id];
            });

        // 2) Colaboradores activos de ingreso reciente sin ningún aviso
        //    (alta digital, alta directa, reingresos previos al aviso).
        Colaborador::query()
            ->whereIn('estatus', [EstadoUsuario::Activo->value, EstadoUsuario::EnIncorporacion->value])
            ->where(fn (Builder $q) => $q->where('fecha_ingreso', '>=', $desde->toDateString())
                ->orWhere(fn (Builder $w) => $w->whereNull('fecha_ingreso')->where('created_at', '>=', $desde)))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('avisos_alta_sistemas')->whereColumn('avisos_alta_sistemas.colaborador_id', 'colaboradores.id'))
            ->orderBy('id')
            ->each(function (Colaborador $colaborador) use (&$faltantes): void {
                $faltantes[$colaborador->id] ??= ['colaborador' => $colaborador, 'origen' => OrigenAvisoAltaSistemas::Colaborador, 'origen_id' => $colaborador->id];
            });

        $resultado = [];

        foreach ($faltantes as $faltante) {
            $colaborador = $faltante['colaborador'];

            if ($crear) {
                // Sin aviso en la campana por cada fila: una regularización
                // masiva no debe inundar a Sistemas; la bandeja ya los cuenta.
                $this->avisos->programar($colaborador, $faltante['origen'], $faltante['origen_id'], null, $enviarCorreo, false);
            }

            $resultado[] = [
                'colaborador_id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'origen' => $faltante['origen']->etiqueta(),
                'fecha_ingreso' => $colaborador->fecha_ingreso?->format('d/m/Y'),
            ];
        }

        return $resultado;
    }

    /**
     * Texto plano para pegar en otro sistema (botón «Copiar datos»).
     *
     * @param  array<string, array{titulo: string, datos: array<string, string>}>  $datos
     */
    public function textoParaCopiar(array $datos): string
    {
        $lineas = [];

        foreach (AvisoAltaSistemasService::aplanar($datos) as $etiqueta => $valor) {
            $lineas[] = sprintf('%s: %s', $etiqueta, $valor);
        }

        return implode("\n", $lineas);
    }

    /**
     * @return Builder<AvisoAltaSistemas>
     */
    private function consulta(User $usuario): Builder
    {
        $query = AvisoAltaSistemas::query();

        if ($this->alcance->tieneAlcanceGlobal($usuario)) {
            return $query;
        }

        return $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::withTrashed()->select('id'), $usuario));
    }

    /**
     * @return FilaPendiente
     */
    private function fila(AvisoAltaSistemas $aviso, User $usuario): array
    {
        $colaborador = $aviso->colaborador;
        $datos = $colaborador !== null ? $this->avisos->datosBasicos($colaborador) : [];
        $secciones = [];

        foreach ($datos as $clave => $seccion) {
            $secciones[] = ['clave' => $clave, 'titulo' => $seccion['titulo'], 'datos' => $this->comoLista($seccion['datos'])];
        }
        $inicio = $aviso->created_at ?? now();
        $fin = $aviso->atendido_en ?? now();
        $dias = (int) floor($inicio->diffInDays($fin, true));
        $puedeAtender = $usuario->can('atender', $aviso);

        return [
            'id' => $aviso->id,
            'colaborador' => [
                'id' => $aviso->colaborador_id,
                'nombre' => $colaborador?->nombreCompleto() ?? 'Colaborador eliminado',
                // Foto oficial (URL protegida, nunca foto_path) solo si quien
                // mira alcanza ese expediente; si no, la vista pone iniciales.
                'foto_url' => $colaborador !== null ? $this->fotos->urlPara($colaborador, $usuario) : null,
                'numero_empleado' => $colaborador?->numero_empleado,
                'puesto' => $colaborador?->puesto?->nombre,
                'sucursal' => $colaborador?->sucursalPrincipal?->nombre,
                'departamento' => $colaborador?->departamento?->nombre,
                'fecha_ingreso' => FechaTexto::doble($colaborador?->fecha_ingreso),
                'url_expediente' => $colaborador !== null && $this->alcance->puedeVerExpediente($usuario, $colaborador)
                    ? route('rh.expedientes.show', $colaborador->id, false)
                    : null,
            ],
            'origen' => $aviso->origen_tipo->etiqueta(),
            'correo' => [
                'estado' => $aviso->estado->value,
                'etiqueta' => $aviso->estado->etiqueta(),
                'enviado_en' => FechaTexto::doble($aviso->enviado_en),
                'error' => $aviso->estado === EstadoAvisoAltaSistemas::Fallido ? 'No se pudo enviar el correo.' : null,
            ],
            'creado_en' => FechaTexto::doble($aviso->created_at),
            'dias' => $dias,
            'atendido' => $aviso->atendido_en !== null ? [
                'en' => FechaTexto::doble($aviso->atendido_en),
                'por' => $aviso->atendidoPor->name ?? 'Usuario eliminado',
                'notas' => $aviso->notas_atencion,
            ] : null,
            'tipo_ingreso' => $aviso->origen_tipo === OrigenAvisoAltaSistemas::Reingreso ? 'Reingreso' : 'Nuevo ingreso',
            'secciones' => $secciones,
            'texto_copiar' => $this->textoParaCopiar($datos),
            'url_detalle' => route('sistemas.pendientes-alta.show', $aviso->id, false),
            'puede_atender' => $puedeAtender,
            'puede_reenviar' => $colaborador !== null && $this->avisos->activo() && $this->avisos->puedeReenviar($usuario, $colaborador),
        ];
    }

    /**
     * @param  array<string, string>  $datos
     * @return list<array{etiqueta: string, valor: string, falta: bool}>
     */
    private function comoLista(array $datos): array
    {
        $lista = [];

        foreach ($datos as $etiqueta => $valor) {
            $lista[] = ['etiqueta' => $etiqueta, 'valor' => $valor, 'falta' => $valor === AvisoAltaSistemasService::SIN_CAPTURAR];
        }

        return $lista;
    }

    private function vigente(Colaborador $colaborador, CarbonInterface $desde): bool
    {
        if (! in_array($colaborador->estatus, [EstadoUsuario::Activo, EstadoUsuario::EnIncorporacion], true)) {
            return false;
        }

        $referencia = $colaborador->fecha_ingreso ?? $colaborador->created_at;

        return $referencia === null || $referencia->greaterThanOrEqualTo($desde);
    }

    private function tieneAviso(Colaborador $colaborador): bool
    {
        return AvisoAltaSistemas::query()->where('colaborador_id', $colaborador->id)->exists();
    }

    private function exigirVer(User $usuario): void
    {
        if (! $usuario->can('viewAny', AvisoAltaSistemas::class)) {
            throw new AuthorizationException('No tienes acceso a los pendientes de alta en sistemas.');
        }
    }

    private function exigirAtender(User $usuario, AvisoAltaSistemas $aviso): void
    {
        if (! $usuario->can('atender', $aviso)) {
            throw new AuthorizationException('No tienes permiso para marcar altas en sistemas.');
        }
    }
}
