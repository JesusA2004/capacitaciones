import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Cliente de Laravel Reverb (WebSocket self-hosted, protocolo compatible
 * con Pusher). Un solo Echo compartido por pestaña, reutilizado desde
 * cualquier composable que necesite un canal privado (hoy solo
 * notificaciones en tiempo real, ver
 * resources/js/composables/useNotificacionesTiempoReal.ts).
 *
 * Solo se importa si `VITE_REVERB_ENABLED=true` (ver `realtimeHabilitado`).
 * Si el servidor no responde, Echo se desconecta tras el primer intento
 * fallido en vez de reintentar indefinidamente (cada reintento es un error
 * de consola del navegador): la campana sigue al día por el polling de
 * useNotificaciones() y al volver el foco a la pestaña.
 *
 * La app móvil puede consumir el mismo servidor Reverb con cualquier
 * cliente compatible con Pusher, autenticando canales privados contra
 * POST /api/v1/broadcasting/auth con su Bearer token (ver routes/api.php y
 * docs/PUSH_NOTIFICATIONS.md).
 */
declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

const seguro = (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: seguro,
    // Un solo transporte según el esquema: sin el "intento ws → luego
    // wss" que duplicaba el error cuando el servidor no estaba arriba.
    enabledTransports: seguro ? ['wss'] : ['ws'],
});

window.Echo.connector.pusher.connection.bind(
    'state_change',
    ({ current }: { current: string }) => {
        if (current === 'unavailable' || current === 'failed') {
            window.Echo.disconnect();
        }
    },
);

export default window.Echo;
