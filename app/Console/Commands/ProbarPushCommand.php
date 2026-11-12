<?php

namespace App\Console\Commands;

use App\Enums\CategoriaNotificacion;
use App\Models\MobileDevice;
use App\Models\NotificacionEntrega;
use App\Models\User;
use App\Models\WebPushSuscripcion;
use App\Services\MobilePush\ExpoPushService;
use App\Services\Notificaciones\MonitorNotificacionesService;
use App\Services\WebPush\WebPushNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Diagnóstico de entrega a UNA persona, canal por canal, para correr en el
 * servidor (docs/PUSH_NOTIFICATIONS.md):
 *
 *  1. App (Expo): cuántos teléfonos tiene registrados y qué respondió Expo a
 *     un push de PRUEBA enviado en este momento (ticket + recibo de FCM/APNs).
 *  2. Navegador (Web Push): cuántos navegadores tiene suscritos y qué
 *     respondió el servicio push de cada uno.
 *  3. Campana + cola: encola el aviso de prueba normal
 *     (`people:probar-notificacion`) y espera a que el worker lo procese.
 *
 * Los envíos 1 y 2 son directos (sin cola): separan «el proveedor rechaza»
 * de «el worker no está corriendo». Nunca imprime tokens ni llaves
 * completos. Solo lectura, salvo el propio aviso de prueba y la revocación
 * de un teléfono que Expo reporta como desinstalado (igual que el flujo normal).
 */
class ProbarPushCommand extends Command
{
    private const TITULO = '🔧 PRUEBA de notificaciones';

    protected $signature = 'people:probar-push
        {usuario : ID o correo de la cuenta destinataria}
        {--sin-enviar : Solo cuenta dispositivos y revisa la configuración, no envía nada}
        {--sin-cola : No prueba la campana ni el worker de la cola}
        {--recibo=8 : Segundos de espera antes de pedir a Expo el recibo de entrega (0 = no pedirlo)}
        {--espera=20 : Segundos máximos esperando a que el worker procese el aviso de la cola}';

    protected $description = 'Envía una notificación de PRUEBA a una persona por app (Expo), navegador (Web Push) y campana, y muestra qué respondió cada canal';

    public function handle(ExpoPushService $expo, WebPushNotifier $web, MonitorNotificacionesService $monitor): int
    {
        $usuario = $this->usuario();

        if ($usuario === null) {
            $this->error('No existe esa cuenta. Indica su ID o su correo: php artisan people:probar-push 15');

            return self::FAILURE;
        }

        $enviar = ! (bool) $this->option('sin-enviar');
        $problemas = [];

        $this->info(sprintf('Cuenta #%d — %s', $usuario->id, $usuario->nombreCompleto()));

        if ($usuario->acceso_bloqueado_en !== null) {
            $problemas[] = 'La cuenta tiene el acceso suspendido (baja): no recibe tareas nuevas.';
        }

        $this->configuracion($monitor, $problemas);
        $this->app($usuario, $expo, $enviar, $problemas);
        $this->navegador($usuario, $web, $enviar, $problemas);

        if ($enviar && ! (bool) $this->option('sin-cola')) {
            $this->cola($usuario, $problemas);
        }

        $this->newLine();

        if ($problemas === []) {
            $this->info('Sin problemas detectados en el servidor.');
            $this->line('Si aun así no aparece en el teléfono: revisa que la app tenga permiso de notificaciones, que no esté en ahorro de batería y que la sesión abierta sea la de esta cuenta.');

            return self::SUCCESS;
        }

        $this->warn('Revisar:');

        foreach ($problemas as $problema) {
            $this->line(' - '.$problema);
        }

        return self::FAILURE;
    }

    private function usuario(): ?User
    {
        $valor = trim((string) $this->argument('usuario'));

        if ($valor === '') {
            return null;
        }

        return ctype_digit($valor)
            ? User::query()->where('id', (int) $valor)->first()
            : User::query()->where('email', $valor)->first();
    }

