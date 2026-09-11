<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use App\Models\SolicitudInternaDocumento;
use App\Models\User;
use App\Notifications\Mobile\RhSolicitudCreadaNotification;
use App\Notifications\Mobile\SolicitudActualizadaNotification;
use App\Services\AlcanceOrganizacionalService;
use App\Services\MobilePush\PushNotifier;
use App\Services\RhMobile\ResponsableResolverService;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Unica fuente de logica de negocio de solicitudes internas. Los
 * controladores Inertia (App\Http\Controllers\Solicitudes,
 * App\Http\Controllers\Rh\SolicitudController) y los controladores API
 * (App\Http\Controllers\Api\V1\SolicitudController,
 * App\Http\Controllers\Api\V1\Rh\SolicitudController) llaman siempre a este
 * servicio, nunca calculan nada por su cuenta (ver seccion 2 del encargo:
 * "no duplicar logica").
 */
class SolicitudesService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly SolicitudDocumentoStorageService $storage,
        private readonly ResponsableResolverService $responsables,
        private readonly PushNotifier $push,
        private readonly VacacionesService $vacaciones,
        private readonly BajaColaboradorService $bajaColaborador,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por StoreSolicitudInternaRequest: tipo, motivo, fecha_inicio?, fecha_fin?, observaciones?, dias_solicitados? (vacaciones), monto_solicitado?/plazo_meses? (préstamo), colaborador_objetivo_id? (baja).
     *
     * @throws ValidationException Saldo de vacaciones insuficiente, o sin permiso para dar de baja al colaborador objetivo.
     */
    public function crear(User $solicitante, array $datos): SolicitudInterna
    {
        $tipo = TipoSolicitudInterna::from($datos['tipo']);

        if ($tipo === TipoSolicitudInterna::Vacaciones && isset($datos['dias_solicitados'])) {
            $saldo = $this->vacaciones->saldo($solicitante);

            if ((int) $datos['dias_solicitados'] > $saldo['dias_disponibles']) {
                throw ValidationException::withMessages([
                    'dias_solicitados' => 'No tienes suficientes días disponibles.',
                ]);
            }
        }

        $colaboradorObjetivo = null;

        if ($tipo === TipoSolicitudInterna::BajaColaborador) {
            $colaboradorObjetivo = User::query()->where('id', $datos['colaborador_objetivo_id'])->firstOrFail();

            if (Gate::forUser($solicitante)->denies('crearBaja', [SolicitudInterna::class, $colaboradorObjetivo])) {
                throw ValidationException::withMessages([
                    'colaborador_objetivo_id' => 'No tienes permiso para solicitar la baja de este colaborador.',
                ]);
            }
        }

        return DB::transaction(function () use ($solicitante, $datos, $colaboradorObjetivo): SolicitudInterna {
            $solicitud = SolicitudInterna::create([
                'folio' => $this->generarFolio(),
                'user_id' => $solicitante->id,
                'colaborador_objetivo_id' => $colaboradorObjetivo?->id,
                'fecha_efectiva' => $datos['fecha_efectiva'] ?? null,
                'tipo_baja' => $datos['tipo_baja'] ?? null,
                'tipo' => $datos['tipo'],
                'estado' => EstadoSolicitudInterna::Enviada,
                'fecha_inicio' => $datos['fecha_inicio'] ?? null,
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'dias_solicitados' => $datos['dias_solicitados'] ?? null,
                'monto_solicitado' => $datos['monto_solicitado'] ?? null,
                'plazo_meses' => $datos['plazo_meses'] ?? null,
                'motivo' => $datos['motivo'],
                'observaciones' => $datos['observaciones'] ?? null,
                'empresa_id' => $solicitante->empresa()?->id,
                'sucursal_id' => $solicitante->sucursal_principal_id,
            ]);

            $this->registrarHistorial($solicitud, $solicitante, 'creada');
            $this->registrarHistorial($solicitud, $solicitante, 'enviada');

            $this->notificarSinFallar(function () use ($solicitud, $solicitante): void {
                $responsables = $this->responsables->paraColaborador($solicitante, 'rh.solicitudes.aprobar');

                NotificationFacade::send($responsables, new RhSolicitudCreadaNotification($solicitud));
                $this->push->aUsuarios($responsables, 'rh_solicitud', $solicitud->id, 'Nueva solicitud por revisar', 'Un colaborador envió una solicitud.');
            });

            return $solicitud;
        });
    }

    /**
     * Un fallo al notificar (base de datos de notificaciones o encolar
     * push) nunca debe deshacer la accion principal, que ya quedo
     * persistida antes de llamar aqui: solo se registra en el log. Ver
     * seccion 4 y 16 del encargo movil.
     */
    private function notificarSinFallar(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('SolicitudesService: fallo al notificar', ['message' => $e->getMessage()]);
        }
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
     * Listado del tablero Kanban de RH (Rh/Solicitudes/Index.vue): mismo
     * alcance/filtros que paraRevision(), pero sin paginar (las columnas se
     * arman en el frontend por estado) y sin las solicitudes "creada"
     * (estado transitorio que nunca se persiste, ver crear()) ni
     * "cancelada" (el colaborador la retiró antes de que RH actuara; no
     * requieren ninguna acción del tablero). Acotado con un límite
     * defensivo: el tablero es para el trabajo del día, no un reporte
     * histórico (para eso están las exportaciones de paraExportar()).
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, SolicitudInterna>
     */
    public function paraTablero(User $revisor, array $filtros = []): Collection
    {
        return $this->queryRevision($revisor, $filtros)
            ->whereNotIn('estado', [EstadoSolicitudInterna::Creada->value, EstadoSolicitudInterna::Cancelada->value])
            ->orderBy('created_at')
            ->limit(500)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<SolicitudInterna>
     */
    private function queryRevision(User $revisor, array $filtros = []): Builder
    {
        $query = SolicitudInterna::query()
            // 'users' no tiene columna empresa_id propia (se deriva de la
            // sucursal, ver User::empresa()) — solo la propia SolicitudInterna
            // la tiene (snapshot al crear, ver crear() más arriba).
            ->with([
                'usuario:id,name,apellidos,sucursal_principal_id,departamento_id,puesto_id',
                'usuario.departamento:id,nombre',
                'usuario.puesto:id,nombre',
                'revisadoPor:id,name,apellidos',
                'sucursal:id,nombre',
                'documentosGenerados:id,solicitud_id,status',
            ])
            ->withCount('documentos');

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

    /**
     * Cambio de estado unificado usado por el tablero Kanban de RH (drag and
     * drop, ver Rh/Solicitudes/Index.vue): traduce el estado destino a la
     * misma accion publica que ya usan los botones del detalle, para nunca
     * duplicar la logica de cambiarEstado(). $comentario es obligatorio al
     * mover a "rechazada" o "requiere_correccion" (se pide en el dialog de
     * confirmacion del tablero antes de soltar la tarjeta).
     *
     * @throws ValidationException Estado destino no gestionable desde el tablero, o falta comentario/motivo.
     */
    public function moverEnTablero(SolicitudInterna $solicitud, User $actor, EstadoSolicitudInterna $nuevoEstado, ?string $comentario = null): SolicitudInterna
    {
        if (in_array($nuevoEstado, [EstadoSolicitudInterna::Rechazada, EstadoSolicitudInterna::RequiereCorreccion], true) && trim((string) $comentario) === '') {
            throw ValidationException::withMessages([
                'comentario' => 'Agrega un comentario para mover la solicitud a este estado.',
            ]);
        }

        return match ($nuevoEstado) {
            EstadoSolicitudInterna::EnRevision => $this->marcarEnRevision($solicitud, $actor, $comentario),
            EstadoSolicitudInterna::RequiereCorreccion => $this->requerirCorreccion($solicitud, $actor, (string) $comentario),
            EstadoSolicitudInterna::Aprobada => $this->aprobar($solicitud, $actor, $comentario),
            EstadoSolicitudInterna::Rechazada => $this->rechazar($solicitud, $actor, (string) $comentario),
            EstadoSolicitudInterna::Cerrada => $this->cerrar($solicitud, $actor, $comentario),
            default => throw ValidationException::withMessages([
                'estado' => 'Ese estado no se puede asignar desde el tablero.',
            ]),
        };
    }

    private function cambiarEstado(SolicitudInterna $solicitud, User $actor, EstadoSolicitudInterna $nuevoEstado, ?string $comentario = null, ?string $motivoRechazo = null): SolicitudInterna
    {
        // Nunca se aprueba una baja sin evidencia/firma del gerente
        // (formato firmado, carta o autorización adjunta): la validación va
        // antes de la transacción para no dejar nada a medio persistir.
        if ($nuevoEstado === EstadoSolicitudInterna::Aprobada
            && $solicitud->tipo === TipoSolicitudInterna::BajaColaborador
            && $solicitud->documentos()->count() === 0
        ) {
            throw ValidationException::withMessages([
                'evidencia' => 'Adjunta la evidencia/firma del gerente antes de aprobar esta baja.',
            ]);
        }

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

            $solicitud->refresh();

            // Al aprobar una baja de colaborador, se ejecuta el bloqueo de
            // acceso real (ver App\Services\Solicitudes\BajaColaboradorService):
            // nunca antes de la aprobación, y nunca en ningún otro estado.
            if ($nuevoEstado === EstadoSolicitudInterna::Aprobada && $solicitud->tipo === TipoSolicitudInterna::BajaColaborador) {
                $solicitud->loadMissing('colaboradorObjetivo');

                if ($solicitud->colaboradorObjetivo !== null) {
                    $this->bajaColaborador->ejecutar($solicitud->colaboradorObjetivo, $actor, $solicitud->motivo);
                }
            }

            // Notifica al colaborador en cada transicion visible del tablero
            // (no solo aprobada/rechazada): "en_revision" y "cerrada" tambien
            // son cambios que le interesan, aunque no requieran una accion de
            // su parte.
            if (in_array($nuevoEstado, [
                EstadoSolicitudInterna::EnRevision,
                EstadoSolicitudInterna::Aprobada,
                EstadoSolicitudInterna::Rechazada,
                EstadoSolicitudInterna::RequiereCorreccion,
                EstadoSolicitudInterna::Cerrada,
            ], true)) {
                $this->notificarSinFallar(function () use ($solicitud): void {
                    $solicitud->loadMissing('usuario');
                    NotificationFacade::send($solicitud->usuario, new SolicitudActualizadaNotification($solicitud));
                    $this->push->aUsuarioConDatos(
                        $solicitud->usuario,
                        'Actualización de tu solicitud',
                        "Tu solicitud ahora está: {$solicitud->estado->etiqueta()}.",
                        ['type' => 'solicitud', 'resource_id' => $solicitud->id, 'estado' => $solicitud->estado->value],
                    );
                });
            }

            return $solicitud;
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
     * Vista previa/descarga de un adjunto de la solicitud (evidencia de
     * baja, comprobante, etc.): siempre a través del disco privado, nunca
     * expone la ruta real. `inline` deja que el navegador previsualice
     * PDF/imágenes en vez de forzar la descarga.
     */
    public function documento(SolicitudInterna $solicitud, SolicitudInternaDocumento $documento): StreamedResponse
    {
        abort_unless($documento->solicitud_interna_id === $solicitud->id, 404);

        return $this->storage->respuesta($documento->path, [
            'Content-Type' => $documento->mime ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$documento->original_name.'"',
        ]);
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

    /**
     * Catalogo de tipos con las reglas de formulario que la app movil usa
     * para construir el formulario de "nueva solicitud" sin hardcodear
     * nada: que campos mostrar, si requiere rango de fechas, si admite
     * adjuntos. Ver seccion 14 del encargo movil.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tiposConFormulario(): array
    {
        return array_map(function (TipoSolicitudInterna $tipo): array {
            $requiereFechas = $tipo->usaRangoFechas();
            $requiereHorario = $tipo->usaHorario();

            $campos = [
                ['name' => 'motivo', 'type' => 'text', 'required' => true],
                ['name' => 'observaciones', 'type' => 'text', 'required' => false],
            ];

            if ($requiereFechas) {
                array_unshift(
                    $campos,
                    ['name' => 'fecha_inicio', 'type' => 'date', 'required' => true],
                    ['name' => 'fecha_fin', 'type' => 'date', 'required' => true],
                );
            } elseif ($requiereHorario) {
                array_unshift($campos, ['name' => 'fecha_inicio', 'type' => 'date', 'required' => true]);
            }

            if ($tipo->requiereDias()) {
                $campos[] = ['name' => 'dias_solicitados', 'type' => 'number', 'required' => true];
            }

            if ($tipo->requiereMonto()) {
                $campos[] = ['name' => 'monto_solicitado', 'type' => 'number', 'required' => true];
                $campos[] = ['name' => 'plazo_meses', 'type' => 'number', 'required' => false];
            }

            if ($tipo->requiereColaboradorObjetivo()) {
                $campos[] = ['name' => 'colaborador_objetivo_id', 'type' => 'select', 'required' => true];
            }

            return [
                'clave' => $tipo->value,
                'nombre' => $tipo->etiqueta(),
                'requiere_fechas' => $requiereFechas,
                'requiere_horario' => $requiereHorario,
                'requiere_dias' => $tipo->requiereDias(),
                'requiere_monto' => $tipo->requiereMonto(),
                'requiere_colaborador_objetivo' => $tipo->requiereColaboradorObjetivo(),
                'requiere_motivo' => true,
                'permite_adjuntos' => true,
                'campos' => $campos,
            ];
        }, TipoSolicitudInterna::cases());
    }
}
