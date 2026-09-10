import { toast } from 'vue-sonner';
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
): void {
    if (suscrito || typeof window === 'undefined' || !userId) {
        return;
    }

    suscrito = true;

    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().catch(() => undefined);
    }

    import('@/echo')
        .then(({ default: echo }) => {
            echo.private(`App.Models.User.${userId}`).notification(
                (payload: PayloadNotificacionBroadcast) => {
                    toast.info(payload.titulo, {
                        description: payload.mensaje,
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

    // No usa Service Worker/Web Push: funciona mientras la sesion (pestaña)
    // este abierta, incluida en segundo plano (minimizada o sin foco) —
    // esa es la garantia pedida, no notificar con el navegador cerrado.
    new Notification(payload.titulo, {
        body: payload.mensaje,
        tag: `mrlana-notificacion-${payload.id}`,
    });
}

export type { PayloadNotificacionBroadcast, NotificacionItem };
