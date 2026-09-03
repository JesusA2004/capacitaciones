<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unica fuente de logica de negocio de solicitudes internas. Los
 * controladores Inertia (App\Http\Controllers\Solicitudes,
 * App\Http\Controllers\Rh\SolicitudController) y los controladores API
 * (App\Http\Controllers\Api\V1\SolicitudController) llaman siempre a este
 * servicio, nunca calculan nada por su cuenta (ver seccion 2 del encargo:
 * "no duplicar logica").
 */
class SolicitudesService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly SolicitudDocumentoStorageService $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por StoreSolicitudInternaRequest: tipo, motivo, fecha_inicio?, fecha_fin?, observaciones?.
     */
    public function crear(User $solicitante, array $datos): SolicitudInterna
    {
        return DB::transaction(function () use ($solicitante, $datos): SolicitudInterna {
            $solicitud = SolicitudInterna::create([
                'folio' => $this->generarFolio(),
                'user_id' => $solicitante->id,
                'tipo' => $datos['tipo'],
                'estado' => EstadoSolicitudInterna::Enviada,
                'fecha_inicio' => $datos['fecha_inicio'] ?? null,
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'motivo' => $datos['motivo'],
                'observaciones' => $datos['observaciones'] ?? null,
                'empresa_id' => $solicitante->empresa()?->id,
                'sucursal_id' => $solicitante->sucursal_principal_id,
            ]);

            $this->registrarHistorial($solicitud, $solicitante, 'creada');
            $this->registrarHistorial($solicitud, $solicitante, 'enviada');

            return $solicitud;
        });
    }

    private function generarFolio(): string
    {
        $ultimoId = (int) (SolicitudInterna::query()->withTrashed()->max('id') ?? 0);

        return sprintf('SOL-%06d', $ultimoId + 1);
    }

    /**
     * @return LengthAwarePaginator<int, SolicitudInterna>
     */
    public function paraColaborador(User $colaborador): LengthAwarePaginator
    {
        return SolicitudInterna::query()
            ->where('user_id', $colaborador->id)
            ->with(['revisadoPor:id,name,apellidos'])
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    /**
     * Listado de revisión de RH/gerencia, acotado por alcance organizacional
     * y filtros opcionales.
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, SolicitudInterna>
     */
    public function paraRevision(User $revisor, array $filtros = []): LengthAwarePaginator
    {
        return $this->queryRevision($revisor, $filtros)
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * Mismo filtrado que paraRevision(), sin paginar: usado por las
     * exportaciones Excel/PDF para que respeten exactamente los filtros
     * activos en pantalla.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, SolicitudInterna>
     */
    public function paraExportar(User $revisor, array $filtros = []): Collection
    {
        return $this->queryRevision($revisor, $filtros)->orderByDesc('created_at')->get();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<SolicitudInterna>
     */
    private function queryRevision(User $revisor, array $filtros = []): Builder
    {
        $query = SolicitudInterna::query()
            ->with(['usuario:id,name,apellidos,sucursal_principal_id,empresa_id,departamento_id,puesto_id', 'usuario.departamento:id,nombre', 'usuario.puesto:id,nombre', 'revisadoPor:id,name,apellidos']);

        $query = $this->limitarPorAlcance($query, $revisor);

        return $query
            ->when($filtros['estado'] ?? null, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->when($filtros['tipo'] ?? null, fn (Builder $q, string $v) => $q->where('tipo', $v))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, string $v) => $q->where('sucursal_id', $v))
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, string $v) => $q->where('empresa_id', $v))
            ->when($filtros['departamento_id'] ?? null, fn (Builder $q, string $v) => $q->whereHas('usuario', fn (Builder $u) => $u->where('departamento_id', $v)))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, string $v) => $q->whereHas('usuario', fn (Builder $u) => $u->where('puesto_id', $v)))
            ->when($filtros['revisado_por'] ?? null, fn (Builder $q, string $v) => $q->where('revisado_por', $v))
            ->when($filtros['fecha_inicio'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['fecha_fin'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filtros['busqueda'] ?? null, function (Builder $q, string $busqueda): void {
                $q->where(function (Builder $sub) use ($busqueda): void {
                    $sub->where('folio', 'like', "%{$busqueda}%")
                        ->orWhere('motivo', 'like', "%{$busqueda}%");
                });
            });
    }

    /**
     * @param  Builder<SolicitudInterna>  $query
     * @return Builder<SolicitudInterna>
     */
    private function limitarPorAlcance(Builder $query, User $revisor): Builder
    {
        if ($this->alcance->tieneAlcanceGlobal($revisor)) {
            return $query;
        }

        if ($this->alcance->tieneAlcanceDeSucursal($revisor)) {
            return $query->whereIn('sucursal_id', $this->alcance->sucursalesVisiblesIds($revisor));
        }

        if ($revisor->hasRole('jefe_directo')) {
            return $query->whereHas('usuario', fn (Builder $q) => $q->where('jefe_id', $revisor->id));
        }

        return $query->where('user_id', $revisor->id);
    }

    public function marcarEnRevision(SolicitudInterna $solicitud, User $actor, ?string $comentario = null): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::EnRevision, $comentario);
    }

    public function aprobar(SolicitudInterna $solicitud, User $actor, ?string $comentario = null): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::Aprobada, $comentario);
    }

    public function rechazar(SolicitudInterna $solicitud, User $actor, string $motivoRechazo): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::Rechazada, $motivoRechazo, $motivoRechazo);
    }

    public function requerirCorreccion(SolicitudInterna $solicitud, User $actor, string $comentario): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::RequiereCorreccion, $comentario);
    }

    public function cerrar(SolicitudInterna $solicitud, User $actor, ?string $comentario = null): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::Cerrada, $comentario);
    }

    public function cancelar(SolicitudInterna $solicitud, User $actor): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::Cancelada);
    }

    private function cambiarEstado(SolicitudInterna $solicitud, User $actor, EstadoSolicitudInterna $nuevoEstado, ?string $comentario = null, ?string $motivoRechazo = null): SolicitudInterna
    {
        return DB::transaction(function () use ($solicitud, $actor, $nuevoEstado, $comentario, $motivoRechazo): SolicitudInterna {
            $datos = ['estado' => $nuevoEstado];

            if ($motivoRechazo !== null) {
                $datos['motivo_rechazo'] = $motivoRechazo;
            }

            if (in_array($nuevoEstado, [EstadoSolicitudInterna::Aprobada, EstadoSolicitudInterna::Rechazada, EstadoSolicitudInterna::Cerrada], true)) {
                $datos['revisado_por'] = $actor->id;
                $datos['revisado_en'] = now();
            }

            $solicitud->update($datos);

            $this->registrarHistorial($solicitud, $actor, $nuevoEstado->value, $comentario);

            return $solicitud->refresh();
        });
    }

    public function registrarHistorial(SolicitudInterna $solicitud, ?User $actor, string $accion, ?string $comentario = null): void
    {
        $solicitud->historial()->create([
            'user_id' => $actor?->id,
            'accion' => $accion,
            'comentario' => $comentario,
            'created_at' => now(),
        ]);
    }

    public function adjuntarDocumento(SolicitudInterna $solicitud, UploadedFile $archivo, User $actor): void
    {
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->storage->rutaDocumento($solicitud->id, $nombreInterno);
        $this->storage->guardar($archivo, $ruta);

        $solicitud->documentos()->create([
            'disk' => config('expedientes.disk'),
            'path' => $ruta,
            'original_name' => $archivo->getClientOriginalName(),
            'stored_name' => $nombreInterno,
            'mime' => $archivo->getClientMimeType(),
            'size' => $archivo->getSize() ?: null,
            'subido_por' => $actor->id,
        ]);

        $this->registrarHistorial($solicitud, $actor, 'comentario', "Documento adjuntado: {$archivo->getClientOriginalName()}");
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function tiposDisponibles(): array
    {
        return array_map(
            fn (TipoSolicitudInterna $tipo) => ['value' => $tipo->value, 'label' => $tipo->etiqueta()],
            TipoSolicitudInterna::cases(),
        );
    }
}
