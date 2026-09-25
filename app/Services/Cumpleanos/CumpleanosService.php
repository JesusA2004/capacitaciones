<?php

namespace App\Services\Cumpleanos;

use App\Enums\EstadoUsuario;
use App\Models\BirthdayGreeting;
use App\Models\Colaborador;
use App\Models\User;
use App\Notifications\Mobile\BirthdayGreetingNotification;
use App\Notifications\Mobile\BirthdayRhReminderNotification;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Celebraciones\FechasCelebracion;
use App\Services\MobilePush\PushNotifier;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta el modulo de cumpleanos: listados/calendario (Rh\CumpleanosController),
 * calculo de edad y notificaciones automaticas (commands cumpleanos:*). La
 * generacion de la tarjeta/imagen y el registro BirthdayGreeting en si viven
 * en App\Services\Cumpleanos\BirthdayCardService; este service la invoca
 * cuando corresponde, nunca duplica esa logica.
 *
 * El "colaborador" de este módulo (fecha_nacimiento, foto, sucursal, etc.)
 * es siempre App\Models\Colaborador — puede o no tener cuenta de acceso; las
 * notificaciones (in-app/push) solo se envían cuando sí la tiene.
 */
class CumpleanosService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly BirthdayCardService $tarjetas,
        private readonly PushNotifier $push,
    ) {}

    /**
     * Colaboradores activos con cumpleanos en el mes indicado, dentro del
     * alcance organizacional de quien consulta ($usuario = null => sin
     * restriccion, usado por los commands del scheduler que operan a nivel
     * de toda la organizacion). $filtros admite: sucursal_id,
     * departamento_id, estatus, busqueda.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Colaborador>
     */
    public function cumpleanosDelMes(int $mes, ?User $usuario, array $filtros = []): Collection
    {
        // Orden por dia en PHP (no SQL) porque DAY() no es portable entre
        // MariaDB (produccion) y SQLite (tests, ver phpunit.xml); el volumen
        // por mes es siempre acotado, asi que ordenar en memoria es barato.
        return $this->queryBase($usuario, $filtros)
            ->whereMonth('fecha_nacimiento', $mes)
            ->get()
            ->sortBy(fn (Colaborador $colaborador) => (int) $colaborador->fecha_nacimiento->format('d'))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Colaborador>
     */
    public function cumpleanosDeHoy(?User $usuario = null, array $filtros = []): Collection
    {
        $hoy = FechasCelebracion::hoy();

        // Nacidos un 29/feb celebran el 28/feb en años no bisiestos.
        return $this->queryBase($usuario, $filtros)
            ->whereMonth('fecha_nacimiento', $hoy->month)
            ->get()
            ->filter(fn (Colaborador $colaborador) => $colaborador->fecha_nacimiento !== null && FechasCelebracion::esHoy($colaborador->fecha_nacimiento, $hoy))
            ->values();
    }

    /**
     * Colaboradores cuyo proximo cumpleanos cae dentro de los proximos $dias
     * (inclusive de hoy), ordenados por cercania. Calculado en PHP porque
     * "proximos N dias" puede cruzar el limite de anio (31-dic -> 5-ene) y
     * eso es mas simple de expresar aqui que con SQL de fecha portatil entre
     * motores.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Colaborador>
     */
    public function proximosCumpleanos(?User $usuario, int $dias, array $filtros = []): Collection
    {
        $hoy = FechasCelebracion::hoy();

        return $this->queryBase($usuario, $filtros)
            ->get()
            ->map(function (Colaborador $colaborador) use ($hoy) {
                $colaborador->setAttribute('_proxima_fecha', $this->proximaFecha($colaborador->fecha_nacimiento, $hoy));

                return $colaborador;
            })
            ->filter(fn (Colaborador $colaborador) => $hoy->diffInDays($colaborador->getAttribute('_proxima_fecha'), false) <= $dias)
            ->sortBy(fn (Colaborador $colaborador) => $colaborador->getAttribute('_proxima_fecha')->timestamp)
            ->values();
    }

    /**
     * Colaboradores cuyo próximo cumpleaños cae dentro de [$desde, $hasta]
     * (ambos inclusive) — el mini-calendario de rango libre del panel RH,
     * en vez de los botones fijos de "7 días"/"30 días". Misma lógica de
     * "próxima fecha" que proximosCumpleanos(), pero calculada desde
     * $desde en vez de siempre desde hoy, para que un rango que empieza en
     * el futuro también funcione.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Colaborador>
     */
    public function cumpleanosEnRango(?User $usuario, CarbonInterface $desde, CarbonInterface $hasta, array $filtros = []): Collection
    {
        $desde = Carbon::parse($desde)->startOfDay();
        $hasta = Carbon::parse($hasta)->endOfDay();

        return $this->queryBase($usuario, $filtros)
            ->get()
            ->map(function (Colaborador $colaborador) use ($desde) {
                $colaborador->setAttribute('_proxima_fecha', $this->proximaFecha($colaborador->fecha_nacimiento, $desde));

                return $colaborador;
            })
            ->filter(fn (Colaborador $colaborador) => $colaborador->getAttribute('_proxima_fecha')->between($desde, $hasta))
            ->sortBy(fn (Colaborador $colaborador) => $colaborador->getAttribute('_proxima_fecha')->timestamp)
            ->values();
    }

    /**
     * Colaboradores elegibles para el selector de "Colaborador" del panel
     * RH: mismo alcance y filtros de sucursal/departamento/estatus que el
     * resto de la pantalla, pero sin colaborador_id ni busqueda — así el
     * desplegable siempre ofrece el universo completo para elegir, sin
     * auto-acotarse por la propia selección o por el texto libre de otro
     * campo. Ver docs del combobox en Rh/Cumpleanos/Index.vue.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Colaborador>
     */
    public function colaboradoresElegibles(?User $usuario, array $filtros): Collection
    {
        $filtrosAcotados = collect($filtros)->only(['sucursal_id', 'departamento_id', 'estatus'])->all();

        return $this->queryBase($usuario, $filtrosAcotados, requiereFechaNacimiento: false)->orderBy('name')->get();
    }

    /**
     * Colaboradores activos (dentro del alcance) sin fecha_nacimiento
     * capturada: alimenta la metrica "Sin fecha de nacimiento" y la alerta
     * del panel RH para que se complete el dato en el expediente en vez de
     * que el colaborador "desaparezca" en silencio del modulo.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Colaborador>
     */
    public function sinFechaNacimiento(?User $usuario, array $filtros = []): Collection
    {
        $filtrosAcotados = collect($filtros)->only(['sucursal_id', 'departamento_id', 'estatus'])->all();

        return $this->queryBase($usuario, $filtrosAcotados, requiereFechaNacimiento: false)
            ->whereNull('fecha_nacimiento')
            ->orderBy('name')
            ->get();
    }

    /**
     * Dispatcher usado por la API movil de RH (GET /api/v1/rh/cumpleanos):
     * traduce el parametro `periodo` de la app al metodo de listado
     * correspondiente, todos ya acotados por alcance organizacional y
     * filtros. `mes` solo aplica (y es requerido) cuando periodo=mes.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Colaborador>
     */
    public function colaboradoresPorPeriodo(string $periodo, ?int $mes, User $usuario, array $filtros = []): Collection
    {
        return match ($periodo) {
            'hoy' => $this->cumpleanosDeHoy($usuario, $filtros),
            '7_dias' => $this->proximosCumpleanos($usuario, 7, $filtros),
            '30_dias' => $this->proximosCumpleanos($usuario, 30, $filtros),
            default => $this->cumpleanosDelMes($mes ?? Carbon::today()->month, $usuario, $filtros),
        };
    }

    /**
     * Fecha del cumpleanos del colaborador en el anio en curso (mismo mes y
     * dia que fecha_nacimiento). Usada por el panel RH para generar/ver la
     * felicitacion de "este anio" bajo demanda, sin esperar al command
     * diario. Null si el colaborador no tiene fecha_nacimiento capturada.
     */
    public function fechaEsteAnio(Colaborador $colaborador): ?CarbonInterface
    {
        if ($colaborador->fecha_nacimiento === null) {
            return null;
        }

        return FechasCelebracion::ocurrenciaEn($colaborador->fecha_nacimiento, FechasCelebracion::hoy()->year);
    }

    public function calcularEdad(Colaborador $colaborador, ?CarbonInterface $enFecha = null): ?int
    {
        if ($colaborador->fecha_nacimiento === null) {
            return null;
        }

        return (int) $colaborador->fecha_nacimiento->diffInYears($enFecha ?? FechasCelebracion::hoy());
    }

    /**
     * Payload agrupado por dia del mes para el calendario mensual. Nunca
     * incluye el anio de nacimiento (solo dia/mes); la edad solo se agrega
     * si config('cumpleanos.show_age') esta activo.
     *
     * $anio se usa unicamente para resolver el caso de quien nacio un 29 de
     * febrero: en un anio no bisiesto ese dia no existe en el calendario que
     * ve RH, asi que su cumpleanos se muestra el 28 de febrero (convencion
     * usual de RH) en vez de desaparecer del calendario.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function payloadCalendario(int $anio, int $mes, ?User $usuario, array $filtros = []): array
    {
        $colaboradores = $this->cumpleanosDelMes($mes, $usuario, $filtros);
        $mostrarEdad = (bool) config('cumpleanos.show_age');
        $esBisiesto = $mes === 2 && Carbon::create($anio)->isLeapYear();

        $porDia = [];

        foreach ($colaboradores as $colaborador) {
            $dia = (int) $colaborador->fecha_nacimiento->format('d');

            if ($dia === 29 && $mes === 2 && ! $esBisiesto) {
                $dia = 28;
            }

            $porDia[$dia] ??= [];
            $porDia[$dia][] = $this->tarjetaColaborador($colaborador, $mostrarEdad, $usuario);
        }

        ksort($porDia);

        return $porDia;
    }

    /**
     * @return array<string, mixed>
     */
    public function tarjetaColaborador(Colaborador $colaborador, ?bool $mostrarEdad = null, ?User $solicitante = null): array
    {
        $mostrarEdad ??= (bool) config('cumpleanos.show_age');

        // Misma ruta protegida por permiso que usa el explorador de
        // expedientes (Rh\ExpedienteController::descargarFoto): nunca se
        // expone foto_path, solo esta URL si quien consulta tiene permiso.
        $fotoUrl = $colaborador->foto_path !== null && $solicitante?->can('expedientes.ver')
            ? route('rh.expedientes.foto', $colaborador)
            : null;

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'departamento' => $colaborador->departamento?->nombre,
            'puesto' => $colaborador->puesto?->nombre,
            'estatus' => $colaborador->estatus->value,
            'dia' => (int) $colaborador->fecha_nacimiento->format('d'),
            'mes' => (int) $colaborador->fecha_nacimiento->format('m'),
            'edad' => $mostrarEdad ? $this->calcularEdad($colaborador) : null,
            'tiene_foto' => $colaborador->foto_path !== null,
            'foto_url' => $fotoUrl,
        ];
    }

    /**
     * Genera (si no existe) la felicitacion de hoy y notifica al
     * colaborador (solo si tiene cuenta de acceso): notificacion in-app +
     * push si tiene dispositivo. No duplica si ya se notifico este anio/dia
     * (BirthdayGreeting.enviada_at). Cualquier fallo en tarjeta/push se
     * registra en log y no interrumpe el resto del lote (usado por el
     * command diario).
     */
    public function felicitarColaborador(Colaborador $colaborador, CarbonInterface $fecha): BirthdayGreeting
    {
        $greeting = $this->tarjetas->generar($colaborador, $fecha);

        if ($greeting->enviada_at !== null) {
            return $greeting;
        }

        if ((bool) config('cumpleanos.notify_employee')) {
            $this->notificarColaborador($greeting, $colaborador);
        }

        $greeting->update(['enviada_at' => now()]);

        return $greeting;
    }

    /**
     * Envio manual desde el panel RH (permiso rh.cumpleanos.notificaciones.gestionar):
     * a diferencia de felicitarColaborador() (usado por el command diario),
     * siempre notifica aunque ya se haya enviado antes — es una accion
     * explicita de RH, no el envio automatico.
     */
    public function reenviarManual(Colaborador $colaborador, User $ejecutor): BirthdayGreeting
    {
        $fecha = $this->fechaEsteAnio($colaborador) ?? FechasCelebracion::hoy();
        $greeting = $this->tarjetas->generar($colaborador, $fecha);

        $this->notificarColaborador($greeting, $colaborador);

        $greeting->update(['enviada_at' => now(), 'enviada_por_id' => $ejecutor->id, 'auto_generada' => false]);

        return $greeting;
    }

    public function notificarColaborador(BirthdayGreeting $greeting, ?Colaborador $colaborador = null): void
    {
        $colaborador ??= $greeting->colaborador;
        $cuenta = $colaborador->user;

        if ($cuenta === null) {
            // Sin cuenta de acceso no hay a quién notificar in-app/push; la
            // tarjeta/felicitación sigue existiendo y es visible en el panel
            // RH igual.
            return;
        }

        try {
            $cuenta->notify(new BirthdayGreetingNotification($greeting));

            $this->push->aUsuario(
                $cuenta,
                'cumpleanos',
                $greeting->id,
                '¡Feliz cumpleaños!',
                'Tenemos una felicitación especial para ti en MR. LANA PEOPLE.',
            );
        } catch (\Throwable $e) {
            Log::error('cumpleanos: fallo al notificar al colaborador', [
                'colaborador_id' => $colaborador->id,
                'greeting_id' => $greeting->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recordatorio a RH/admin (rh.cumpleanos.ver): cumpleanos de hoy y de
     * los proximos 7 dias, calculados **por destinatario** dentro de su
     * propio alcance organizacional (nunca se asume alcance global solo por
     * tener el permiso — un rol puede reconfigurarse desde Administracion >
     * Roles). Si un destinatario no tiene ningun cumpleanos dentro de lo
     * suyo, no se le notifica. No incluye fecha completa de nacimiento.
     *
     * Idempotente por destinatario/dia (cache con TTL hasta medianoche):
     * correr el command dos veces el mismo dia no duplica el recordatorio.
     */
    public function notificarRh(): void
    {
        if (! (bool) config('cumpleanos.notify_rh')) {
            return;
        }

        $destinatarios = User::role(['super_admin', 'rh_admin', 'rh_auxiliar'])
            ->whereHas('colaborador', fn (Builder $q) => $q->where('estatus', EstadoUsuario::Activo->value))
            ->get()
            ->filter(fn (User $u) => $u->can('rh.cumpleanos.ver'));

        foreach ($destinatarios as $destinatario) {
            $hoy = $this->cumpleanosDeHoy($destinatario);
            $proximos7 = $this->proximosCumpleanos($destinatario, 7);

            if ($hoy->isEmpty() && $proximos7->isEmpty()) {
                continue;
            }

            $claveDedup = 'cumpleanos:recordatorio_rh:'.$destinatario->id.':'.Carbon::today()->toDateString();

            if (Cache::has($claveDedup)) {
                continue;
            }

            // Si hay un unico colaborador cumpliendo anios hoy dentro del
            // alcance de este destinatario, el aviso puede apuntar a esa
            // felicitacion concreta; si no, resource_id queda null y la app
            // navega por `route`/`periodo` en vez de un id inventado.
            $greetingId = null;
            $periodo = $hoy->isNotEmpty() ? 'hoy' : 'proximos7';

            if ($hoy->count() === 1) {
                try {
                    $greetingId = $this->tarjetas->generar($hoy->first(), Carbon::today())->id;
                } catch (\Throwable $e) {
                    Log::error('cumpleanos: fallo al generar la tarjeta para el recordatorio de rh', [
                        'colaborador_id' => $hoy->first()->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                $destinatario->notify(new BirthdayRhReminderNotification($hoy->count(), $proximos7->count(), $greetingId, $periodo));

                $this->push->aUsuarioConDatos(
                    $destinatario,
                    'Cumpleaños de hoy',
                    $hoy->isNotEmpty()
                        ? "{$hoy->count()} colaborador(es) cumplen años hoy."
                        : "{$proximos7->count()} cumpleaños en los próximos 7 días.",
                    ['type' => 'rh_cumpleanos', 'resource_id' => $greetingId, 'route' => 'rh/cumpleanos', 'periodo' => $periodo],
                );

                Cache::put($claveDedup, true, Carbon::today()->endOfDay());
            } catch (\Throwable $e) {
                Log::error('cumpleanos: fallo al notificar a RH', [
                    'user_id' => $destinatario->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Colaborador>
     */
    private function queryBase(?User $usuario, array $filtros, bool $requiereFechaNacimiento = true): Builder
    {
        $query = Colaborador::query();

        if ($requiereFechaNacimiento) {
            $query->whereNotNull('fecha_nacimiento');
        }

        if ($usuario !== null) {
            $query = $this->alcance->limitarColaboradoresPorAlcance($query, $usuario);
        }

        $query->with(['sucursalPrincipal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre']);

        if (! empty($filtros['sucursal_id'])) {
            $query->where('sucursal_principal_id', $filtros['sucursal_id']);
        }

        if (! empty($filtros['departamento_id'])) {
            $query->where('departamento_id', $filtros['departamento_id']);
        }

        if (! empty($filtros['colaborador_id'])) {
            $query->where('id', $filtros['colaborador_id']);
        }

        if (! empty($filtros['estatus'])) {
            $query->where('estatus', $filtros['estatus']);
        } else {
            $query->where('estatus', EstadoUsuario::Activo->value);
        }

        if (! empty($filtros['busqueda'])) {
            $busqueda = $filtros['busqueda'];
            $query->where(function (Builder $sub) use ($busqueda): void {
                $sub->where('name', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('numero_empleado', 'like', "%{$busqueda}%");
            });
        }

        return $query;
    }

    private function proximaFecha(CarbonInterface $fechaNacimiento, CarbonInterface $hoy): CarbonInterface
    {
        return FechasCelebracion::proxima($fechaNacimiento, $hoy);
    }
}
