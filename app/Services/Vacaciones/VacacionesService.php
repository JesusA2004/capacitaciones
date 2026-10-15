<?php

namespace App\Services\Vacaciones;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoSolicitudVacaciones;
use App\Enums\TipoSolicitudInterna;
use App\Models\AjusteHistoricoVacaciones;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Notifications\Mobile\RhVacacionCreadaNotification;
use App\Notifications\Mobile\VacacionActualizadaNotification;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\RhMobile\ResponsableResolverService;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Calcula el saldo de vacaciones de un colaborador segun su antiguedad y la
 * tabla legal configurable (config/vacaciones.php). No hay una tabla de
 * "saldos" persistida: los dias generados se calculan a partir de
 * fecha_ingreso, y los usados/en solicitud se agregan desde
 * solicitudes_vacaciones — igual criterio de "vista calculada" que el
 * expediente digital. aprobar()/rechazar() son la unica fuente de esa
 * transicion: tanto App\Http\Controllers\Rh\VacacionesController (web) como
 * App\Http\Controllers\Api\V1\Rh\VacacionController (app movil) llaman
 * aqui, nunca actualizan el modelo por su cuenta.
 */
class VacacionesService
{
    public function __construct(
        private readonly ResponsableResolverService $responsables,
        private readonly NotificacionEventosService $eventos,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * @return array{
     *     antiguedad_anios: int,
     *     vigencia_inicio: string|null,
     *     vigencia_fin: string|null,
     *     dias_generados: int,
     *     dias_usados: int,
     *     dias_usados_people: int,
     *     dias_usados_historicos: float,
     *     dias_usados_total: float,
     *     dias_en_solicitud: int,
     *     dias_ajustes_historicos: float,
     *     ajustes_a_favor: float,
     *     dias_disponibles: int,
     *     puede_solicitar: bool,
     *     motivo_no_disponible: string|null,
     *     fecha_disponible_desde: string|null,
     *     fecha_ingreso: string|null,
     *     primer_aniversario: string|null,
     *     antiguedad_meses: int,
     *     antiguedad_texto: string,
     * }
     */
    public function saldo(User $colaborador): array
    {
        if ($colaborador->colaborador === null) {
            return $this->saldoVacio();
        }

        return $this->saldoColaborador($colaborador->colaborador);
    }

    /**
     * Igual que saldo(), pero identificando al colaborador directamente por
     * su expediente (App\Models\Colaborador) en vez de por su cuenta de
     * acceso — un Colaborador sin User (todavía no tiene alta digital
     * completa, o nunca necesitó entrar a la app) debe poder tener saldo de
     * vacaciones para efectos de finiquito (ver
     * App\Services\Finiquitos\FiniquitoService::calcularAutomaticos()). Si
     * el colaborador sí tiene cuenta, también cuenta lo que haya solicitado
     * por el flujo de autoservicio (solicitudes_vacaciones/solicitudes_internas
     * siguen ligadas a `user_id`, no a `colaborador_id` — deuda técnica
     * documentada aquí mismo, Parte B pendiente).
     *
     * @return array{
     *     antiguedad_anios: int,
     *     vigencia_inicio: string|null,
     *     vigencia_fin: string|null,
     *     dias_generados: int,
     *     dias_usados: int,
     *     dias_usados_people: int,
     *     dias_usados_historicos: float,
     *     dias_usados_total: float,
     *     dias_en_solicitud: int,
     *     dias_ajustes_historicos: float,
     *     ajustes_a_favor: float,
     *     dias_disponibles: int,
     *     puede_solicitar: bool,
     *     motivo_no_disponible: string|null,
     *     fecha_disponible_desde: string|null,
     *     fecha_ingreso: string|null,
     *     primer_aniversario: string|null,
     *     antiguedad_meses: int,
     *     antiguedad_texto: string,
     * }
     */
    public function saldoColaborador(Colaborador $colaborador): array
    {
        $fechaIngreso = $colaborador->fecha_ingreso;

        if ($fechaIngreso === null) {
            return array_merge($this->saldoVacio(), [
                'motivo_no_disponible' => 'Fecha de ingreso pendiente de validación por RH.',
            ]);
        }

        // Sin prorrateo: 0 días hasta el día exacto del aniversario (fecha
        // calendario de México), nunca antes.
        $ingreso = Carbon::parse($fechaIngreso->toDateString());
        $hoy = self::hoyMexico();
        $antiguedadAnios = $hoy->lt($ingreso) ? 0 : (int) $ingreso->diffInYears($hoy);
        $antiguedadMeses = $hoy->lt($ingreso) ? 0 : (int) $ingreso->diffInMonths($hoy);

        $vigenciaInicio = $ingreso->copy()->addYears($antiguedadAnios);
        $vigenciaFin = $vigenciaInicio->copy()->addYear()->subDay();

        $diasGenerados = $this->diasPorAntiguedad($antiguedadAnios);

        $userId = $colaborador->user?->id;

        // colaborador_id es la fuente real (un Colaborador sin User debe
        // poder tener saldo de vacaciones — finiquito, expediente, etc.);
        // user_id se conserva como fallback para solicitudes legacy
        // creadas antes de que colaborador_id empezara a llenarse (ver
        // SolicitudesService::crear() y VacacionesService::solicitar()).
        $porIdentidad = function (Builder $q) use ($colaborador, $userId): void {
            $q->where('colaborador_id', $colaborador->id);

            if ($userId !== null) {
                $q->orWhere('user_id', $userId);
            }
        };

        // Cuenta dias tanto del modulo legacy (solicitudes_vacaciones, ver
        // App\Http\Controllers\VacacionesController — se conserva como
        // endpoint legacy, ver seccion 13 de la reestructuracion) como del
        // modulo unificado (solicitudes_internas con tipo=vacaciones, ver
        // App\Services\Solicitudes\SolicitudesService::crear()). Nunca se
        // debe poder rebasar el saldo solicitando por cualquiera de los dos
        // caminos.
        $solicitudesLegacyVigentes = SolicitudVacaciones::query()
            ->where($porIdentidad)
            ->whereBetween('fecha_inicio', [$vigenciaInicio, $vigenciaFin])
            ->get();

        $diasUsadosLegacy = (int) $solicitudesLegacyVigentes
            ->where('estado', EstadoSolicitudVacaciones::Aprobada)
            ->sum('dias_solicitados');

        $diasEnSolicitudLegacy = (int) $solicitudesLegacyVigentes
            ->where('estado', EstadoSolicitudVacaciones::Pendiente)
            ->sum('dias_solicitados');

        $solicitudesInternasVigentes = SolicitudInterna::query()
            ->where($porIdentidad)
            ->where('tipo', TipoSolicitudInterna::Vacaciones)
            ->whereBetween('fecha_inicio', [$vigenciaInicio, $vigenciaFin])
            ->get();

        $diasUsadosInternas = (int) $solicitudesInternasVigentes
            ->where('estado', EstadoSolicitudInterna::Aprobada)
            ->sum('dias_solicitados');

        $diasEnSolicitudInternas = (int) $solicitudesInternasVigentes
            ->whereIn('estado', [
                EstadoSolicitudInterna::Creada,
                EstadoSolicitudInterna::Enviada,
                EstadoSolicitudInterna::EnRevision,
                EstadoSolicitudInterna::RequiereCorreccion,
            ])
            ->sum('dias_solicitados');

        $diasUsados = $diasUsadosLegacy + $diasUsadosInternas;
        $diasEnSolicitud = $diasEnSolicitudLegacy + $diasEnSolicitudInternas;

        // Ajustes históricos (importador de Excel, CLAUDE.md §24-35): solo
        // los que caen dentro de ESTA vigencia — un ajuste de un año de
        // servicio ya cerrado no debe seguir restando saldo para siempre.
        // Nunca se acreditan ajustes positivos a quien aún no cumple 1 año
        // (dias_generados ya es 0 en ese caso; sumar un ajuste adelantaría
        // el bloque anual antes de tiempo). Un ajuste NEGATIVO es consumo
        // histórico (vacaciones ya tomadas antes de existir PEOPLE, p. ej.
        // importadas de un Excel de rotación); uno POSITIVO es a favor del
        // colaborador — nunca se mezclan en un solo número "usado" (cierre
        // de producto, caso real: Luz Elena con -9 días históricos se veía
        // como "Usados: 0" en vez de "Usados: 9 (9 históricos)").
        $ajustesHistoricosQuery = AjusteHistoricoVacaciones::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('fecha_corte', '>=', $vigenciaInicio->toDateString())
            ->whereDate('fecha_corte', '<=', $vigenciaFin->toDateString());

        $ajustesAFavor = $antiguedadAnios >= 1 ? (float) (clone $ajustesHistoricosQuery)->where('dias', '>', 0)->sum('dias') : 0.0;
        $diasUsadosHistoricos = $antiguedadAnios >= 1 ? abs((float) (clone $ajustesHistoricosQuery)->where('dias', '<', 0)->sum('dias')) : 0.0;
        $diasAjustesHistoricos = $ajustesAFavor - $diasUsadosHistoricos;
        $diasUsadosTotal = $diasUsados + $diasUsadosHistoricos;

        // Primer año (CLAUDE.md §49-53): antes de cumplirlo, NUNCA se puede
        // iniciar el wizard de solicitud, aunque el saldo calculado diera 0
        // por otra razón — el mensaje debe distinguir "todavía no tienes
        // derecho" de "ya usaste tu saldo" (sección 52).
        $cumpleUnAnio = $antiguedadAnios >= 1;

        return [
            'antiguedad_anios' => $antiguedadAnios,
            'vigencia_inicio' => $vigenciaInicio->toDateString(),
            'vigencia_fin' => $vigenciaFin->toDateString(),
            'dias_generados' => $diasGenerados,
            // 'dias_usados' se conserva (solo PEOPLE) por compatibilidad de
            // consumidores existentes — el desglose completo vive en los
            // campos nuevos; la UI debe mostrar dias_usados_total como el
            // "Usados" visible, con el desglose debajo.
            'dias_usados' => $diasUsados,
            'dias_usados_people' => $diasUsados,
            'dias_usados_historicos' => $diasUsadosHistoricos,
            'dias_usados_total' => $diasUsadosTotal,
            'dias_en_solicitud' => $diasEnSolicitud,
            'dias_ajustes_historicos' => $diasAjustesHistoricos,
            'ajustes_a_favor' => $ajustesAFavor,
            'dias_disponibles' => max(0, (int) floor($diasGenerados + $diasAjustesHistoricos - $diasUsados - $diasEnSolicitud)),
            'puede_solicitar' => $cumpleUnAnio,
            'motivo_no_disponible' => $cumpleUnAnio
                ? null
                : sprintf('Todavía no tienes vacaciones disponibles. Podrás solicitarlas al cumplir tu primer año el %s.', $ingreso->copy()->addYear()->translatedFormat('d/m/Y')),
            'fecha_disponible_desde' => $cumpleUnAnio ? null : $ingreso->copy()->addYear()->toDateString(),
            'fecha_ingreso' => $ingreso->toDateString(),
            'primer_aniversario' => $ingreso->copy()->addYear()->toDateString(),
            'antiguedad_meses' => $antiguedadMeses,
            'antiguedad_texto' => self::textoAntiguedad($antiguedadAnios, $antiguedadMeses),
        ];
    }

    /**
     * @return array{
     *     antiguedad_anios: int,
     *     vigencia_inicio: string|null,
     *     vigencia_fin: string|null,
     *     dias_generados: int,
     *     dias_usados: int,
     *     dias_usados_people: int,
     *     dias_usados_historicos: float,
     *     dias_usados_total: float,
     *     dias_en_solicitud: int,
     *     dias_ajustes_historicos: float,
     *     ajustes_a_favor: float,
     *     dias_disponibles: int,
     *     puede_solicitar: bool,
     *     motivo_no_disponible: string|null,
     *     fecha_disponible_desde: string|null,
     *     fecha_ingreso: string|null,
     *     primer_aniversario: string|null,
     *     antiguedad_meses: int,
     *     antiguedad_texto: string,
     * }
     */
    private function saldoVacio(): array
    {
        return [
            'antiguedad_anios' => 0,
            'vigencia_inicio' => null,
            'vigencia_fin' => null,
            'dias_generados' => 0,
            'dias_usados' => 0,
            'dias_usados_people' => 0,
            'dias_usados_historicos' => 0.0,
            'dias_usados_total' => 0.0,
            'dias_en_solicitud' => 0,
            'dias_ajustes_historicos' => 0.0,
            'ajustes_a_favor' => 0.0,
            'dias_disponibles' => 0,
            'puede_solicitar' => false,
            'motivo_no_disponible' => 'Tu cuenta no tiene un expediente de colaborador vinculado.',
            'fecha_disponible_desde' => null,
            'fecha_ingreso' => null,
            'primer_aniversario' => null,
            'antiguedad_meses' => 0,
            'antiguedad_texto' => 'Sin fecha de ingreso',
        ];
    }

    /**
     * «Hoy» para vacaciones: la fecha calendario de America/Mexico_City
     * expresada como medianoche del mismo huso que fecha_ingreso (cast
     * `date`), para que diffInYears compare días completos. La app corre en
     * UTC: con Carbon::now() a partir de las 18:00 de México ya contaba el
     * día siguiente (el aniversario llegaba horas antes).
     */
    public static function hoyMexico(): Carbon
    {
        return Carbon::parse(Carbon::now('America/Mexico_City')->toDateString());
    }

    private static function textoAntiguedad(int $anios, int $meses): string
    {
        if ($anios >= 1) {
            return $anios === 1 ? '1 año' : sprintf('%d años', $anios);
        }

        $meses = max(0, $meses);

        return sprintf('%d %s · Aún no cumple un año', $meses, $meses === 1 ? 'mes' : 'meses');
    }

    /**
     * @return Collection<int, SolicitudVacaciones>
     */
    public function misSolicitudes(User $colaborador): Collection
    {
        return SolicitudVacaciones::query()
            ->where(function (Builder $q) use ($colaborador): void {
                $q->where('user_id', $colaborador->id);

                if ($colaborador->colaborador_id !== null) {
                    $q->orWhere('colaborador_id', $colaborador->colaborador_id);
                }
            })
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Crea una solicitud de vacaciones validando que el colaborador tenga
     * saldo suficiente. Usado por el controlador web (Inertia) y por la API
     * movil (Api\V1\VacacionesController) — misma logica, un solo lugar.
     *
     * @param  array<string, mixed>  $datos  Validado por StoreSolicitudVacacionesRequest: fecha_inicio, fecha_fin, dias_solicitados, comentario?.
     *
     * @throws ValidationException
     */
    public function solicitar(User $colaborador, array $datos): SolicitudVacaciones
    {
        $saldo = $this->saldo($colaborador);

        if (! $saldo['puede_solicitar']) {
            throw ValidationException::withMessages([
                'dias_solicitados' => $saldo['motivo_no_disponible'] ?? 'Todavía no puedes solicitar vacaciones.',
            ]);
        }

        if ($datos['dias_solicitados'] > $saldo['dias_disponibles']) {
            throw ValidationException::withMessages([
                'dias_solicitados' => 'No tienes suficientes días disponibles.',
            ]);
        }

        $solicitud = SolicitudVacaciones::create([
            ...$datos,
            'user_id' => $colaborador->id,
            'colaborador_id' => $colaborador->colaborador_id,
            'estado' => EstadoSolicitudVacaciones::Pendiente,
        ]);

        $this->notificarSinFallar(function () use ($solicitud, $colaborador): void {
            $responsables = $this->responsables->paraColaborador($colaborador, 'rh.vacaciones.aprobar');

            $this->eventos->registrarConPendiente(
                $colaborador,
                new Aviso('vacaciones_solicitadas', 'vacaciones', 'Vacaciones solicitadas', sprintf('Pediste %d día(s) de vacaciones; quedó en revisión.', (int) $solicitud->dias_solicitados), $solicitud, 'registrada'),
                $responsables,
                new Aviso('vacaciones_solicitadas', 'rh_vacaciones', 'Nueva solicitud de vacaciones', 'Un colaborador solicitó vacaciones.', $solicitud, 'revisar_vacaciones', 'media', [], null, new RhVacacionCreadaNotification($solicitud)),
            );
        });

        return $solicitud;
    }

    /**
     * Aprueba/rechaza una solicitud de vacaciones: unica fuente de esta
     * transicion (ver docstring de la clase). Notifica al colaborador y
     * encola push (type=vacaciones), sin poder tumbar la aprobacion/rechazo
     * si el envio falla.
     */
    public function aprobar(SolicitudVacaciones $solicitud, User $actor): SolicitudVacaciones
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudVacaciones::Aprobada);
    }

