<?php

namespace App\Services\Cumpleanos;

use App\Enums\EstadoUsuario;
use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Notifications\Mobile\BirthdayGreetingNotification;
use App\Notifications\Mobile\BirthdayRhReminderNotification;
use App\Services\AlcanceOrganizacionalService;
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
     * @return Collection<int, User>
     */
    public function cumpleanosDelMes(int $mes, ?User $usuario, array $filtros = []): Collection
    {
        // Orden por dia en PHP (no SQL) porque DAY() no es portable entre
        // MariaDB (produccion) y SQLite (tests, ver phpunit.xml); el volumen
        // por mes es siempre acotado, asi que ordenar en memoria es barato.
        return $this->queryBase($usuario, $filtros)
            ->whereMonth('fecha_nacimiento', $mes)
            ->get()
            ->sortBy(fn (User $colaborador) => (int) $colaborador->fecha_nacimiento->format('d'))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, User>
     */
    public function cumpleanosDeHoy(?User $usuario = null, array $filtros = []): Collection
    {
        $hoy = Carbon::today();

        return $this->queryBase($usuario, $filtros)
            ->whereMonth('fecha_nacimiento', $hoy->month)
            ->whereDay('fecha_nacimiento', $hoy->day)
            ->get();
    }

    /**
     * Colaboradores cuyo proximo cumpleanos cae dentro de los proximos $dias
     * (inclusive de hoy), ordenados por cercania. Calculado en PHP porque
     * "proximos N dias" puede cruzar el limite de anio (31-dic -> 5-ene) y
     * eso es mas simple de expresar aqui que con SQL de fecha portatil entre
     * motores.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, User>
     */
    public function proximosCumpleanos(?User $usuario, int $dias, array $filtros = []): Collection
    {
        $hoy = Carbon::today();

        return $this->queryBase($usuario, $filtros)
            ->get()
            ->map(function (User $colaborador) use ($hoy) {
                $colaborador->setAttribute('_proxima_fecha', $this->proximaFecha($colaborador->fecha_nacimiento, $hoy));

                return $colaborador;
            })
            ->filter(fn (User $colaborador) => $hoy->diffInDays($colaborador->getAttribute('_proxima_fecha'), false) <= $dias)
            ->sortBy(fn (User $colaborador) => $colaborador->getAttribute('_proxima_fecha')->timestamp)
            ->values();
    }

    /**
     * Dispatcher usado por la API movil de RH (GET /api/v1/rh/cumpleanos):
     * traduce el parametro `periodo` de la app al metodo de listado
     * correspondiente, todos ya acotados por alcance organizacional y
     * filtros. `mes` solo aplica (y es requerido) cuando periodo=mes.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, User>
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
    public function fechaEsteAnio(User $colaborador): ?CarbonInterface
    {
        if ($colaborador->fecha_nacimiento === null) {
            return null;
        }

        return Carbon::create(Carbon::today()->year, $colaborador->fecha_nacimiento->month, $colaborador->fecha_nacimiento->day);
    }

    public function calcularEdad(User $colaborador, ?CarbonInterface $enFecha = null): ?int
    {
        if ($colaborador->fecha_nacimiento === null) {
            return null;
        }

        return (int) $colaborador->fecha_nacimiento->diffInYears($enFecha ?? Carbon::today());
    }

    /**
     * Payload agrupado por dia del mes para el calendario mensual. Nunca
     * incluye el anio de nacimiento (solo dia/mes); la edad solo se agrega
     * si config('cumpleanos.show_age') esta activo.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function payloadCalendario(int $anio, int $mes, ?User $usuario, array $filtros = []): array
    {
        $colaboradores = $this->cumpleanosDelMes($mes, $usuario, $filtros);
        $mostrarEdad = (bool) config('cumpleanos.show_age');

        $porDia = [];

        foreach ($colaboradores as $colaborador) {
            $dia = (int) $colaborador->fecha_nacimiento->format('d');
            $porDia[$dia] ??= [];
            $porDia[$dia][] = $this->tarjetaColaborador($colaborador, $mostrarEdad, $usuario);
        }

        ksort($porDia);

        return $porDia;
    }

    /**
     * @return array<string, mixed>
     */
    public function tarjetaColaborador(User $colaborador, ?bool $mostrarEdad = null, ?User $solicitante = null): array
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
     * colaborador: notificacion in-app + push si tiene dispositivo. No
     * duplica si ya se notifico este anio/dia (BirthdayGreeting.enviada_at).
     * Cualquier fallo en tarjeta/push se registra en log y no interrumpe el
     * resto del lote (usado por el command diario).
     */
    public function felicitarColaborador(User $colaborador, CarbonInterface $fecha): BirthdayGreeting
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
    public function reenviarManual(User $colaborador, User $ejecutor): BirthdayGreeting
    {
        $fecha = $this->fechaEsteAnio($colaborador) ?? Carbon::today();
        $greeting = $this->tarjetas->generar($colaborador, $fecha);

        $this->notificarColaborador($greeting, $colaborador);

        $greeting->update(['enviada_at' => now(), 'enviada_por_id' => $ejecutor->id, 'auto_generada' => false]);

        return $greeting;
    }

    public function notificarColaborador(BirthdayGreeting $greeting, ?User $colaborador = null): void
    {
        $colaborador ??= $greeting->colaborador;

        try {
            $colaborador->notify(new BirthdayGreetingNotification($greeting));

            $this->push->aUsuario(
                $colaborador,
                'cumpleanos',
                $greeting->id,
                '¡Feliz cumpleaños!',
                'Tenemos una felicitación especial para ti en MR. LANA PEOPLE.',
            );
        } catch (\Throwable $e) {
            Log::error('cumpleanos: fallo al notificar al colaborador', [
                'user_id' => $colaborador->id,
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
            ->where('estatus', EstadoUsuario::Activo->value)
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
                        'user_id' => $hoy->first()->id,
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
     * @return Builder<User>
     */
    private function queryBase(?User $usuario, array $filtros): Builder
    {
        $query = User::query()->whereNotNull('fecha_nacimiento');

        if ($usuario !== null) {
            $query = $this->alcance->limitarUsuariosPorAlcance($query, $usuario);
        }

        $query->with(['sucursalPrincipal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre']);

        if (! empty($filtros['sucursal_id'])) {
            $query->where('sucursal_principal_id', $filtros['sucursal_id']);
        }

        if (! empty($filtros['departamento_id'])) {
            $query->where('departamento_id', $filtros['departamento_id']);
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
        $candidata = Carbon::create($hoy->year, $fechaNacimiento->month, $fechaNacimiento->day);

        if ($candidata->lt($hoy->startOfDay())) {
            $candidata = $candidata->addYear();
        }

        return $candidata;
    }
}
