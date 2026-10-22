import { toast } from 'vue-sonner';
import type { NotificacionItem } from '@/composables/useNotificaciones';
import { webPushActivo } from '@/composables/useWebPush';

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
 * notificacion nueva (solicitud/vacaciones/documento/incorporacion, ver
 * App\Notifications\Mobile\*) llegue al instante mientras la sesion este
 * abierta, sin esperar el polling de 30s de useNotificaciones(): actualiza
 * la campana y muestra un toast + notificacion nativa del navegador
 * (Windows/mac/Android Chrome la presenta como notificacion del sistema).
 *
 * Importa `@/echo` (que abre la conexion WebSocket) de forma perezosa, solo
 * cuando hay un userId real: asi la pantalla de login nunca abre un socket
 * sin autenticar. Silenciosamente no hace nada si el navegador no
 * soporta/permite Notification o si Echo no logra conectar.
 */
export function useNotificacionesTiempoReal(
    userId: number,
    onNuevaNotificacion: (notificacion: PayloadNotificacionBroadcast) => void,
    alAbrir?: (id: string) => void,
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

    // El permiso de notificaciones ya no se pide solo: lo pide el botón
    // «Activar notificaciones de escritorio» (useWebPush), con un clic.
    import('@/echo')
        .then(({ default: echo }) => {
            echo.private(`App.Models.User.${userId}`).notification(
                (payload: PayloadNotificacionBroadcast) => {
                    toast.info(payload.titulo, {
                        description: payload.mensaje,
                        // Laravel agrega el `id` de la notificación al
                        // payload del broadcast: "Ver" la abre igual que
                        // un clic en la campana (marca leída + navega).
                        action:
                            alAbrir && payload.id
                                ? {
                                      label: 'Ver',
                                      onClick: () => alAbrir(payload.id),
                                  }
                                : undefined,
                    });
                    mostrarNotificacionNativa(payload);
                    onNuevaNotificacion(payload);
                },
            );
        })
        .catch(() => {
            // Sin Reverb configurado (VITE_REVERB_* ausentes) o el bundle
            // de Echo no cargo: la campana sigue funcionando por el
            // polling de 30s de useNotificaciones(), solo se pierde el
            // tiempo real.
        });
}

function mostrarNotificacionNativa(
    payload: PayloadNotificacionBroadcast,
): void {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    // Con Web Push activo, la notificación del sistema ya la muestra
    // public/sw.js (incluso con la pestaña cerrada): no duplicarla.
    if (webPushActivo()) {
        return;
    }

    // Respaldo sin Web Push: funciona mientras la pestaña esté abierta.
    new Notification(payload.titulo, {
        body: payload.mensaje,
        tag: `mrlana-notificacion-${payload.id}`,
    });
}

export type { PayloadNotificacionBroadcast, NotificacionItem };