    public function rechazar(SolicitudVacaciones $solicitud, User $actor, string $motivoRechazo): SolicitudVacaciones
    {
        return $this->cambiarEstado($solicitud, $actor, EstadoSolicitudVacaciones::Rechazada, $motivoRechazo);
    }

    private function cambiarEstado(SolicitudVacaciones $solicitud, User $actor, EstadoSolicitudVacaciones $nuevoEstado, ?string $motivoRechazo = null): SolicitudVacaciones
    {
        return DB::transaction(function () use ($solicitud, $actor, $nuevoEstado, $motivoRechazo): SolicitudVacaciones {
            $datos = [
                'estado' => $nuevoEstado,
                'revisado_por' => $actor->id,
                'revisado_en' => now(),
            ];

            if ($motivoRechazo !== null) {
                $datos['motivo_rechazo'] = $motivoRechazo;
            }

            $solicitud->update($datos);
            $solicitud->refresh();

            $this->notificarSinFallar(function () use ($solicitud): void {
                $solicitud->loadMissing('usuario');
                $this->eventos->avisarResultado($solicitud->usuario, new Aviso('vacaciones_actualizadas', 'vacaciones', 'Actualización de tus vacaciones', "Tu solicitud de vacaciones ahora está: {$solicitud->estado->etiqueta()}.", $solicitud, $solicitud->estado->value, 'media', [], null, new VacacionActualizadaNotification($solicitud)));
            });

            return $solicitud;
        });
    }