    /**
     * @param  list<string>  $problemas
     */
    private function configuracion(MonitorNotificacionesService $monitor, array &$problemas): void
    {
        $estado = $monitor->estado();
        $config = $estado['config'];
        $colas = $estado['colas'];
        $url = (string) config('app.url');
        $si = fn (bool $valor): string => $valor ? 'sí' : 'no';

        $this->newLine();
        $this->line('<comment>Servidor</comment>');
        $this->table(['Clave', 'Valor'], [
            ['APP_ENV / APP_URL', sprintf('%s / %s', (string) config('app.env'), $url)],
            ['QUEUE_CONNECTION (driver)', sprintf('%s (%s)', $config['queue'], $config['queue_driver'])],
            ['Cola de avisos', sprintf('%s — %d en espera, %d fallidos', $config['cola_notificaciones'], $colas['notificaciones'], $colas['failed_notificaciones'])],
            ['Expo push habilitado', $si($config['expo'])],
            ['Web Push habilitado / llaves VAPID', sprintf('%s / %s', $si($config['web_push']), $si($config['vapid']))],
            ['Extensiones curl / openssl', sprintf('%s / %s', $si(extension_loaded('curl')), $si(extension_loaded('openssl')))],
        ]);

        if ($config['queue'] === 'sync') {
            $problemas[] = 'QUEUE_CONNECTION=sync: los avisos salen dentro de la petición (lento y sin reintentos). En producción usa database.';
        } elseif ($config['queue_driver'] !== 'database') {
            $problemas[] = sprintf('QUEUE_CONNECTION=%s: los avisos se encolan ahí, pero el worker people-notifications consume la tabla jobs (conexión «notificaciones»). Nadie los procesa.', $config['queue']);
        }

        if ($colas['notificaciones_atorada']) {
            $problemas[] = 'Hay avisos esperando más de 5 minutos en la cola: el worker people-notifications no está corriendo (sudo supervisorctl status people-notifications:*).';
        }

        if (! $config['expo']) {
            $problemas[] = 'EXPO_PUSH_ENABLED=false: la app no recibe push.';
        }

        if (! $config['web_push'] || ! $config['vapid']) {
            $problemas[] = 'Web Push apagado o sin llaves VAPID: ningún navegador puede suscribirse (php artisan webpush:claves).';
        }

        if (app()->environment('production') && ! str_starts_with($url, 'https://')) {
            $problemas[] = 'APP_URL no es https: el navegador solo registra el Service Worker y la suscripción en un sitio seguro.';
        }
    }

