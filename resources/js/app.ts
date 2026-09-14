import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { initializePersonalizacion } from '@/composables/usePersonalizacion';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Paginas publicas (sin sesion): auth.user llega null desde
            // HandleInertiaRequests, y AppLayout -> NavUser -> UserInfo lee
            // user.avatar sin optional chaining, asi que envolverlas con
            // AppLayout revienta el render para un visitante anonimo.
            case name.startsWith('AltaPublica/'):
            case name.startsWith('Incorporacion/Qr'):
            case name.startsWith('App/'):
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
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// Tema de color / animaciones guardados por el usuario (Settings/Personalizacion)...
initializePersonalizacion();

// This will listen for flash toast data from the server...
initializeFlashToast();

/**
 * Salvaguarda: reka-ui bloquea `document.body.style.pointerEvents` mientras
 * hay un Select/DropdownMenu/Dialog abierto (ver
 * node_modules/reka-ui/dist/DismissableLayer/DismissableLayer.js) y lo
 * restaura cuando se cierra. En una carrera con una recarga parcial de
 * Inertia (por ejemplo, un filtro con Select que dispara router.get al
 * elegir una opción) ese bloqueo se puede quedar pegado aunque ya no haya
 * ningún overlay abierto -- toda la página deja de responder a clics
 * (incluido el propio elemento atascado) hasta refrescar. Un intervalo es
 * la única forma de autorepararlo: el bloqueo también impide que un click
 * dispare el arreglo.
 */
setInterval(() => {
    if (
        document.body.style.pointerEvents === 'none' &&
        !document.querySelector('[data-dismissable-layer]')
    ) {
        document.body.style.pointerEvents = '';
    }
}, 1000);
