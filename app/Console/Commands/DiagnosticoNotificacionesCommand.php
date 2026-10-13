<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\MonitorNotificacionesService;
use Illuminate\Console\Command;

/**
 * Estado de la entrega de avisos SIN secretos ni datos personales: conexión
 * de cola y broadcast, Expo, Reverb configurado (sí/no, nunca las llaves),
 * trabajos en espera por cola, fallidos, dispositivos activos (conteo, nunca
 * tokens) y fechas del último envío/broadcast/reconciliación.
 */
class DiagnosticoNotificacionesCommand extends Command
{
    protected $signature = 'people:diagnostico-notificaciones';

    protected $description = 'Diagnóstico de notificaciones: colas, Reverb, Expo, fallos y último envío (sin secretos)';

    public function handle(MonitorNotificacionesService $monitor): int
    {
        $estado = $monitor->estado();
        $config = $estado['config'];
        $colas = $estado['colas'];
        $si = fn (bool $valor): string => $valor ? 'sí' : 'no';

        $this->info('Configuración');
        $this->table(['Clave', 'Valor'], [
            ['APP_ENV', (string) config('app.env')],
            ['APP_DEBUG', $si((bool) config('app.debug'))],
            ['QUEUE_CONNECTION', $config['queue']],
            ['DB_QUEUE_AFTER_COMMIT', $si($config['after_commit'])],
            ['Cola de avisos', $config['cola_notificaciones']],
            ['BROADCAST_CONNECTION', $config['broadcast']],
            ['Reverb configurado (llaves presentes)', $si($config['reverb_configurado'])],
            ['VITE_REVERB_ENABLED (.env del servidor)', $si($config['vite_reverb'])],
            ['Expo push habilitado', $si($config['expo'])],
        ]);

        $this->info('Colas y entrega');
        $this->table(['Métrica', 'Valor'], [
            ['Jobs en espera: notifications', $colas['notificaciones'].($colas['notificaciones_atorada'] ? '  ⚠ worker de notificaciones detenido' : '')],
            ['Jobs en espera: default', $colas['general'].($colas['general_atorada'] ? '  ⚠ worker general detenido' : '')],
            ['failed_jobs (total / avisos)', sprintf('%d / %d', $colas['failed'], $colas['failed_notificaciones'])],
            ['Dispositivos push activos', (string) $estado['dispositivos_activos']],
            ['Avisos entregados (24 h)', (string) $estado['entregas_24h']],
            ['Última notificación (BD)', $estado['ultimos'][MonitorNotificacionesService::ULTIMA_NOTIFICACION] ?? '—'],
            ['Último broadcast', $estado['ultimos'][MonitorNotificacionesService::ULTIMO_BROADCAST] ?? '—'],
            ['Último push aceptado por Expo', $estado['ultimos'][MonitorNotificacionesService::ULTIMO_PUSH] ?? '—'],
            ['Último push fallido', $estado['ultimos'][MonitorNotificacionesService::ULTIMO_FALLO_PUSH] ?? '—'],
            ['Última reconciliación', $estado['ultimos'][MonitorNotificacionesService::ULTIMA_RECONCILIACION] ?? '—'],
        ]);

        foreach ($estado['tarjetas'] as $tarjeta) {
            $this->line(sprintf('[%s] %s — %s', mb_strtoupper($tarjeta['estado']), $tarjeta['titulo'], $tarjeta['detalle']));
        }

        return self::SUCCESS;
    }
}
