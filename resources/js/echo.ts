import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Cliente de Laravel Reverb (WebSocket self-hosted, protocolo compatible
 * con Pusher). Un solo Echo compartido por pestaña: se conecta apenas se
 * importa este modulo y se reutiliza desde cualquier composable que
 * necesite un canal privado (hoy solo notificaciones en tiempo real, ver
 * resources/js/composables/useNotificacionesTiempoReal.ts).
 *
 * La app movil puede consumir el mismo servidor Reverb con cualquier
 * cliente compatible con Pusher (pusher-js/laravel-echo en React Native),
 * autenticando canales privados contra POST /api/v1/broadcasting/auth con
 * su Bearer token (ver routes/api.php y docs/PUSH_NOTIFICATIONS.md).
 */
declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

export default window.Echo;
