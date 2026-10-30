<?php

namespace App\Services\Solicitudes;

use App\Enums\AlcanceDatos;
use App\Enums\CausalPermisoEspecial;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\GocePermiso;
use App\Enums\ModoFechasSolicitud;
use App\Enums\TipoPermisoSolicitado;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\OfficialFormatGeneration;
use App\Models\SolicitudInterna;
use App\Models\SolicitudInternaDocumento;
use App\Models\User;
use App\Notifications\Mobile\RhSolicitudCreadaNotification;
use App\Notifications\Mobile\SolicitudActualizadaNotification;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use App\Services\Configuracion\WorkflowRoutingService;
use App\Services\Expedientes\ActualizacionDatosService;
use App\Services\Expedientes\CorreccionesService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\Nomina\IncidenciasNominaService;
use App\Services\Nomina\PrestamoService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Permisos\AlcanceModuloService;
use App\Services\RhMobile\ResponsableResolverService;
use App\Services\Vacaciones\VacacionesService;
use App\Support\OrigenPeticion;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
     * Una baja de colaborador no se aprueba/rechaza/corrige desde la bandeja
     * de Solicitudes: hacerlo saltaría el Cierre laboral (finiquito,
     * documentos, fecha efectiva). Ver CierreLaboralService.
     */
    public const MENSAJE_BAJA_EN_CIERRE = 'La baja se gestiona en el Cierre laboral del colaborador.';

    /** Documentos con vista previa antes de aprobar (ruta {documento}). */
    public const VISTA_PREVIA_COMPROBANTE = 'comprobante';

    public const VISTA_PREVIA_FORMATO_OFICIAL = 'formato-oficial';

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
        private readonly SolicitudDocumentoStorageService $storage,
        private readonly ResponsableResolverService $responsables,
        private readonly NotificacionEventosService $eventos,
        private readonly VacacionesService $vacaciones,
        private readonly BajaColaboradorService $bajaColaborador,
        private readonly FiniquitoService $finiquito,
        private readonly SolicitudFormatoOficialService $formatoOficial,
        private readonly PrestamoService $prestamo,
        private readonly AprobacionJerarquicaService $aprobaciones,
        private readonly TareasSolicitudService $tareasSolicitud,
        private readonly ComprobanteSolicitudService $comprobantes,
        private readonly WorkflowRoutingService $routing,
        private readonly FechasSolicitudService $fechas,
        private readonly ActualizacionDatosService $actualizacionDatos,
        private readonly AlcanceModuloService $alcanceModulo,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por StoreSolicitudInternaRequest: tipo, motivo, fecha_inicio?, fecha_fin?, observaciones?, dias_solicitados? (vacaciones), monto_solicitado?/plazo_meses? (préstamo), colaborador_objetivo_id? (baja).
     *
     * @throws ValidationException Saldo de vacaciones insuficiente, o sin permiso para dar de baja al colaborador objetivo.
     */
    public function crear(User $solicitante, array $datos): SolicitudInterna
    {
        $tipo = TipoSolicitudInterna::from($datos['tipo']);
        // Clientes anteriores (rango + dias_solicitados) reciben el error en su campo de siempre.
        $campoDias = isset($datos['dias']) ? 'dias' : 'dias_solicitados';

        // Fechas calculadas por el backend según el tipo (duración natural,
        // días específicos de vacaciones, un día…): nunca una fecha fin
        // capturada a mano (FechasSolicitudService).
        $datos = $this->fechas->normalizar($tipo, $datos, $solicitante->colaborador);

        // Permiso: solo lo que pide el formato oficial. La hora que no
        // corresponde a la modalidad se descarta (nunca se guarda basura) y
        // el motivo, si no se escribió, es la propia modalidad.
        if ($tipo === TipoSolicitudInterna::Permiso) {
            $permisoTipo = TipoPermisoSolicitado::from((string) $datos['permiso_tipo']);
            $datos['hora_salida'] = $permisoTipo === TipoPermisoSolicitado::SalirTemprano ? ($datos['hora_salida'] ?? null) : null;
            $datos['hora_entrada'] = $permisoTipo === TipoPermisoSolicitado::LlegarTarde ? ($datos['hora_entrada'] ?? null) : null;
            $datos['permiso_causal'] = ($datos['permiso_goce'] ?? null) === GocePermiso::Especial->value ? ($datos['permiso_causal'] ?? null) : null;
            $datos['motivo'] = trim((string) ($datos['motivo'] ?? '')) !== '' ? $datos['motivo'] : $permisoTipo->etiqueta();
        }

        // «Completar mis datos»: valores estructurados que se aplican al
        // expediente solo cuando RH autoriza (ActualizacionDatosService).
        $datos['datos_propuestos'] = null;
        $datos['datos_sensibles_propuestos'] = null;
        $datos['datos_base'] = null;

        if ($tipo === TipoSolicitudInterna::ActualizacionDatos && ! empty($datos['datos'])) {
            // Los datos médicos van cifrados aparte (datos_sensibles_propuestos).
            $preparados = $this->actualizacionDatos->preparar((array) $datos['datos'], $solicitante->colaborador);
            $datos['datos_propuestos'] = $preparados['propuestos'];
            $datos['datos_sensibles_propuestos'] = $preparados['sensibles'];
            $datos['datos_base'] = $preparados['base'];
            $datos['motivo'] = trim((string) ($datos['motivo'] ?? '')) !== '' ? $datos['motivo'] : $preparados['motivo'];
        }

        // Préstamo: 6 meses completos de antigüedad (fecha del servidor);
        // misma regla para web, API y app (422 si no cumple).
        if ($tipo === TipoSolicitudInterna::PrestamoInterno) {
            app(AprobacionOrganizacionalService::class)->validarElegibilidadPrestamo($solicitante->colaborador);
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

        $solicitud = DB::transaction(function () use ($solicitante, $datos, $colaboradorObjetivo, $tipo, $campoDias): SolicitudInterna {
            // Saldo de vacaciones: el chequeo y la creación viven en la MISMA
            // transacción, con un lock sobre la fila del colaborador, para que
            // dos solicitudes concurrentes del mismo colaborador (doble clic,
            // dos dispositivos) se serialicen — la segunda ya ve los días «en
            // solicitud» de la primera y nunca rebasa el saldo (P2-17). El
            // lock se toma sobre `colaboradores` porque es la identidad real
            // que suma el saldo (SolicitudesService + tabla legacy), aunque
            // las solicitudes sigan ligadas por `user_id`.
            if ($tipo === TipoSolicitudInterna::Vacaciones) {
                if ($solicitante->colaborador_id !== null) {
                    Colaborador::query()->whereKey($solicitante->colaborador_id)->lockForUpdate()->first();
                }

                $saldo = $this->vacaciones->saldo($solicitante);

                if (! $saldo['puede_solicitar']) {
                    throw ValidationException::withMessages([
                        $campoDias => $saldo['motivo_no_disponible'] ?? 'Todavía no puedes solicitar vacaciones.',
                    ]);
                }

                if ((int) $datos['dias_solicitados'] > $saldo['dias_disponibles']) {
                    throw ValidationException::withMessages([
                        $campoDias => sprintf('Pediste %d días y tienes %d disponibles.', (int) $datos['dias_solicitados'], $saldo['dias_disponibles']),
                    ]);
                }
            }

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
                // Causal de rotación: la valida quien crea la baja
                // (CierreLaboralService vía CausalRotacionService::resolver).
                'causal_rotacion_id' => $datos['causal_rotacion_id'] ?? null,
                'tipo' => $datos['tipo'],
                'estado' => EstadoSolicitudInterna::Enviada,
                'fecha_inicio' => $datos['fecha_inicio'] ?? null,
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'dias_solicitados' => $datos['dias_solicitados'] ?? null,
                'hora_salida' => $datos['hora_salida'] ?? null,
                'hora_entrada' => $datos['hora_entrada'] ?? null,
                'permiso_tipo' => $datos['permiso_tipo'] ?? null,
                'permiso_goce' => $datos['permiso_goce'] ?? null,
                'permiso_causal' => $datos['permiso_causal'] ?? null,
                'monto_solicitado' => $datos['monto_solicitado'] ?? null,
                'plazo_meses' => $datos['plazo_meses'] ?? null,
                'motivo' => $datos['motivo'],
                'observaciones' => $datos['observaciones'] ?? null,
                'datos_propuestos' => $datos['datos_propuestos'],
                'datos_sensibles_propuestos' => $datos['datos_sensibles_propuestos'],
                'datos_base' => $datos['datos_base'],
                'empresa_id' => $solicitante->colaborador?->empresa()?->id,
                'sucursal_id' => $solicitante->colaborador?->sucursal_principal_id,
            ]);

            $solicitud->update(['folio' => sprintf('SOL-%06d', $solicitud->id)]);

            // Vacaciones: la lista de días es la fuente real (fecha única por solicitud).
            foreach ($datos['dias_vacaciones'] as $dia) {
                $solicitud->diasVacaciones()->create(['colaborador_id' => $solicitante->colaborador_id, 'fecha' => $dia]);
            }

            $this->registrarHistorial($solicitud, $solicitante, 'creada');
            $this->registrarHistorial($solicitud, $solicitante, 'enviada');

            $this->notificarSinFallar(function () use ($solicitud, $solicitante): void {
                // La baja NO es una solicitud por revisar: la aplica de
                // inmediato el jefe directo o RH (sin autorización posterior);
                // CierreLaboralService avisa con «cierre_baja_solicitada».
                if ($solicitud->tipo === TipoSolicitudInterna::BajaColaborador) {
                    return;
                }

                // Destinatarios por la regla "solicitud_creada" (Configuración →
                // Notificaciones) sobre la PERSONA solicitante; sin persona
                // enlazada se conserva el criterio por permiso + alcance.
                $responsables = $solicitante->colaborador !== null
                    ? $this->routing->resolver('solicitud_creada', $solicitante->colaborador, ['solicitante' => $solicitante, 'excluir' => [$solicitante->id]])->pluck('usuario')
                    : $this->responsables->paraColaborador($solicitante, 'rh.solicitudes.aprobar');

                // Con vistos buenos por dar (jefe/regional…) RH todavía no
                // puede actuar: no se le avisa ahora. Le llega al completarse
                // el último visto bueno (TareasSolicitudService). Solo se
                // notifica a quien puede actuar (AprobacionOrganizacionalService).
                if ($this->aprobaciones->requiereVistoBuenoJefe($solicitud)) {
                    $responsables = collect();
                } elseif ($solicitud->tipo === TipoSolicitudInterna::PrestamoInterno) {
                    // El préstamo lo autoriza quien tiene prestamos.autorizar
                    // (con alcance sobre la persona), no rh.solicitudes.aprobar.
                    // Se respeta la regla configurada (Configuración →
                    // Notificaciones) dentro de quienes PUEDEN actuar; si la
                    // regla no deja a nadie que pueda, el aviso va a todos
                    // los que pueden (nunca se pierde).
                    $puedenActuar = app(AprobacionOrganizacionalService::class)->quienesPuedenActuar($solicitud)
                        ->reject(fn (User $u) => $u->id === $solicitante->id)
                        ->values();
                    $configurados = $responsables->filter(fn ($u) => $u instanceof User)->pluck('id')->all();
                    $enRegla = $puedenActuar->filter(fn (User $u) => in_array($u->id, $configurados, true))->values();
                    $responsables = $enRegla->isNotEmpty() ? $enRegla : $puedenActuar;
                }

                // Un solo flujo: confirmación a quien la envió (aunque sea
                // RH o un admin) + tarea a quien la resuelve; si es la misma
                // persona recibe UN aviso combinado. Todo sale tras el commit.
                $this->eventos->registrarConPendiente(
                    $solicitante,
                    new Aviso('solicitud_creada', 'solicitud', $this->tituloConfirmacion($solicitud), sprintf('Tu solicitud %s (%s) quedó registrada y en revisión.', $solicitud->folio, mb_strtolower($solicitud->tipo->etiqueta())), $solicitud, 'registrada'),
                    $responsables,
                    // Corrección del expediente: qué datos pide (nunca sus valores).
                    app(CorreccionesService::class)->esCorreccion($solicitud)
                        ? app(CorreccionesService::class)->avisoPendiente($solicitud)
                        : new Aviso('solicitud_creada', 'rh_solicitud', 'Nueva solicitud por revisar', 'Un colaborador envió una solicitud.', $solicitud, 'revisar_solicitud', 'media', [], null, new RhSolicitudCreadaNotification($solicitud)),
                );
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
     * "Solicitud enviada", "Vacaciones solicitadas"… para la confirmación de
     * quien hizo la solicitud.
     */
    private function tituloConfirmacion(SolicitudInterna $solicitud): string
    {
        return match ($solicitud->tipo) {
            TipoSolicitudInterna::Vacaciones => 'Vacaciones solicitadas',
            TipoSolicitudInterna::PrestamoInterno => 'Préstamo solicitado',
            TipoSolicitudInterna::BajaColaborador => 'Baja solicitada',
            TipoSolicitudInterna::ActualizacionDatos => 'Cambio de datos enviado',
            default => 'Solicitud enviada',
        };
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
            // La baja no es una solicitud personal: se consulta en el Cierre
            // laboral del colaborador (y RH la ve en su bandeja).
            ->where('tipo', '!=', TipoSolicitudInterna::BajaColaborador)
            ->with(['revisadoPor:id,name,apellidos', 'diasVacaciones'])
            ->orderByDesc('created_at')
            ->paginate(Paginacion::porPagina());
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
            ->paginate(Paginacion::porPagina())
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
            // Folio, motivo o el colaborador (buscador único: nombre,
            // número, puesto, sucursal… — «cuernavaca» trae las de esa sucursal).
            ->when($filtros['busqueda'] ?? null, function (Builder $q, string $busqueda): void {
                $buscador = app(BuscadorColaboradoresService::class);
                $q->where(function (Builder $sub) use ($busqueda, $buscador): void {
                    $sub->where('folio', 'like', "%{$busqueda}%")
                        ->orWhere('motivo', 'like', "%{$busqueda}%");
                    $buscador->aplicarPorColumna($sub, $busqueda, 'colaborador_id', 'or');
                    $buscador->aplicarPorUsuario($sub, $busqueda, 'user_id', 'or');
                });
            });
    }

    /**
     * Acota una consulta de solicitudes al alcance de REVISIÓN del usuario
     * (alcance del módulo «Solicitudes»), el MISMO que usan paraRevision()/
     * el Portal web y `/rh/solicitudes` — para que la bandeja móvil de
     * pendientes (RhPendientesService) devuelva exactamente el mismo conjunto
     * que esas superficies y no dos recortes distintos por pantalla (P1-12).
     *
     * @param  Builder<SolicitudInterna>  $query
     * @return Builder<SolicitudInterna>
     */
    public function limitarRevisionPorAlcance(Builder $query, User $revisor): Builder
    {
        return $this->limitarPorAlcance($query, $revisor);
    }

    /**
     * @param  Builder<SolicitudInterna>  $query
     * @return Builder<SolicitudInterna>
     */
    private function limitarPorAlcance(Builder $query, User $revisor): Builder
    {
        // Alcance del módulo «Solicitudes» (Roles y permisos): Propios,
        // Equipo, Sucursal, Región o Todos. Ver ≠ aprobar: las acciones
        // las sigue decidiendo SolicitudInternaPolicy.
        $nivel = $this->alcanceModulo->nivel($revisor, 'solicitudes');

        if ($nivel === AlcanceDatos::Todos) {
            return $query;
        }

        if ($nivel === AlcanceDatos::Sucursal || $nivel === AlcanceDatos::Region) {
            $sucursales = $this->alcanceModulo->sucursalesPara($revisor, $nivel)->all();

            return $query->where(fn (Builder $q) => $q->whereIn('sucursal_id', $sucursales)->orWhere('user_id', $revisor->id));
        }

        if ($nivel === AlcanceDatos::Equipo) {
            return $query->where(fn (Builder $q) => $q
                ->where('user_id', $revisor->id)
                ->orWhereHas('colaborador', fn (Builder $u) => $u->where('jefe_id', $revisor->colaborador_id))
                ->orWhereHas('usuario.colaborador', fn (Builder $u) => $u->where('jefe_id', $revisor->colaborador_id)));
        }

        return $query->where(function (Builder $q) use ($revisor): void {
            $q->where('user_id', $revisor->id);

            if ($revisor->colaborador_id !== null) {
                $q->orWhere('colaborador_id', $revisor->colaborador_id);
            }
        });
    }

    /**
     * $desdeCierreLaboral: SOLO lo pasa App\Services\CierreLaboral\CierreLaboralService.
     * Una baja de colaborador nunca cambia de estado desde la bandeja de
     * Solicitudes (web, API ni tablero): se gestiona en el Cierre laboral.
     */
    public function marcarEnRevision(SolicitudInterna $solicitud, User $actor, ?string $comentario = null, bool $desdeCierreLaboral = false): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::EnRevision, $comentario, desdeCierreLaboral: $desdeCierreLaboral);
    }

    /**
     * @param  array<string, mixed>  $datosAprobacion  Datos que la aprobación fija (p. ej. monto/plazo autorizado de un préstamo, ver PrestamoAutorizacionService).
     * @param  bool  $desdeCierreLaboral  Solo CierreLaboralService (ver marcarEnRevision()).
     */
    public function aprobar(SolicitudInterna $solicitud, User $actor, ?string $comentario = null, array $datosAprobacion = [], bool $desdeCierreLaboral = false): SolicitudInterna
    {
        $aprobada = $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::Aprobada, $comentario, null, $datosAprobacion, $desdeCierreLaboral);
        $this->sincronizarNominaDePermiso($aprobada, $actor);

        return $aprobada;
    }

    /**
     * Un permiso aprobado o cancelado cambia los días pagados de sus recibos.
     * Un fallo aquí nunca deshace la solicitud: queda en el log.
     */
    private function sincronizarNominaDePermiso(SolicitudInterna $solicitud, User $actor): void
    {
        if (! in_array($solicitud->tipo, [TipoSolicitudInterna::Permiso, TipoSolicitudInterna::PermisoConGoce, TipoSolicitudInterna::PermisoSinGoce], true)
            || $solicitud->fecha_inicio === null) {
            return;
        }

        try {
            $colaborador = $solicitud->colaborador ?? $solicitud->usuario?->colaborador;

            if ($colaborador !== null) {
                app(IncidenciasNominaService::class)->sincronizarRecibos($colaborador, $solicitud->fecha_inicio, $solicitud->fecha_fin ?? $solicitud->fecha_inicio, $actor, sprintf('Permiso %s: %s', (string) $solicitud->folio, $solicitud->estado->etiqueta()));
            }
        } catch (Throwable $e) {
            Log::warning('SolicitudesService: no se pudo recalcular la nómina del permiso.', ['solicitud_id' => $solicitud->id, 'error' => $e->getMessage()]);
        }
    }

    public function rechazar(SolicitudInterna $solicitud, User $actor, string $motivoRechazo): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::Rechazada, $motivoRechazo, $motivoRechazo);
    }

    public function requerirCorreccion(SolicitudInterna $solicitud, User $actor, string $comentario): SolicitudInterna
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::RequiereCorreccion, $comentario);
    }

    /**
     * @param  bool  $desdeCierreLaboral  Solo CierreLaboralService (ver marcarEnRevision()).
     */
    public function cancelar(SolicitudInterna $solicitud, User $actor, bool $desdeCierreLaboral = false): SolicitudInterna
    {
        $cancelada = $this->cambiarEstado($solicitud, $actor, EstadoSolicitudInterna::Cancelada, desdeCierreLaboral: $desdeCierreLaboral);
        $this->sincronizarNominaDePermiso($cancelada, $actor);

        return $cancelada;
    }

    /**
     * Qué puede hacer $usuario con esta solicitud en la bandeja de revisión
     * (detalle y tablero Kanban, web): misma SolicitudInternaPolicy que
     * autoriza la acción, más las reglas del servicio que la policy no ve
     * (estado final, baja gestionada en el Cierre laboral). La UI solo
     * muestra/oculta; el backend vuelve a validar al ejecutar.
     *
     * @return array{revisar: bool, requerir_correccion: bool, aprobar: bool, rechazar: bool, motivo_bloqueo: ?string}
     */
    public function accionesBandeja(User $usuario, SolicitudInterna $solicitud): array
    {
        if ($solicitud->tipo === TipoSolicitudInterna::BajaColaborador) {
            return ['revisar' => false, 'requerir_correccion' => false, 'aprobar' => false, 'rechazar' => false, 'motivo_bloqueo' => self::MENSAJE_BAJA_EN_CIERRE];
        }

        if ($solicitud->estado->esFinal()) {
            return ['revisar' => false, 'requerir_correccion' => false, 'aprobar' => false, 'rechazar' => false, 'motivo_bloqueo' => null];
        }

        $gate = Gate::forUser($usuario);
        $aprobar = $gate->allows('aprobar', $solicitud);
        $motivo = null;

        // Con vistos buenos por dar, RH todavía no puede aprobar: el botón
        // se muestra deshabilitado con el motivo humano del motor
        // («RH podrá aprobar cuando X dé su visto bueno.»).
        if ($aprobar && ! $this->aprobaciones->tieneVistoBuenoJefe($solicitud)) {
            $aprobar = false;
            $paso = collect(app(AprobacionOrganizacionalService::class)->flujo($solicitud, $usuario)['pasos'])->firstWhere('clave', 'rh');
            $motivo = isset($paso['motivo_no_puede']) ? (string) $paso['motivo_no_puede'] : null;
        }

        return [
            'revisar' => $gate->allows('revisar', $solicitud),
            'requerir_correccion' => $gate->allows('requerirCorreccion', $solicitud),
            'aprobar' => $aprobar,
            'rechazar' => $gate->allows('rechazar', $solicitud),
            'motivo_bloqueo' => $motivo,
        ];
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
            default => throw ValidationException::withMessages([
                'estado' => 'Ese estado no se puede asignar desde el tablero.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $datosAprobacion
     */
    private function cambiarEstado(SolicitudInterna $solicitud, User $actor, EstadoSolicitudInterna $nuevoEstado, ?string $comentario = null, ?string $motivoRechazo = null, array $datosAprobacion = [], bool $desdeCierreLaboral = false): SolicitudInterna
    {
        // La baja vive en el Cierre laboral: desde la bandeja (web, API o
        // tablero) no se aprueba, rechaza, corrige, revisa ni cancela.
        if ($solicitud->tipo === TipoSolicitudInterna::BajaColaborador && ! $desdeCierreLaboral) {
            throw ValidationException::withMessages(['estado' => self::MENSAJE_BAJA_EN_CIERRE]);
        }

        // Nadie revisa, aprueba ni rechaza su propia solicitud (web y API por
        // igual). super_admin sí, auditado (ver ConSuperAdminOperativo); y el
        // Cierre laboral actúa sobre la baja de un tercero aunque la haya
        // registrado el mismo usuario de RH.
        if (in_array($nuevoEstado, [EstadoSolicitudInterna::EnRevision, EstadoSolicitudInterna::RequiereCorreccion, EstadoSolicitudInterna::Aprobada, EstadoSolicitudInterna::Rechazada], true)
            && ! $desdeCierreLaboral
            && $solicitud->esDe($actor)
            && ! $actor->hasRole('super_admin')
        ) {
            throw ValidationException::withMessages([
                'estado' => 'No puedes revisar, aprobar ni rechazar tu propia solicitud.',
            ]);
        }

        // Vistos buenos jerárquicos (gerente → regional, config
        // solicitudes.visto_bueno_jefe): sin ellos la solicitud se queda como
        // "Recibida"; ni RH la puede pasar a "Pendiente de autorizar" ni autorizarla.
        if (in_array($nuevoEstado, [EstadoSolicitudInterna::Aprobada, EstadoSolicitudInterna::EnRevision], true)
            && ! $this->aprobaciones->tieneVistoBuenoJefe($solicitud)
        ) {
            // Mensaje humano del motor («RH podrá aprobar cuando X dé su
            // visto bueno.»), nunca un texto técnico.
            $flujo = app(AprobacionOrganizacionalService::class)->flujo($solicitud, $actor);
            $paso = collect($flujo['pasos'])->firstWhere('clave', 'rh');

            throw ValidationException::withMessages([
                'visto_bueno' => (string) ($paso['motivo_no_puede'] ?? $flujo['motivo_no_puede'] ?? 'Faltan vistos buenos antes de pasarla a autorización.'),
            ]);
        }

        // Separación de funciones: el gerente/regional da el visto bueno; la
        // autorización final es de Recursos Humanos.
        if ($nuevoEstado === EstadoSolicitudInterna::Aprobada && $this->aprobaciones->esAprobadorDe($actor, $solicitud)) {
            throw ValidationException::withMessages([
                'visto_bueno' => 'Quien da el visto bueno no puede dar también la autorización final: le corresponde a Recursos Humanos.',
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

        // Una baja solo cambia de estado desde el Cierre laboral (ver arriba)
        // y ya no exige evidencia adjunta: se aplica al iniciarla y sus
        // documentos (renuncia/aviso generados por el sistema, firmados en
        // físico) viven en el propio cierre, no como adjuntos del trámite.

        // Un préstamo NUNCA se aprueba con el botón genérico "Aprobar": sin
        // monto/plazo autorizados, PrestamoService::crearDesdeSolicitud()
        // caería en sus defaults (monto/plazo tal cual lo pidió el
        // colaborador, sin revisión real de RH) — ver
        // PrestamoAutorizacionService::autorizar(), única puerta que sí
        // manda `monto_autorizado` en $datosAprobacion.
        if ($nuevoEstado === EstadoSolicitudInterna::Aprobada
            && $solicitud->tipo === TipoSolicitudInterna::PrestamoInterno
            && ! isset($datosAprobacion['monto_autorizado'])
        ) {
            throw ValidationException::withMessages([
                'monto_autorizado' => 'Un préstamo no se aprueba con el botón genérico: usa "Autorizar préstamo" para fijar el monto y el plazo autorizados.',
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

            if (in_array($nuevoEstado, [EstadoSolicitudInterna::Aprobada, EstadoSolicitudInterna::Rechazada], true)) {
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

            // Actualización de datos autorizada: los valores propuestos pasan
            // al expediente en la misma transacción (la completitud se
            // recalcula sola porque se lee en vivo).
            if ($nuevoEstado === EstadoSolicitudInterna::Aprobada && $solicitud->tipo === TipoSolicitudInterna::ActualizacionDatos) {
                $this->actualizacionDatos->aplicar($solicitud, $actor);
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
            // (no solo aprobada/rechazada): "en_revision" y "requiere_correccion" tambien
            // son cambios que le interesan, aunque no requieran una accion de
            // su parte.
            if (in_array($nuevoEstado, [
                EstadoSolicitudInterna::EnRevision,
                EstadoSolicitudInterna::Aprobada,
                EstadoSolicitudInterna::Rechazada,
                EstadoSolicitudInterna::RequiereCorreccion,
            ], true)) {
                $this->notificarSinFallar(function () use ($solicitud, $comentario): void {
                    $solicitud->loadMissing('usuario');
                    $correcciones = app(CorreccionesService::class);

                    // Corrección: el aviso dice qué resolvió RH y con qué
                    // comentario (motivo del rechazo o lo que falta).
                    if ($correcciones->esCorreccion($solicitud)) {
                        $this->eventos->avisarResultado($solicitud->usuario, $correcciones->avisoResultado($solicitud, $comentario));

                        return;
                    }

                    // Préstamo autorizado: PrestamoAutorizacionService ya avisa
                    // «prestamo_autorizado»; un segundo aviso genérico duplicaba.
                    if ($solicitud->tipo === TipoSolicitudInterna::PrestamoInterno && $solicitud->estado === EstadoSolicitudInterna::Aprobada) {
                        return;
                    }

                    $this->eventos->avisarResultado($solicitud->usuario, new Aviso(
                        'solicitud_actualizada',
                        'solicitud',
                        'Actualización de tu solicitud',
                        "Tu solicitud ahora está: {$solicitud->estado->etiqueta()}.",
                        $solicitud,
                        $solicitud->estado->value,
                        'media',
                        ['estado' => $solicitud->estado->value],
                        null,
                        new SolicitudActualizadaNotification($solicitud),
                    ));
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

    /**
     * Resumen del permiso (modalidad, goce, causal, horas y si RH ya lo
     * autorizó) para web y app. null si la solicitud no es un permiso.
     *
     * @return array<string, mixed>|null
     */
    public function resumenPermiso(SolicitudInterna $solicitud): ?array
    {
        return $this->comprobantes->resumenPermiso($solicitud);
    }

    /**
     * Actualización de datos: dato, valor en el expediente y valor
     * propuesto. null en cualquier otro tipo o sin datos estructurados.
     *
     * @return list<array{campo: string, etiqueta: string, actual: string|null, propuesto: string, clase: string}>|null
     */
    public function comparativoDatos(SolicitudInterna $solicitud): ?array
    {
        return $this->actualizacionDatos->comparativo($solicitud);
    }

    /**
     * Nombre visible de la solicitud: en una actualización de datos dice si
     * completa un dato faltante o cambia uno existente.
     */
    public function etiquetaTipo(SolicitudInterna $solicitud): string
    {
        return $this->actualizacionDatos->etiqueta($solicitud) ?? $solicitud->tipo->etiqueta();
    }

    /**
     * PDF del formato oficial de permiso, SOLO si RH ya lo autorizó (antes
     * responde un error de validación: el gerente no puede imprimirlo).
     */
    public function respuestaPermiso(SolicitudInterna $solicitud): StreamedResponse
    {
        return $this->comprobantes->respuestaPermiso($solicitud);
    }

    /**
     * Documentos que se generarán al aprobar y que RH puede ver ANTES de
     * autorizar (web y app): comprobante/constancia/formato de permiso y
     * formato oficial automático. Vacío si la solicitud ya es final.
     *
     * @return list<array{documento: string, titulo: string}>
     */
    public function vistasPreviasAprobacion(SolicitudInterna $solicitud): array
    {
        if ($solicitud->estado->esFinal()) {
            return [];
        }

        $lista = [];
        $titulo = $this->comprobantes->tituloDocumento($solicitud);

        if ($titulo !== null) {
            $lista[] = ['documento' => self::VISTA_PREVIA_COMPROBANTE, 'titulo' => $titulo];
        }

        if ($this->formatoOficial->tieneVistaPrevia($solicitud)) {
            $lista[] = ['documento' => self::VISTA_PREVIA_FORMATO_OFICIAL, 'titulo' => (string) $this->formatoOficial->formatoEsperado($solicitud)?->nombre];
        }

        return $lista;
    }

    /**
     * PDF de vista previa (no guarda nada) de uno de los documentos de
     * vistasPreviasAprobacion().
     *
     * @throws ValidationException Documento que no aplica o datos insuficientes.
     */
    public function vistaPreviaDocumento(SolicitudInterna $solicitud, string $documento, User $actor): string
    {
        return match ($documento) {
            self::VISTA_PREVIA_COMPROBANTE => $this->comprobantes->vistaPreviaPdf($solicitud, $actor),
            self::VISTA_PREVIA_FORMATO_OFICIAL => $this->formatoOficial->vistaPreviaPdf($solicitud, $actor),
            default => throw ValidationException::withMessages(['documento' => 'Documento de vista previa no reconocido.']),
        };
    }

    public function registrarHistorial(SolicitudInterna $solicitud, ?User $actor, string $accion, ?string $comentario = null): void
    {
        $solicitud->historial()->create([
            'user_id' => $actor?->id,
            'accion' => $accion,
            'comentario' => $comentario,
            // Origen (web/app/sistema), IP y user agent de quien actuó.
            ...OrigenPeticion::contexto(),
            'created_at' => now(),
        ]);
    }

    public function adjuntarDocumento(SolicitudInterna $solicitud, UploadedFile $archivo, User $actor): void
    {
        // Una solicitud aprobada/rechazada/cancelada ya no acepta cambios:
        // tampoco evidencia nueva (misma regla para web y app).
        if ($solicitud->estado->esFinal()) {
            throw ValidationException::withMessages([
                'archivo' => 'Esta solicitud ya está finalizada y no acepta más documentos.',
            ]);
        }

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
     * Resumen por tipo de solicitud para la pantalla de entrada de
     * Operación RH → Solicitudes: cuántas hay recibidas (esperan visto
     * bueno), pendientes de autorizar, en corrección, abiertas y del último
     * mes, siempre dentro del alcance del usuario.
     *
     * @return list<array{clave: string, etiqueta: string, recibidas: int, por_autorizar: int, correccion: int, abiertas: int, total: int, ultimo_mes: int}>
     */
    public function resumenPorTipo(User $revisor): array
    {
        $filas = $this->limitarPorAlcance(SolicitudInterna::query(), $revisor)
            ->whereNotIn('estado', [EstadoSolicitudInterna::Creada->value, EstadoSolicitudInterna::Cancelada->value])
            ->selectRaw('tipo, estado, count(*) as total, sum(case when created_at >= ? then 1 else 0 end) as recientes', [now()->subDays(30)])
            ->groupBy('tipo', 'estado')
            ->toBase()
            ->get();

        $resumen = [];

        foreach (TipoSolicitudInterna::cases() as $tipo) {
            // La baja se gestiona en el Cierre laboral, no en esta bandeja.
            if ($tipo === TipoSolicitudInterna::BajaColaborador) {
                continue;
            }

            $delTipo = $filas->where('tipo', $tipo->value);
            $cuenta = fn (EstadoSolicitudInterna $e): int => (int) ($delTipo->firstWhere('estado', $e->value)->total ?? 0);

            // Un tipo histórico solo aparece mientras tenga registros que atender/consultar.
            if ($tipo->esLegado() && (int) $delTipo->sum('total') === 0) {
                continue;
            }

            $resumen[] = [
                'clave' => $tipo->value,
                'etiqueta' => $tipo->etiqueta(),
                'recibidas' => $cuenta(EstadoSolicitudInterna::Enviada),
                'por_autorizar' => $cuenta(EstadoSolicitudInterna::EnRevision),
                'correccion' => $cuenta(EstadoSolicitudInterna::RequiereCorreccion),
                'abiertas' => $cuenta(EstadoSolicitudInterna::Enviada) + $cuenta(EstadoSolicitudInterna::EnRevision) + $cuenta(EstadoSolicitudInterna::RequiereCorreccion),
                'total' => (int) $delTipo->sum('total'),
                'ultimo_mes' => (int) $delTipo->sum('recientes'),
            ];
        }

        return $resumen;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function tiposParaFiltro(User $revisor): array
    {
        $conRegistros = $this->limitarPorAlcance(SolicitudInterna::query(), $revisor)
            ->select('tipo')->distinct()->get()
            ->map(fn (SolicitudInterna $s): string => $s->tipo->value)
            ->all();

        $tipos = array_filter(TipoSolicitudInterna::cases(), fn (TipoSolicitudInterna $t): bool => ! $t->esLegado() || in_array($t->value, $conRegistros, true));

        return array_values(array_map(fn (TipoSolicitudInterna $t): array => [
            'value' => $t->value,
            'label' => $t->esLegado() ? sprintf('%s (histórico)', $t->etiqueta()) : $t->etiqueta(),
        ], $tipos));
    }

    /**
     * Todos los tipos, con su etiqueta: también para leer registros históricos.
     *
     * @return list<array{value: string, label: string}>
     */
    public function tiposDisponibles(): array
    {
        return array_map(
            fn (TipoSolicitudInterna $tipo) => ['value' => $tipo->value, 'label' => $tipo->etiqueta()],
            TipoSolicitudInterna::cases(),
        );
    }

    /**
     * Campos del permiso, en el orden del Formato de Permiso oficial.
     *
     * @return list<array<string, mixed>>
     */
    private function camposPermiso(): array
    {
        $opciones = fn (array $casos) => array_map(fn ($c) => ['value' => $c->value, 'label' => $c->etiqueta()], $casos);

        return [
            ['name' => 'permiso_tipo', 'type' => 'opciones', 'required' => true, 'label' => 'Permiso solicitado', 'opciones' => $opciones(TipoPermisoSolicitado::cases())],
            ['name' => 'fecha_inicio', 'type' => 'date', 'required' => true, 'label' => 'Fecha del permiso'],
            ['name' => 'duracion_dias', 'type' => 'number', 'required' => true, 'label' => 'Días', 'min' => 1, 'max' => 30,
                'ayuda' => 'Días naturales consecutivos a partir de la fecha.',
                'mostrar_si' => ['campo' => 'permiso_tipo', 'valores' => [TipoPermisoSolicitado::Faltar->value]]],
            ['name' => 'hora_salida', 'type' => 'time', 'required' => true, 'label' => 'Hora de salida',
                'mostrar_si' => ['campo' => 'permiso_tipo', 'valores' => [TipoPermisoSolicitado::SalirTemprano->value]]],
            ['name' => 'hora_entrada', 'type' => 'time', 'required' => true, 'label' => 'Hora de entrada',
                'mostrar_si' => ['campo' => 'permiso_tipo', 'valores' => [TipoPermisoSolicitado::LlegarTarde->value]]],
            ['name' => 'permiso_goce', 'type' => 'opciones', 'required' => true, 'label' => 'Tipo de permiso', 'opciones' => $opciones(GocePermiso::cases())],
            ['name' => 'permiso_causal', 'type' => 'opciones', 'required' => true, 'label' => 'Causal del permiso especial', 'opciones' => $opciones(CausalPermisoEspecial::cases()),
                'mostrar_si' => ['campo' => 'permiso_goce', 'valores' => [GocePermiso::Especial->value]]],
            ['name' => 'observaciones', 'type' => 'text', 'required' => false, 'label' => 'Observaciones'],
        ];
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
        // Taxonomía definitiva: los tipos históricos nunca se ofrecen.
        $tipos = array_values(array_filter(
            TipoSolicitudInterna::cases(),
            fn (TipoSolicitudInterna $t) => $autoservicio ? $t->creableEnAutoservicio() : $t->creableComoSolicitud(),
        ));

        return array_map(function (TipoSolicitudInterna $tipo) use ($autoservicio): array {
            $modo = $tipo->modoFechas();

            if ($tipo === TipoSolicitudInterna::Permiso) {
                return [
                    'clave' => $tipo->value,
                    'nombre' => $tipo->etiqueta(),
                    'en_catalogo' => true,
                    'descripcion' => 'Faltar, salir temprano o llegar tarde.',
                    'modo_fechas' => $modo->value,
                    'modo_fechas_etiqueta' => $modo->etiqueta(),
                    'requiere_fechas' => true,
                    'requiere_horario' => false,
                    'requiere_dias' => false,
                    'requiere_monto' => false,
                    'requiere_colaborador_objetivo' => false,
                    'requiere_motivo' => false,
                    'permite_adjuntos' => true,
                    'dias_no_seleccionables' => [],
                    // Formulario dinámico (Formato de Permiso oficial): cada
                    // campo con `mostrar_si` solo aparece cuando aplica.
                    'campos' => $this->camposPermiso(),
                ];
            }

            // Solo los campos que el tipo necesita, en orden de captura.
            $campos = match ($modo) {
                ModoFechasSolicitud::Duracion => [
                    ['name' => 'fecha_inicio', 'type' => 'date', 'required' => true, 'label' => 'Fecha de inicio'],
                    ['name' => 'duracion_dias', 'type' => 'number', 'required' => true, 'label' => 'Número de días', 'min' => 1, 'max' => 365,
                        'ayuda' => 'Días naturales consecutivos (incluye sábado y domingo). La fecha de término se calcula sola.'],
                ],
                ModoFechasSolicitud::DiasEspecificos => [
                    ['name' => 'dias', 'type' => 'dates', 'required' => true, 'label' => 'Días de vacaciones', 'max' => 60,
                        'ayuda' => 'Elige los días que quieres. El domingo no cuenta como vacaciones; el sábado sí.'],
                ],
                ModoFechasSolicitud::Horario => [
                    ['name' => 'fecha_inicio', 'type' => 'date', 'required' => true, 'label' => 'Fecha'],
                ],
                ModoFechasSolicitud::FechaUnica => [
                    ['name' => 'fecha_inicio', 'type' => 'date', 'required' => true, 'label' => 'Fecha'],
                ],
                ModoFechasSolicitud::Ninguna => [],
            };

            if ($tipo->requiereMonto()) {
                $campos[] = ['name' => 'monto_solicitado', 'type' => 'number', 'required' => true, 'label' => 'Monto solicitado'];

                // App del colaborador: plazo y condiciones los decide RH al autorizar.
                if (! $autoservicio) {
                    $campos[] = ['name' => 'plazo_meses', 'type' => 'number', 'required' => false, 'label' => 'Plazo (meses)'];
                }
            }

            if ($tipo->requiereColaboradorObjetivo()) {
                $campos[] = ['name' => 'colaborador_objetivo_id', 'type' => 'select', 'required' => true, 'label' => 'Colaborador'];
            }

            $campos[] = ['name' => 'motivo', 'type' => 'text', 'required' => true, 'label' => 'Motivo'];

            if (! ($autoservicio && $tipo->requiereMonto())) {
                $campos[] = ['name' => 'observaciones', 'type' => 'text', 'required' => false, 'label' => 'Observaciones'];
            }

            return [
                'clave' => $tipo->value,
                'nombre' => $tipo->etiqueta(),
                'en_catalogo' => $tipo->enCatalogo(),
                'descripcion' => match ($tipo) {
                    TipoSolicitudInterna::Vacaciones => 'Elige los días; el domingo no cuenta.',
                    TipoSolicitudInterna::PrestamoInterno => 'Monto y motivo; RH define plazo y condiciones.',
                    TipoSolicitudInterna::ActualizacionDatos => 'Corrige o completa tus datos personales.',
                    default => '',
                },
                'modo_fechas' => $modo->value,
                'modo_fechas_etiqueta' => $modo->etiqueta(),
                // Banderas que ya leían clientes anteriores (se conservan).
                'requiere_fechas' => $tipo->usaRangoFechas(),
                'requiere_horario' => $tipo->usaHorario(),
                'requiere_dias' => $tipo->requiereDias(),
                'requiere_monto' => $tipo->requiereMonto(),
                'requiere_colaborador_objetivo' => $tipo->requiereColaboradorObjetivo(),
                'requiere_motivo' => true,
                'permite_adjuntos' => ! ($autoservicio && $tipo->requiereMonto()),
                // Vacaciones: días de la semana que no se pueden elegir (0 = domingo).
                'dias_no_seleccionables' => $modo === ModoFechasSolicitud::DiasEspecificos
                    ? array_values(array_map('intval', (array) config('vacaciones.dias_no_computables', [0])))
                    : [],
                'campos' => $campos,
            ];
        }, $tipos);
    }
}
