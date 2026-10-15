import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

/**
 * Red de seguridad para que ninguna acción falle en silencio (cierre
 * 2026-10-15): cualquier respuesta 422 de una visita Inertia muestra un
 * toast con el resumen y lleva el foco al primer error visible del
 * formulario. Si el error es de un campo que la pantalla no pinta (p. ej.
 * `referencias`, `dias`, `lote`), el toast es lo que lo hace visible.
 * Las pantallas siguen mostrando cada error junto a su campo.
 */
const VENTANA_MS = 1500;
let ultimoAviso = 0;

function primerMensaje(errores: Record<string, string | string[]>): string | null {
    for (const valor of Object.values(errores)) {
        const texto = Array.isArray(valor) ? valor[0] : valor;

        if (texto) {
            return texto;
        }
    }

    return null;
}

function enfocarPrimerError(): void {
    window.setTimeout(() => {
        const mensaje = Array.from(
            document.querySelectorAll<HTMLElement>('[data-input-error]'),
        ).find((el) => el.offsetParent !== null && el.textContent?.trim());

        if (!mensaje) {
            return;
        }

        mensaje.scrollIntoView({ block: 'center', behavior: 'smooth' });

        const contenedor = mensaje.closest('div.grid, div.flex, div') ?? mensaje.parentElement;
        const campo = contenedor?.parentElement?.querySelector<HTMLElement>(
            'input:not([type="hidden"]), textarea, select, button[role="combobox"]',
        );

        campo?.focus({ preventScroll: true });
    }, 60);
}

export function initializeErroresGlobales(): void {
    router.on('error', (event) => {
        const errores = (event.detail.errors ?? {}) as Record<string, string | string[]>;
        const mensaje = primerMensaje(errores);

        if (mensaje && Date.now() - ultimoAviso > VENTANA_MS) {
            ultimoAviso = Date.now();
            const total = Object.keys(errores).length;
            toast.error(total > 1 ? `Revisa ${total} campos: ${mensaje}` : mensaje);
        }

        enfocarPrimerError();
    });

    // Respuesta que no es Inertia (HTML/JSON de un error del servidor):
    // nunca una pantalla muerta; se avisa y la página actual se conserva.
    router.on('httpException', (event) => {
        const estado = event.detail.response.status;
        event.preventDefault();
        toast.error(
            estado === 403
                ? 'No tienes permiso para realizar esta acción.'
                : 'Ocurrió un error inesperado. Intenta de nuevo; si persiste, avisa a Sistemas.',
        );
    });

    router.on('networkError', (event) => {
        event.preventDefault();
        toast.error('No se pudo completar la acción: revisa tu conexión e intenta de nuevo.');
    });
}
