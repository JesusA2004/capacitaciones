<?php

namespace App\Services\Notificaciones;

use App\Models\MobileDevice;
use App\Models\NotificacionEntrega;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Estado operativo de los avisos (colas, Reverb, Expo, fallas) para
 * `people:diagnostico-notificaciones` y las tarjetas de «Notificaciones del
 * sistema». Nunca expone tokens, llaves ni datos personales: solo
 * configuración no secreta, conteos y fechas.
 */
class MonitorNotificacionesService
{
    public const ULTIMO_PUSH = 'push';

    public const ULTIMO_FALLO_PUSH = 'fallo_push';

    public const ULTIMO_BROADCAST = 'broadcast';

    public const ULTIMA_NOTIFICACION = 'database';

    public const ULTIMA_RECONCILIACION = 'reconciliacion';

    /** Un job de avisos esperando más que esto = el worker no está corriendo. */
    private const MINUTOS_COLA_ATORADA = 5;

    public function registrar(string $evento): void
    {
        try {
            Cache::forever($this->clave($evento), now()->toIso8601String());
        } catch (Throwable) {
            // Monitoreo: jamás interrumpe el envío.
        }
    }

    public function ultimo(string $evento): ?Carbon
    {
        try {
            $valor = Cache::get($this->clave($evento));

            return is_string($valor) ? Carbon::parse($valor) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     config: array{queue: string, broadcast: string, expo: bool, reverb_configurado: bool, vite_reverb: bool, after_commit: bool, cola_notificaciones: string},
     *     colas: array{general: int, notificaciones: int, general_atorada: bool, notificaciones_atorada: bool, failed: int, failed_notificaciones: int},
     *     dispositivos_activos: int,
     *     entregas_24h: int,
     *     ultimos: array<string, string|null>,
     *     tarjetas: list<array{clave: string, titulo: string, estado: string, detalle: string}>
     * }
     */
    public function estado(): array
    {
        $colaAvisos = (string) config('queue.cola_notificaciones', 'notifications');
        $conexion = (string) config('queue.default');
        $broadcast = (string) config('broadcasting.default');
        $expo = (bool) config('expo.enabled');
        $reverbConfigurado = $broadcast === 'reverb'
            && filled(config('broadcasting.connections.reverb.key'))
            && filled(config('broadcasting.connections.reverb.secret'))
            && filled(config('broadcasting.connections.reverb.app_id'));

        $general = $this->pendientes('default');
        $avisos = $this->pendientes($colaAvisos);
        $failed = $this->failed();
        $failedAvisos = $this->failed($colaAvisos);

        $ultimos = [];

        foreach ([self::ULTIMA_NOTIFICACION, self::ULTIMO_BROADCAST, self::ULTIMO_PUSH, self::ULTIMO_FALLO_PUSH, self::ULTIMA_RECONCILIACION] as $evento) {
            $ultimos[$evento] = $this->ultimo($evento)?->toIso8601String();
        }

        $estado = [
            'config' => [
                'queue' => $conexion,
                'broadcast' => $broadcast,
                'expo' => $expo,
                'reverb_configurado' => $reverbConfigurado,
                // El build de Vite ya trae VITE_REVERB_ENABLED horneado; aquí
                // solo se informa lo que dice el .env del servidor.
                'vite_reverb' => (bool) config('broadcasting.vite_reverb_habilitado', false),
                'after_commit' => (bool) config("queue.connections.{$conexion}.after_commit", false),
                'cola_notificaciones' => $colaAvisos,
            ],
            'colas' => [
                'general' => $general['total'],
                'notificaciones' => $avisos['total'],
                'general_atorada' => $general['atorada'],
                'notificaciones_atorada' => $avisos['atorada'],
                'failed' => $failed,
                'failed_notificaciones' => $failedAvisos,
            ],
            'dispositivos_activos' => $this->contar(fn () => MobileDevice::query()->activos()->count()),
            'entregas_24h' => $this->contar(fn () => NotificacionEntrega::query()->where('created_at', '>=', now()->subDay())->count()),
            'ultimos' => $ultimos,
        ];

        $estado['tarjetas'] = $this->tarjetas($estado);

        return $estado;
    }

    /**
     * @param  array<string, mixed>  $estado
     * @return list<array{clave: string, titulo: string, estado: string, detalle: string}>
     */
    private function tarjetas(array $estado): array
    {
        $colas = $estado['colas'];
        $config = $estado['config'];
        $sync = $config['queue'] === 'sync';

        return [
            [
                'clave' => 'cola_general',
                'titulo' => 'Cola general',
                'estado' => $sync ? 'alerta' : ($colas['general_atorada'] ? 'error' : 'ok'),
                'detalle' => $sync ? 'QUEUE_CONNECTION=sync: los trabajos corren dentro de la petición.' : sprintf('%d trabajo(s) en espera%s.', $colas['general'], $colas['general_atorada'] ? ' — el worker general no los está tomando' : ''),
            ],
            [
                'clave' => 'notificaciones',
                'titulo' => 'Notificaciones',
                'estado' => $sync ? 'alerta' : ($colas['notificaciones_atorada'] ? 'error' : 'ok'),
                'detalle' => $sync ? 'Sin worker dedicado (sync).' : sprintf('%d aviso(s) en espera%s.', $colas['notificaciones'], $colas['notificaciones_atorada'] ? ' — el worker de notificaciones no está corriendo' : ''),
            ],
            [
                'clave' => 'reverb',
                'titulo' => 'Reverb (tiempo real web)',
                'estado' => $config['reverb_configurado'] ? 'ok' : 'alerta',
                'detalle' => $config['reverb_configurado']
                    ? 'Configurado. Si el proceso cae, la campana sigue al día por consulta cada 30 s.'
                    : sprintf('BROADCAST_CONNECTION=%s: la web se actualiza por consulta cada 30 s.', $config['broadcast']),
            ],
            [
                'clave' => 'expo',
                'titulo' => 'Expo Push (app)',
                'estado' => ! $config['expo'] ? 'alerta' : ($this->falloPushReciente() ? 'error' : 'ok'),
                'detalle' => ! $config['expo']
                    ? 'EXPO_PUSH_ENABLED=false: la app no recibe push.'
                    : sprintf('%d dispositivo(s) activo(s).%s', $estado['dispositivos_activos'], $this->falloPushReciente() ? ' Hubo un push fallido en las últimas 24 h.' : ''),
            ],
            [
                'clave' => 'failed',
                'titulo' => 'Trabajos fallidos',
                'estado' => $colas['failed'] === 0 ? 'ok' : 'error',
                'detalle' => sprintf('%d en failed_jobs (%d de avisos).', $colas['failed'], $colas['failed_notificaciones']),
            ],
        ];
    }

    private function falloPushReciente(): bool
    {
        $ultimo = $this->ultimo(self::ULTIMO_FALLO_PUSH);

        return $ultimo !== null && $ultimo->gt(now()->subDay());
    }

    /**
     * @return array{total: int, atorada: bool}
     */
    private function pendientes(string $cola): array
    {
        try {
            if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
                return ['total' => 0, 'atorada' => false];
            }

            $base = DB::table('jobs')->where('queue', $cola);
            $total = (clone $base)->count();
            $limite = now()->subMinutes(self::MINUTOS_COLA_ATORADA)->getTimestamp();
            $atorada = (clone $base)->whereNull('reserved_at')->where('available_at', '<=', $limite)->exists();

            return ['total' => $total, 'atorada' => $atorada];
        } catch (Throwable) {
            return ['total' => 0, 'atorada' => false];
        }
    }

    private function failed(?string $cola = null): int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return 0;
            }

            return DB::table('failed_jobs')->when($cola !== null, fn ($q) => $q->where('queue', $cola))->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function contar(callable $consulta): int
    {
        try {
            return (int) $consulta();
        } catch (Throwable) {
            return 0;
        }
    }

    private function clave(string $evento): string
    {
        return 'people:notificaciones:ultimo:'.$evento;
    }
}
