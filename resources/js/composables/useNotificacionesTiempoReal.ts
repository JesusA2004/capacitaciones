import type { NotificacionItem } from '@/composables/useNotificaciones';

type PayloadNotificacionBroadcast = {
    id: string;
    tipo: string | null;
    titulo: string;
    mensaje: string;
    url: string | null;
    resource_id?: number | string | null;
};

let suscrito = false;

/**
 * El tiempo real es opcional: solo se abre el WebSocket si el entorno lo
 * declara (`VITE_REVERB_ENABLED=true`, con `php artisan reverb:start`
 * corriendo). Sin él, ni siquiera se descarga Echo — la campana funciona
 * por polling y no hay errores de conexión en consola.
 */
export function realtimeHabilitado(): boolean {
    return (
        String(import.meta.env.VITE_REVERB_ENABLED ?? 'false') === 'true' &&
        Boolean(import.meta.env.VITE_REVERB_APP_KEY)
    );
}

/**
 * Conecta el canal privado del usuario autenticado en Reverb
 * (App.Models.User.{id}, ver routes/channels.php) para que cualquier
 * notificacion nueva llegue al instante mientras la sesion este abierta,
 * sin esperar el polling de useNotificaciones(). Aquí solo se avisa que
 * llegó algo: el toast, la notificación de Chrome, el tono y el contador
 * de la pestaña los da useNotificaciones() (avisarNuevas) al refrescar,
 * así un aviso nunca se muestra dos veces.
 *
 * Importa `@/echo` (que abre la conexion WebSocket) de forma perezosa, solo
 * cuando hay un userId real: asi la pantalla de login nunca abre un socket
 * sin autenticar.
 */
export function useNotificacionesTiempoReal(
    userId: number,
    onNuevaNotificacion: (notificacion: PayloadNotificacionBroadcast) => void,
): void {
    if (
        !realtimeHabilitado() ||
        suscrito ||
        typeof window === 'undefined' ||
        !userId
    ) {
        return;
    }

    suscrito = true;

    import('@/echo')
        .then(({ default: echo }) => {
            echo.private(`App.Models.User.${userId}`).notification(
                (payload: PayloadNotificacionBroadcast) =>
                    onNuevaNotificacion(payload),
            );
        })
        .catch(() => {
            // Sin Reverb configurado (VITE_REVERB_* ausentes) o el bundle
            // de Echo no cargo: la campana sigue funcionando por polling,
            // solo se pierde el tiempo real.
        });
}

export type { PayloadNotificacionBroadcast, NotificacionItem };