    /**
     * Un fallo al notificar nunca debe deshacer la accion principal, que ya
     * quedo persistida antes de llamar aqui: solo se registra en el log.
     */
    private function notificarSinFallar(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('VacacionesService: fallo al notificar', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Listado de revision de RH/gerencia, acotado por alcance organizacional
     * y filtros opcionales. Usado tanto por el Portal RH web
     * (App\Http\Controllers\Rh\VacacionesController) como por la app movil
     * (App\Http\Controllers\Api\V1\Rh\VacacionController) — misma logica.
     *
     * @param  array<string, mixed>  $filtros  estado, empresa_id, sucursal_id, revisado_por, busqueda, fecha_inicio, fecha_fin
     * @return LengthAwarePaginator<int, SolicitudVacaciones>
     */
    public function paraRevision(User $revisor, array $filtros = []): LengthAwarePaginator
    {
        return $this->queryRevision($revisor, $filtros)->orderByDesc('created_at')->paginate(Paginacion::porPagina())->withQueryString();
    }

    /**
     * Mismo filtrado que paraRevision(), sin paginar (usado por las
     * exportaciones Excel/PDF).
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, SolicitudVacaciones>
     */
    public function paraExportar(User $revisor, array $filtros = []): Collection
    {
        return $this->queryRevision($revisor, $filtros)->orderByDesc('created_at')->get();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<SolicitudVacaciones>
     */
    private function queryRevision(User $revisor, array $filtros = []): Builder
    {
        $colaboradoresPermitidos = $this->alcance->limitarPorModulo(Colaborador::query(), $revisor, 'vacaciones', 'id');
        $idsColaboradoresPermitidos = (clone $colaboradoresPermitidos)->select('id');
        $idsUsuariosPermitidos = User::query()->whereIn('colaborador_id', (clone $colaboradoresPermitidos)->select('id'))->orWhere('id', $revisor->id)->select('id');

        return SolicitudVacaciones::query()
            ->with([
                'colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id',
                'colaborador.sucursalPrincipal:id,nombre',
                'usuario:id,name,apellidos,colaborador_id',
                'usuario.colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id',
                'usuario.colaborador.sucursalPrincipal:id,nombre',
                'revisadoPor:id,name,apellidos',
            ])
            // Alcance del módulo «Vacaciones» (Roles y permisos).
            ->where(fn (Builder $q) => $q
                ->whereIn('user_id', $idsUsuariosPermitidos)
                ->orWhereIn('colaborador_id', $idsColaboradoresPermitidos))
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $sub) => $sub
                ->whereHas('colaborador.sucursalPrincipal', fn ($s) => $s->where('empresa_id', $v))
                ->orWhereHas('usuario.colaborador.sucursalPrincipal', fn ($s) => $s->where('empresa_id', $v))))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $sub) => $sub
                ->whereHas('colaborador', fn ($s) => $s->where('sucursal_principal_id', $v))
                ->orWhereHas('usuario.colaborador', fn ($s) => $s->where('sucursal_principal_id', $v))))
            ->when($filtros['revisado_por'] ?? null, fn (Builder $q, $v) => $q->where('revisado_por', $v))
            ->when($filtros['estado'] ?? null, fn (Builder $q, $v) => $q->where('estado', $v))
            ->when($filtros['fecha_inicio'] ?? null, fn (Builder $q, $v) => $q->whereDate('fecha_inicio', '>=', $v))
            ->when($filtros['fecha_fin'] ?? null, fn (Builder $q, $v) => $q->whereDate('fecha_fin', '<=', $v))
            ->when($filtros['busqueda'] ?? null, function (Builder $q, string $busqueda): void {
                $q->where(function (Builder $sub) use ($busqueda): void {
                    $sub->whereHas('colaborador', function ($s) use ($busqueda): void {
                        $s->where('name', 'like', "%{$busqueda}%")->orWhere('apellidos', 'like', "%{$busqueda}%");
                    })->orWhereHas('usuario', function ($s) use ($busqueda): void {
                        $s->where('name', 'like', "%{$busqueda}%")->orWhere('apellidos', 'like', "%{$busqueda}%");
                    });
                });
            });
    }

    /**
     * Dias generados para un numero de anios completos de antiguedad, segun
     * la tabla legal configurable. Menos de 1 anio completo = 0 dias.
     */
    public function diasPorAntiguedad(int $antiguedadAnios): int
    {
        if ($antiguedadAnios < 1) {
            return 0;
        }

        /** @var array<int, int> $tabla */
        $tabla = config('vacaciones.tabla_dias', []);
        $ultimoAnioTabla = max(array_keys($tabla) ?: [0]);

        if ($antiguedadAnios <= $ultimoAnioTabla) {
            return $tabla[$antiguedadAnios];
        }

        $base = $tabla[$ultimoAnioTabla];
        $aniosPorBloque = (int) config('vacaciones.anios_por_bloque');
        $incrementoPorBloque = (int) config('vacaciones.incremento_por_bloque');
        $bloques = (int) ceil(($antiguedadAnios - $ultimoAnioTabla) / $aniosPorBloque);

        return $base + ($bloques * $incrementoPorBloque);
    }
}
