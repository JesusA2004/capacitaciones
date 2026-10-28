import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { initializePersonalizacion } from '@/composables/usePersonalizacion';
import { registrarServiceWorker } from '@/composables/useWebPush';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { tituloConPendientes } from '@/lib/avisosNavegador';
import { initializeErroresGlobales } from '@/lib/erroresGlobales';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    // «(3) …» con avisos sin leer, como WhatsApp Web (lib/avisosNavegador).
    title: (title) =>
        tituloConPendientes(title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Paginas publicas (sin sesion): auth.user llega null desde
            // HandleInertiaRequests, y AppLayout -> NavUser -> UserInfo lee
            // user.avatar sin optional chaining, asi que envolverlas con
            // AppLayout revienta el render para un visitante anonimo.
            case name.startsWith('AltaPublica/'):
            case name.startsWith('Incorporacion/Qr'):
            case name.startsWith('App/'):
            // Página de error: puede pintarse sin sesión (404, 500, 503).
            case name === 'Error':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        // Color de marca (token), con retraso corto para no parpadear en
        // navegaciones rápidas.
        color: 'var(--brand-primary)',
        delay: 150,
    },
});

// Solo tema claro: limpia cualquier preferencia oscura guardada...
initializeTheme();

// Tema de color / animaciones guardados por el usuario (Settings/Personalizacion)...
initializePersonalizacion();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Ningún 422/403/500 en silencio: toast + foco al primer error.
initializeErroresGlobales();

// Único Service Worker de PEOPLE: public/sw.js, SOLO para notificaciones de
// escritorio (Web Push, sin caché ni handler fetch). Cualquier otro
// registrado en este origen es residuo de otro proyecto servido antes en el
// mismo host:puerto y se desregistra (ver composables/useWebPush.ts).
void registrarServiceWorker();
