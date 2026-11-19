<?php

namespace App\Services\Sistemas;

use App\Enums\EstadoAvisoBajaSistemas;
use App\Enums\EventoAvisoBajaSistemas;
use App\Models\AvisoBajaSistemas;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Notificaciones\AvisoAltaSistemasService;
use App\Services\Notificaciones\AvisoBajaSistemasService;
use App\Support\FechaTexto;
use App\Support\Paginacion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bandeja «Pendientes de baja en sistemas»: cada correo de baja a Sistemas
 * (AvisoBajaSistemasService) queda pendiente hasta que Sistemas marca que ya
 * dio de baja (o reactivó, si la baja se canceló) los usuarios de la persona
 * en los demás sistemas. Mismos permisos que la bandeja de alta
 * (sistemas.altas.ver / sistemas.altas.atender), acotada por
 * AlcanceOrganizacionalService. Un aviso cancelado antes de atenderse ya no
 * cuenta como pendiente.
 *
 * @phpstan-type FilaPendienteBaja array<string, mixed>
 */
class PendientesBajaSistemasService
{
    public const FILTROS = ['pendientes', 'atendidos', 'todos'];

    public function __construct(
        private readonly AvisoBajaSistemasService $avisos,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
        private readonly FotoColaboradorService $fotos,
    ) {}

    /**
     * @param  array{filtro?: string|null, buscar?: string|null}  $filtros
     * @return LengthAwarePaginator<int, FilaPendienteBaja>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $this->exigirVer($usuario);

        $filtro = in_array($filtros['filtro'] ?? null, self::FILTROS, true) ? (string) $filtros['filtro'] : 'pendientes';
        $buscar = trim((string) ($filtros['buscar'] ?? ''));

        $query = $this->consulta($usuario)
            ->with(['colaborador.puesto:id,nombre', 'colaborador.departamento:id,nombre', 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.user', 'atendidoPor:id,name'])
            ->when($filtro === 'pendientes', fn (Builder $q) => $this->soloPendientes($q))
            ->when($filtro === 'atendidos', fn (Builder $q) => $q->whereNotNull('atendido_en'))
            ->when($buscar !== '', fn (Builder $q) => $q->whereHas('colaborador', function (Builder $c) use ($buscar): void {
                foreach (preg_split('/\s+/', $buscar) ?: [] as $palabra) {
                    $like = sprintf('%%%s%%', $palabra);
                    $c->where(fn (Builder $w) => $w->where('name', 'like', $like)
                        ->orWhere('apellidos', 'like', $like)
                        ->orWhere('numero_empleado', 'like', $like));
                }
            }));

        $query = $filtro === 'pendientes'
            ? $query->orderBy('created_at')->orderBy('id')
            : $query->orderByDesc('id');

        return $query
            ->paginate(Paginacion::porPagina())
            ->withQueryString()
            ->through(fn (AvisoBajaSistemas $aviso): array => $this->fila($aviso, $usuario));
    }

    /**
     * @return FilaPendienteBaja
     */
    public function detalle(AvisoBajaSistemas $aviso, User $usuario): array
    {
        $this->exigirVer($usuario);

        if (! $this->consulta($usuario)->whereKey($aviso->id)->exists()) {
            throw new AuthorizationException('Esta persona está fuera de tu alcance.');
        }

        $aviso->loadMissing(['colaborador.puesto:id,nombre', 'colaborador.departamento:id,nombre', 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.user', 'atendidoPor:id,name']);

        return $this->fila($aviso, $usuario);
    }

    /**
     * @return array{pendientes: int, atendidos: int}
     */
    public function totales(User $usuario): array
    {
        $this->exigirVer($usuario);

        return [
            'pendientes' => $this->soloPendientes($this->consulta($usuario))->count(),
            'atendidos' => $this->consulta($usuario)->whereNotNull('atendido_en')->count(),
        ];
    }

    public function marcarAtendido(AvisoBajaSistemas $aviso, User $actor, ?string $notas = null): AvisoBajaSistemas
    {
        $this->exigirAtender($actor, $aviso);

        $notas = $notas !== null && trim($notas) !== '' ? trim($notas) : null;

        DB::transaction(function () use ($aviso, $actor, $notas): void {
            $bloqueado = AvisoBajaSistemas::query()->where('id', $aviso->id)->lockForUpdate()->first();

            if ($bloqueado === null) {
                throw ValidationException::withMessages(['aviso' => 'Este pendiente ya no existe.']);
            }

            if ($bloqueado->atendido_en !== null) {
                throw ValidationException::withMessages(['aviso' => 'Este pendiente ya estaba marcado como atendido.']);
            }

            $bloqueado->update(['atendido_en' => now(), 'atendido_por' => $actor->id, 'notas_atencion' => $notas]);
        });

        $this->auditoria->registrar('sistemas_baja_atendida', $aviso->colaborador, $actor, [
            'aviso_id' => $aviso->id,
            'evento' => $aviso->evento->value,
            'colaborador_id' => $aviso->colaborador_id,
            'con_notas' => $notas !== null,
        ]);

        return $aviso->refresh();
    }

    /** Deshacer «Marcar como atendido» (se marcó por error). */
    public function reabrir(AvisoBajaSistemas $aviso, User $actor): AvisoBajaSistemas
    {
        $this->exigirAtender($actor, $aviso);

        if ($aviso->atendido_en === null) {
            throw ValidationException::withMessages(['aviso' => 'Este pendiente sigue abierto.']);
        }

        $antes = $aviso->atendido_en->toIso8601String();
        $aviso->update(['atendido_en' => null, 'atendido_por' => null, 'notas_atencion' => null]);

        $this->auditoria->registrar('sistemas_baja_reabierta', $aviso->colaborador, $actor, [
            'aviso_id' => $aviso->id,
            'colaborador_id' => $aviso->colaborador_id,
            'atendido_antes_en' => $antes,
        ]);

        return $aviso->refresh();
    }

    /**
     * Sin atender y vigente: un aviso de baja cancelada que nunca salió (o
     * que ya fue sustituido por el de «Baja cancelada») no es trabajo pendiente.
     *
     * @param  Builder<AvisoBajaSistemas>  $query
     * @return Builder<AvisoBajaSistemas>
     */
    private function soloPendientes(Builder $query): Builder
    {
        return $query->whereNull('atendido_en')->where('estado', '!=', EstadoAvisoBajaSistemas::Cancelado->value);
    }

    /**
     * @return Builder<AvisoBajaSistemas>
     */
    private function consulta(User $usuario): Builder
    {
        $query = AvisoBajaSistemas::query();

        if ($this->alcance->tieneAlcanceGlobal($usuario)) {
            return $query;
        }

        return $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::withTrashed()->select('id'), $usuario));
    }

