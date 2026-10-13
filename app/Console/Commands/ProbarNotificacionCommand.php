<?php

namespace App\Console\Commands;

use App\Enums\CategoriaNotificacion;
use App\Models\NotificacionEntrega;
use App\Models\User;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Aviso de PRUEBA (claramente marcado) a un usuario por todos los canales
 * disponibles: base de datos (campana/app), broadcast (Reverb) y push
 * (Expo). Para no dejar basura, cada corrida reemplaza la prueba anterior
 * de ese usuario.
 */
class ProbarNotificacionCommand extends Command
{
    protected $signature = 'people:probar-notificacion {--user= : ID del usuario destinatario}';

    protected $description = 'Envía una notificación de PRUEBA a un usuario por base de datos, Reverb y push';

    public function handle(NotificacionEventosService $eventos): int
    {
        $usuario = User::query()->where('id', (int) $this->option('user'))->first();

        if ($usuario === null) {
            $this->error('Indica un usuario existente: --user=ID');

            return self::FAILURE;
        }

        // Reemplaza la prueba anterior (no acumula avisos de prueba).
        $anteriores = NotificacionEntrega::query()->where('user_id', $usuario->id)->where('categoria', CategoriaNotificacion::Prueba->value);
        $usuario->notifications()->whereIn('id', (clone $anteriores)->whereNotNull('notification_id')->pluck('notification_id'))->delete();
        $anteriores->delete();

        $entregada = $eventos->entregar($usuario, CategoriaNotificacion::Prueba, new Aviso(
            'prueba_notificacion',
            'push_test',
            '🔧 PRUEBA de notificaciones',
            'Aviso de prueba de Sistemas (base de datos, tiempo real y push). Puedes ignorarlo.',
            null,
            'prueba',
            'baja',
            [],
            Str::uuid()->toString(),
        ), 'prueba');

        $dispositivos = (int) NotificacionEntrega::query()->where('user_id', $usuario->id)->where('categoria', CategoriaNotificacion::Prueba->value)->value('push_dispositivos');

        $this->line($entregada ? 'Notificación de prueba encolada.' : 'No se pudo encolar la prueba (revisa el log).');
        $this->line(sprintf('Base de datos + broadcast: cola %s. Push: %d dispositivo(s) activo(s).', (string) config('queue.cola_notificaciones'), $dispositivos));
        $this->line('Si no llega en segundos: php artisan people:diagnostico-notificaciones');

        return $entregada ? self::SUCCESS : self::FAILURE;
    }
}
