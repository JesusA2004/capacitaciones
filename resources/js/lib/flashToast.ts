import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

/**
 * Un solo aviso de éxito por acción: muchas pantallas muestran su propio
 * mensaje en `onSuccess` y el backend además manda su flash toast
 * (`->with('toast', ...)`, ver HandleInertiaRequests). El que llegue
 * primero se muestra; el otro, si llega dentro de la ventana, se omite.
 */
const VENTANA_MS = 2000;
let ultimoExito = 0;

export function avisarExito(mensaje: string): void {
    if (Date.now() - ultimoExito < VENTANA_MS) {
        return;
    }

    ultimoExito = Date.now();
    toast.success(mensaje);
}

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        if (data.type === 'success') {
            avisarExito(data.message);

            return;
        }

        toast[data.type](data.message);
    });
}