    /**
     * @return FilaPendienteBaja
     */
    private function fila(AvisoBajaSistemas $aviso, User $usuario): array
    {
        $colaborador = $aviso->colaborador;
        $datos = $colaborador !== null ? $this->avisos->datos($aviso, $colaborador) : [];
        $secciones = [];
        $lineas = [];

        foreach ($datos as $clave => $seccion) {
            $lista = [];

            foreach ($seccion['datos'] as $etiqueta => $valor) {
                $lista[] = ['etiqueta' => $etiqueta, 'valor' => $valor, 'falta' => $valor === AvisoAltaSistemasService::SIN_CAPTURAR];
                $lineas[] = sprintf('%s: %s', $etiqueta, $valor);
            }

            $secciones[] = ['clave' => $clave, 'titulo' => $seccion['titulo'], 'datos' => $lista];
        }

        $inicio = $aviso->created_at ?? now();
        $fin = $aviso->atendido_en ?? now();

        return [
            'id' => $aviso->id,
            'evento' => $aviso->evento->value,
            'evento_etiqueta' => $aviso->evento->etiqueta(),
            'colaborador' => [
                'id' => $aviso->colaborador_id,
                'nombre' => $colaborador?->nombreCompleto() ?? 'Colaborador eliminado',
                'foto_url' => $colaborador !== null ? $this->fotos->urlPara($colaborador, $usuario) : null,
                'numero_empleado' => $colaborador?->numero_empleado,
                'puesto' => $colaborador?->puesto?->nombre,
                'sucursal' => $colaborador?->sucursalPrincipal?->nombre,
                'url_expediente' => $colaborador !== null && $this->alcance->puedeVerExpediente($usuario, $colaborador)
                    ? route('rh.expedientes.show', $colaborador->id, false)
                    : null,
            ],
            'origen' => $aviso->origen_tipo->etiqueta(),
            'fecha_baja' => $datos['baja']['datos']['Fecha efectiva de baja'] ?? null,
            'correo' => [
                'estado' => $aviso->estado->value,
                'etiqueta' => $aviso->estado->etiqueta(),
                'enviado_en' => FechaTexto::doble($aviso->enviado_en),
                'error' => $aviso->estado === EstadoAvisoBajaSistemas::Fallido ? 'No se pudo enviar el correo.' : null,
            ],
            'cancelado' => $aviso->estado === EstadoAvisoBajaSistemas::Cancelado,
            'creado_en' => FechaTexto::doble($aviso->created_at),
            'dias' => (int) floor($inicio->diffInDays($fin, true)),
            'atendido' => $aviso->atendido_en !== null ? [
                'en' => FechaTexto::doble($aviso->atendido_en),
                'por' => $aviso->atendidoPor->name ?? 'Usuario eliminado',
                'notas' => $aviso->notas_atencion,
            ] : null,
            'accion' => match ($aviso->evento) {
                EventoAvisoBajaSistemas::BajaCancelada => 'Marcar como reactivado',
                EventoAvisoBajaSistemas::BajaReprogramada => 'Marcar como enterado',
                default => 'Marcar como dado de baja',
            },
            'secciones' => $secciones,
            'texto_copiar' => implode("\n", $lineas),
            'url_detalle' => route('sistemas.pendientes-baja.show', $aviso->id, false),
            'puede_atender' => $usuario->can('atender', $aviso),
            'puede_reenviar' => $colaborador !== null
                && $aviso->estado !== EstadoAvisoBajaSistemas::Cancelado
                && $this->avisos->activo()
                && $this->avisos->puedeReenviar($usuario, $colaborador),
        ];
    }

    private function exigirVer(User $usuario): void
    {
        if (! $usuario->can('viewAny', AvisoBajaSistemas::class)) {
            throw new AuthorizationException('No tienes acceso a los pendientes de baja en sistemas.');
        }
    }

    private function exigirAtender(User $usuario, AvisoBajaSistemas $aviso): void
    {
        if (! $usuario->can('atender', $aviso)) {
            throw new AuthorizationException('No tienes permiso para marcar bajas en sistemas.');
        }
    }
}