    /**
     * @param  list<string>  $problemas
     */
    private function app(User $usuario, ExpoPushService $expo, bool $enviar, array &$problemas): void
    {
        $dispositivos = MobileDevice::query()->where('user_id', $usuario->id)->orderByDesc('last_seen_at')->get();
        $activos = $dispositivos->whereNull('revoked_at')->values();

        $this->newLine();
        $this->line(sprintf('<comment>1. App (Expo)</comment> — %d teléfono(s) activo(s), %d revocado(s)', $activos->count(), $dispositivos->count() - $activos->count()));

        if ($activos->isEmpty()) {
            $problemas[] = 'App: la cuenta no tiene ningún teléfono registrado. Debe abrir la app con esta cuenta y aceptar las notificaciones (la app registra el token en POST /api/v1/dispositivos/push-token).';

            return;
        }

        $filas = [];
        $tickets = [];

        foreach ($activos as $dispositivo) {
            $token = $dispositivo->push_token;
            $formato = preg_match('/^Expo(nent)?PushToken\[.+\]$/', $token) === 1;
            $respuesta = '—';

            if (! $formato) {
                $problemas[] = sprintf('App: el dispositivo #%d no tiene un token de Expo válido (build sin projectId de EAS o token de otro proveedor).', $dispositivo->id);
            } elseif ($enviar) {
                $envio = $expo->enviarDetallado($token, self::TITULO, 'Envío directo a este teléfono desde el servidor. Puedes ignorarlo.', ['type' => 'push_test', 'categoria' => CategoriaNotificacion::Prueba->value, 'user_id' => $usuario->id]);
                $respuesta = trim(sprintf('%s%s%s', $envio['resultado'], $envio['error'] !== null ? ' · '.$envio['error'] : '', $envio['detalle'] !== null ? ' · '.mb_substr($envio['detalle'], 0, 90) : ''));

                if ($envio['resultado'] !== ExpoPushService::RESULTADO_ENVIADO) {
                    $problemas[] = sprintf('App: Expo no aceptó el push del dispositivo #%d (%s).', $dispositivo->id, $respuesta);
                } elseif ($envio['ticket_id'] !== null) {
                    $tickets[$dispositivo->id] = ['token' => $token, 'ticket' => $envio['ticket_id']];
                }
            }

            $filas[$dispositivo->id] = [
                '#'.$dispositivo->id,
                $dispositivo->platform,
                (string) ($dispositivo->app_version ?? '—'),
                $dispositivo->last_seen_at?->diffForHumans() ?? '—',
                $this->enmascarar($token),
                $respuesta,
                '—',
            ];
        }

        $espera = max(0, (int) $this->option('recibo'));

        if ($tickets !== [] && $espera > 0) {
            $this->line(sprintf('   Expo aceptó %d envío(s); esperando %d s el recibo de FCM/APNs…', count($tickets), $espera));
            Sleep::for($espera)->seconds();

            foreach ($tickets as $id => $ticket) {
                $recibo = $expo->recibo($ticket['token'], $ticket['ticket']);
                $filas[$id][6] = trim(sprintf('%s%s%s', $recibo['estado'], $recibo['error'] !== null ? ' · '.$recibo['error'] : '', $recibo['detalle'] !== null ? ' · '.mb_substr($recibo['detalle'], 0, 90) : ''));

                if ($recibo['estado'] === 'error') {
                    $problemas[] = $recibo['error'] === 'DeviceNotRegistered'
                        ? sprintf('App: el dispositivo #%d ya no existe para Expo (app desinstalada o token viejo); quedó revocado. La persona debe volver a abrir la app.', $id)
                        : sprintf('App: FCM/APNs rechazó el push del dispositivo #%d (%s). Es de las credenciales del proyecto EAS (FCM v1 / APNs), no del servidor.', $id, $filas[$id][6]);
                }
            }
        }

        $this->table(['Dispositivo', 'Plataforma', 'Versión app', 'Visto', 'Token', 'Expo (ticket)', 'FCM/APNs (recibo)'], array_values($filas));
    }

