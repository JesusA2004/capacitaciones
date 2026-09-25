<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoFiniquito;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\OfficialFormatGeneration;
use App\Models\SolicitudInterna;
use App\Models\SolicitudInternaDocumento;
use App\Models\User;
use App\Notifications\Mobile\RhSolicitudCreadaNotification;
use App\Notifications\Mobile\SolicitudActualizadaNotification;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\MobilePush\PushNotifier;
use App\Services\Nomina\PrestamoService;
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
    /**
     * Límite defensivo de tarjetas cargadas en el tablero Kanban (ver
     * paraTablero()). Si hay más solicitudes activas que esto, el resto no
     * desaparece en silencio: la vista debe avisar con el total real.
     */
    private const LIMITE_TABLERO = 500;

    /**
     * Resultado del último intento de generación automática de documento
     * oficial (ver cambiarEstado()) — los controladores lo leen justo
     * después de aprobar()/moverEnTablero() para enriquecer el toast, sin
     * que esto revierta ni retrase la aprobación en sí.
     *
     * @var array{generacion: ?OfficialFormatGeneration, aplica: bool, motivo_error: ?string}|null
     */
    private ?array $ultimoResultadoDocumentoOficial = null;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly SolicitudDocumentoStorageService $storage,
        private readonly ResponsableResolverService $responsables,
        private readonly PushNotifier $push,
        private readonly VacacionesService $vacaciones,
        private readonly BajaColaboradorService $bajaColaborador,
        private readonly FiniquitoService $finiquito,
        private readonly SolicitudFormatoOficialService $formatoOficial,
        private readonly PrestamoService $prestamo,
        private readonly AprobacionJerarquicaService $aprobaciones,
        private readonly TareasSolicitudService $tareasSolicitud,
        private readonly ComprobanteSolicitudService $comprobantes,
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
            $colaboradorObjetivo = Colaborador::query()->where('id', $datos['colaborador_objetivo_id'])->firstOrFail();

            if (Gate::forUser($solicitante)->denies('crearBaja', [SolicitudInterna::class, $colaboradorObjetivo])) {
                throw ValidationException::withMessages([
                    'colaborador_objetivo_id' => 'No tienes permiso para solicitar la baja de este colaborador.',
                ]);
            }
        }

        $solicitud = DB::transaction(function () use ($solicitante, $datos, $colaboradorObjetivo): SolicitudInterna {
            // El folio final se deriva del id autoincremental real de la
            // fila (asignado de forma atómica por la base de datos), nunca
            // de un max(id)+1 leído antes del insert: dos requests
            // concurrentes podían leer el mismo max(id) y calcular el mismo
            // folio, chocando contra el índice unique('folio') con un 500
            // en vez de un folio correcto. El valor temporal solo existe
            // durante el insert (columna NOT NULL) y se reemplaza abajo,
            // dentro de la misma transacción, antes de que nadie más pueda
            // leer la fila.
            $solicitud = SolicitudInterna::create([
                'folio' => $this->folioTemporal(),
                'user_id' => $solicitante->id,
                'colaborador_id' => $solicitante->colaborador_id,
                'colaborador_objetivo_id' => $colaboradorObjetivo?->user?->id,
                'objetivo_colaborador_id' => $colaboradorObjetivo?->id,
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
                'empresa_id' => $solicitante->colaborador?->empresa()?->id,
                'sucursal_id' => $solicitante->colaborador?->sucursal_principal_id,
            ]);

            $solicitud->update(['folio' => sprintf('SOL-%06d', $solicitud->id)]);

            $this->registrarHistorial($solicitud, $solicitante, 'creada');
            $this->registrarHistorial($solicitud, $solicitante, 'enviada');

            $this->notificarSinFallar(function () use ($solicitud, $solicitante): void {
                $responsables = $this->responsables->paraColaborador($solicitante, 'rh.solicitudes.aprobar');

                NotificationFacade::send($responsables, new RhSolicitudCreadaNotification($solicitud));
                $this->push->aUsuarios($responsables, 'rh_solicitud', $solicitud->id, 'Nueva solicitud por revisar', 'Un colaborador envió una solicitud.');
            });

            return $solicitud;
        });

        // Bandeja de trabajo: pendiente para el jefe (si el tipo exige su
        // visto bueno) o para quien autoriza. Nunca revierte la solicitud.
        $this->notificarSinFallar(fn () => $this->tareasSolicitud->alCrear($solicitud));

        return $solicitud;
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

    /**
     * Valor de folio de un solo uso mientras la fila no tiene id todavía
     * (la columna es NOT NULL + unique). Nunca se le muestra a nadie: se
     * reemplaza por el folio real (SOL-{id}) en la misma transacción,
     * segundos — normalmente microsegundos — después de crearse la fila.
     */
    private function folioTemporal(): string
    {
        return 'TMP-'.bin2hex(random_bytes(6));
    }

    /**
     * @return LengthAwarePaginator<int, SolicitudInterna>
     */
    public function paraColaborador(User $colaborador): LengthAwarePaginator
    {
        return SolicitudInterna::query()
            ->where(function (Builder $q) use ($colaborador): void {
                $q->where('user_id', $colaborador->id);

                // Fallback temporal: solicitudes legacy creadas antes de que
                // crear() empezara a llenar colaborador_id (ver
                // SolicitudesService::crear()) solo tienen user_id.
                if ($colaborador->colaborador_id !== null) {
                    $q->orWhere('colaborador_id', $colaborador->colaborador_id);
                }
            })
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
     * defensivo (self::LIMITE_TABLERO): el tablero es para el trabajo del
     * día, no un reporte histórico (para eso están las exportaciones de
     * paraExportar()) — pero el recorte NUNCA es silencioso: se devuelve
     * también el total real para que la vista avise "mostrando N de
     * total" en vez de esconder trabajo activo sin decirlo.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{items: Collection<int, SolicitudInterna>, total: int, limite: int}
     */
    public function paraTablero(User $revisor, array $filtros = []): array
    {
        $query = $this->queryRevision($revisor, $filtros)
            ->whereNotIn('estado', [EstadoSolicitudInterna::Creada->value, EstadoSolicitudInterna::Cancelada->value]);

        $total = (clone $query)->count();

        $items = $query->orderBy('created_at')->limit(self::LIMITE_TABLERO)->get();

        return [
            'items' => $items,
            'total' => $total,
            'limite' => self::LIMITE_TABLERO,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<SolicitudInterna>
     */
    private function queryRevision(User $revisor, array $filtros = []): Builder
    {
        $query = SolicitudInterna::query()
            // 'users' no tiene columna empresa_id propia (se deriva de la
            // sucursal, ver Colaborador::empresa()) — solo la propia
            // SolicitudInterna la tiene (snapshot al crear, ver crear() más
            // arriba). colaborador_id es la fuente real de "quién es esta
            // solicitud" (ver SolicitudInterna::personaSolicitante());
            // usuario.colaborador solo se conserva como fallback de
            // lectura para solicitudes legacy sin colaborador_id.
            ->with([
                'colaborador:id,name,apellidos,sucursal_principal_id,departamento_id,puesto_id,foto_path',
                'colaborador.departamento:id,nombre',
                'colaborador.puesto:id,nombre',
                'usuario:id,name,apellidos,colaborador_id',
                'usuario.colaborador:id,name,apellidos,sucursal_principal_id,departamento_id,puesto_id,foto_path',
                'usuario.colaborador.departamento:id,nombre',
                'usuario.colaborador.puesto:id,nombre',
                'revisadoPor:id,name,apellidos',
                'sucursal:id,nombre',
                'documentosGenerados:id,solicitud_id,status',
                'finiquitoCalculo:id,solicitud_interna_id,estado',
            ])
            ->withCount('documentos');

        $query = $this->limitarPorAlcance($query, $revisor);

        return $query
            ->when($filtros['estado'] ?? null, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->when($filtros['tipo'] ?? null, fn (Builder $q, string $v) => $q->where('tipo', $v))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, string $v) => $q->where('sucursal_id', $v))
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, string $v) => $q->where('empresa_id', $v))
            ->when($filtros['departamento_id'] ?? null, fn (Builder $q, string $v) => $q->where(fn (Builder $sub) => $sub
                ->whereHas('colaborador', fn (Builder $u) => $u->where('departamento_id', $v))
                ->orWhereHas('usuario.colaborador', fn (Builder $u) => $u->where('departamento_id', $v))))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, string $v) => $q->where(fn (Builder $sub) => $sub
                ->whereHas('colaborador', fn (Builder $u) => $u->where('puesto_id', $v))
                ->orWhereHas('usuario.colaborador', fn (Builder $u) => $u->where('puesto_id', $v))))
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
            return $query->where(fn (Builder $q) => $q
                ->whereHas('colaborador', fn (Builder $u) => $u->where('jefe_id', $revisor->colaborador_id))
                ->orWhereHas('usuario.colaborador', fn (Builder $u) => $u->where('jefe_id', $revisor->colaborador_id)));
        }

        return $query->where(function (Builder $q) use ($revisor): void {
            $q->where('user_id', $revisor->id);

            if ($revisor->colaborador_id !== null) {
                $q->orWhere('colaborador_id', $revisor->colaborador_id);
            }
        });
    }

    public function marcarEnRevision(SolicitudInterna $solicitud, User $actor, ?string $comentario = null): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::EnRevision, $comentario);
    }

    /**
     * @param  array<string, mixed>  $datosAprobacion  Datos que la aprobación fija (p. ej. monto/plazo autorizado de un préstamo, ver PrestamoAutorizacionService).
     */
    public function aprobar(SolicitudInterna $solicitud, User $actor, ?string $comentario = null, array $datosAprobacion = []): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::Aprobada, $comentario, null, $datosAprobacion);
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

    /**
     * @param  array<string, mixed>  $datosAprobacion
     */
    private function cambiarEstado(SolicitudInterna $solicitud, User $actor, EstadoSolicitudInterna $nuevoEstado, ?string $comentario = null, ?string $motivoRechazo = null, array $datosAprobacion = []): SolicitudInterna
    {
        // Visto bueno jerárquico (config solicitudes.visto_bueno_jefe): la
        // autorización final nunca se salta al jefe inmediato.
        if ($nuevoEstado === EstadoSolicitudInterna::Aprobada
            && $this->aprobaciones->requiereVistoBuenoJefe($solicitud)
            && ! $this->aprobaciones->tieneVistoBuenoJefe($solicitud)
        ) {
            throw ValidationException::withMessages([
                'visto_bueno' => 'Falta el visto bueno del jefe inmediato del colaborador antes de autorizar.',
            ]);
        }

        // Única puerta de cambio de estado (tablero Kanban y botones del
        // detalle pasan por aquí): el mapa de transiciones vive en el enum
        // (EstadoSolicitudInterna::puedeTransicionarA()) para que el backend
        // sea la autoridad real, nunca solo el frontend que oculta botones.
        if (! $solicitud->estado->puedeTransicionarA($nuevoEstado)) {
            throw ValidationException::withMessages([
                'estado' => "No se puede mover la solicitud de «{$solicitud->estado->etiqueta()}» a «{$nuevoEstado->etiqueta()}».",
            ]);
        }

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

        // No se ejecuta una baja sin que RH/contabilidad haya revisado su
        // cálculo de finiquito — salvo el permiso especial reservado a
        // super_admin (ver config/finiquitos.php y FiniquitoService).
        if ($nuevoEstado === EstadoSolicitudInterna::Aprobada
            && $solicitud->tipo === TipoSolicitudInterna::BajaColaborador
            && config('finiquitos.exigir_finiquito_revisado_para_aprobar_baja')
            && ! $actor->can('solicitudes.bajas.omitir_finiquito')
        ) {
            $finiquito = FiniquitoCalculo::query()->where('solicitud_interna_id', $solicitud->id)->first();

            if ($finiquito === null || ! in_array($finiquito->estado, [EstadoFiniquito::Revisado, EstadoFiniquito::Aprobado, EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true)) {
                throw ValidationException::withMessages([
                    'finiquito' => 'Calcula y revisa el finiquito antes de aprobar esta baja.',
                ]);
            }
        }

        $solicitud = DB::transaction(function () use ($solicitud, $actor, $nuevoEstado, $comentario, $motivoRechazo, $datosAprobacion): SolicitudInterna {
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
                $solicitud->loadMissing(['objetivoColaborador', 'colaboradorObjetivo.colaborador']);
                $colaboradorDeBaja = $solicitud->colaboradorDeBaja();

                if ($colaboradorDeBaja !== null) {
                    $this->bajaColaborador->ejecutar($colaboradorDeBaja, $actor, $solicitud->motivo);
                }

                $this->finiquito->marcarAprobadoConLaBaja($solicitud);
            }

            // Al aprobar un préstamo interno se crea el registro operativo
            // real (App\Services\Nomina\PrestamoService) DENTRO de esta
            // misma transacción: aprobar la solicitud y que exista el
            // préstamo son la misma operación atómica, nunca dos pasos
            // separados que puedan quedar a medias. Nace en
            // 'pendiente_entrega' — RH todavía tiene que confirmar la
            // entrega del dinero (ver PrestamoService::activar()), eso no
            // pasa aquí.
            if ($nuevoEstado === EstadoSolicitudInterna::Aprobada && $solicitud->tipo === TipoSolicitudInterna::PrestamoInterno) {
                $this->prestamo->crearDesdeSolicitud($solicitud, $datosAprobacion, $actor);
            }

            // Documento oficial automático (config/solicitudes.php): se
            // genera al aprobar, para todos los tipos con formato mapeado,
            // no solo baja. Nunca revierte la aprobación si falla (ver
            // SolicitudFormatoOficialService::generarSiAplica()), pero el
            // resultado SÍ se guarda para que el controlador lo muestre en
            // el toast (ultimoResultadoDocumentoOficial()) — a diferencia de
            // un fallo de notificación, esto no se debe esconder solo en el log.
            if ($nuevoEstado === EstadoSolicitudInterna::Aprobada) {
                $this->ultimoResultadoDocumentoOficial = $this->formatoOficial->generarSiAplica($solicitud, $actor);
            } else {
                $this->ultimoResultadoDocumentoOficial = null;
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

        // Efectos posteriores al commit (nunca revierten el cambio de estado):
        // pendientes de la bandeja y comprobante PDF de vacaciones/permisos
        // archivado en el expediente.
        $this->notificarSinFallar(fn () => $this->tareasSolicitud->alCambiarEstado($solicitud, $actor));

        if ($nuevoEstado === EstadoSolicitudInterna::Aprobada && $this->comprobantes->aplicaPara($solicitud)) {
            $this->notificarSinFallar(fn () => $this->comprobantes->generarSiAplica($solicitud, $actor));
        }

        return $solicitud;
    }

    /**
     * Resultado de la generación automática del documento oficial en el
     * último aprobar()/moverEnTablero() a "aprobada": null si la solicitud
     * no era de un tipo con formato mapeado o si el último cambio de estado
     * no fue una aprobación. Los controladores lo consultan justo después de
     * aprobar() para construir el toast que ve RH (ver
     * App\Http\Controllers\Rh\SolicitudController::aprobar()).
     *
     * @return array{generacion: ?OfficialFormatGeneration, aplica: bool, motivo_error: ?string}|null
     */
    public function ultimoResultadoDocumentoOficial(): ?array
    {
        return $this->ultimoResultadoDocumentoOficial;
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
     * `$autoservicio` (app móvil del colaborador): sin baja de colaborador y
     * con el préstamo reducido a monto + motivo (plazo y condiciones son
     * decisión de RH al autorizar). El Portal web conserva el catálogo
     * completo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tiposConFormulario(bool $autoservicio = false): array
    {
        $tipos = $autoservicio
            ? array_values(array_filter(TipoSolicitudInterna::cases(), fn (TipoSolicitudInterna $t) => $t->creableEnAutoservicio()))
            : TipoSolicitudInterna::cases();

        return array_map(function (TipoSolicitudInterna $tipo) use ($autoservicio): array {
            if ($autoservicio && $tipo->requiereMonto()) {
                return [
                    'clave' => $tipo->value,
                    'nombre' => $tipo->etiqueta(),
                    'requiere_fechas' => false,
                    'requiere_horario' => false,
                    'requiere_dias' => false,
                    'requiere_monto' => true,
                    'requiere_colaborador_objetivo' => false,
                    'requiere_motivo' => true,
                    'permite_adjuntos' => false,
                    'campos' => [
                        ['name' => 'monto_solicitado', 'type' => 'number', 'required' => true],
                        ['name' => 'motivo', 'type' => 'text', 'required' => true],
                    ],
                ];
            }

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
        }, $tipos);
    }
}