    /**
     * @param  list<string>  $problemas
     */
    private function navegador(User $usuario, WebPushNotifier $web, bool $enviar, array &$problemas): void
    {
        $suscripciones = WebPushSuscripcion::query()->where('user_id', $usuario->id)->orderByDesc('updated_at')->get();

        $this->newLine();
        $this->line(sprintf('<comment>2. Navegador (Web Push)</comment> — %d navegador(es) suscrito(s)', $suscripciones->count()));

        if ($suscripciones->isEmpty()) {
            $problemas[] = 'Navegador: la cuenta no activó las notificaciones en ningún navegador. En la web: campana → «Activar notificaciones» (el permiso solo se puede pedir con un clic de la persona).';

            return;
        }

        $filas = [];

        foreach ($suscripciones as $suscripcion) {
            $respuesta = '—';

            if ($enviar && $web->habilitado()) {
                try {
                    $reporte = $web->enviarAhora($suscripcion, $web->payload(self::TITULO, 'Envío directo a este navegador desde el servidor. Puedes ignorarlo.', ['type' => 'push_test']));
                    $respuesta = $reporte['exito']
                        ? sprintf('aceptado (HTTP %s)', (string) ($reporte['estado'] ?? '201'))
                        : sprintf('%s (HTTP %s) %s', $reporte['vencida'] ? 'suscripción vencida' : 'rechazado', (string) ($reporte['estado'] ?? 'sin respuesta'), mb_substr($reporte['motivo'], 0, 90));

                    if (! $reporte['exito']) {
                        $problemas[] = match (true) {
                            $reporte['vencida'] => sprintf('Navegador: la suscripción #%d venció (la persona quitó el permiso o borró los datos del sitio). Se borra sola en el siguiente aviso; debe volver a entrar a la web.', $suscripcion->id),
                            $reporte['estado'] === null => sprintf('Navegador: el servidor no pudo conectar con el servicio push de la suscripción #%d (%s). Revisa salida HTTPS y certificados de CA del servidor.', $suscripcion->id, mb_substr($reporte['motivo'], 0, 120)),
                            in_array($reporte['estado'], [400, 401, 403], true) => sprintf('Navegador: el servicio push rechazó la firma de la suscripción #%d (HTTP %d). Las llaves VAPID del servidor no son las mismas con que se suscribió, o VAPID_SUBJECT no es un mailto:/https: válido.', $suscripcion->id, $reporte['estado']),
                            default => sprintf('Navegador: suscripción #%d rechazada (HTTP %d).', $suscripcion->id, $reporte['estado']),
                        };
                    }
                } catch (Throwable $e) {
                    $respuesta = 'error: '.mb_substr($e->getMessage(), 0, 90);
                    $problemas[] = sprintf('Navegador: no se pudo cifrar/enviar a la suscripción #%d (%s).', $suscripcion->id, mb_substr($e->getMessage(), 0, 160));
                }
            }

            $filas[] = [
                '#'.$suscripcion->id,
                (string) (parse_url($suscripcion->endpoint, PHP_URL_HOST) ?: '—'),
                mb_substr((string) ($suscripcion->navegador ?? '—'), 0, 48),
                $suscripcion->ultimo_envio_en?->diffForHumans() ?? 'nunca',
                $respuesta,
            ];
        }

        $this->table(['Suscripción', 'Servicio push', 'Navegador', 'Último envío', 'Respuesta'], $filas);
    }

    /**
     * Mismo camino que un aviso real: base de datos + tiempo real + push,
     * por la cola de avisos. Si la fila no aparece en `notifications`, el
     * worker no está procesando.
     *
     * @param  list<string>  $problemas
     */
    private function cola(User $usuario, array &$problemas): void
    {
        $this->newLine();
        $this->line('<comment>3. Campana y cola</comment>');

        $this->callSilent('people:probar-notificacion', ['--user' => $usuario->id]);

        $notificacion = NotificacionEntrega::query()
            ->where('user_id', $usuario->id)
            ->where('categoria', CategoriaNotificacion::Prueba->value)
            ->orderByDesc('id')
            ->value('notification_id');

        if (! is_string($notificacion)) {
            $problemas[] = 'Campana: no se pudo registrar el aviso de prueba (ver storage/logs/laravel.log, «NotificacionEventosService»).';
            $this->line('   No se pudo encolar el aviso de prueba.');

            return;
        }

        $limite = max(0, (int) $this->option('espera'));
        $llego = false;

        for ($segundo = 0; $segundo <= $limite; $segundo++) {
            if (DB::table('notifications')->where('id', $notificacion)->exists()) {
                $llego = true;
                break;
            }

            if ($segundo < $limite) {
                Sleep::for(1)->second();
            }
        }

        if ($llego) {
            $this->line('   El worker procesó el aviso: ya está en la campana de la persona (y salieron sus push por la cola).');

            return;
        }

        $this->line(sprintf('   El aviso sigue en la cola después de %d s.', $limite));
        $problemas[] = sprintf('Cola: el aviso de prueba no se procesó en %d s. El worker de avisos no está corriendo o consume otra conexión: sudo supervisorctl status people-notifications:* (debe correr «queue:work notificaciones --queue=%s»).', $limite, (string) config('queue.cola_notificaciones'));
    }

    private function enmascarar(string $token): string
    {
        return mb_strlen($token) <= 30 ? '…'.mb_substr($token, -4) : sprintf('%s…%s', mb_substr($token, 0, 22), mb_substr($token, -5));
    }
}
