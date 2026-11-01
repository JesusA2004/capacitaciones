import { router } from '@inertiajs/vue3';

/** Un clic cuenta para la visita que arranca poco después (incluye el diálogo). */
const VENTANA_CLIC_MS = 1500;

/**
 * Red de seguridad de toda acción que escribe (`router.post/put/patch/delete`):
 * una repetición exacta mientras la primera sigue en curso se ignora (doble
 * clic) y el botón que la lanzó se marca como ocupado, con spinner (app.css).
 * Las pantallas con estado propio usan además useAccionEnCurso.
 */
export function initializeAccionesEnCurso(): void {
    const enCurso = new Map<string, HTMLElement | null>();
    let ultimoBoton: HTMLElement | null = null;
    let ultimoClic = 0;

    document.addEventListener(
        'click',
        (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }

            // Confirmar en useAlertas no cambia quién inició la acción.
            if (event.target.closest('.swal2-container')) {
                ultimoClic = Date.now();

                return;
            }

            const boton = event.target.closest<HTMLElement>(
                'button, [role="button"], a[data-slot="button"]',
            );

            if (boton) {
                ultimoBoton = boton;
                ultimoClic = Date.now();
            }
        },
        true,
    );

    function clave(visita: {
        method: string;
        url: URL;
        data: unknown;
    }): string | null {
        if (visita.method === 'get' || visita.data instanceof FormData) {
            return null;
        }

        return `${visita.method} ${visita.url.href} ${JSON.stringify(visita.data)}`;
    }

    router.on('before', (event) => {
        const visita = event.detail.visit;
        const llave = clave(visita);

        if (llave !== null && !visita.async && enCurso.has(llave)) {
            return false;
        }
    });

    router.on('start', (event) => {
        const visita = event.detail.visit;
        const llave = clave(visita);

        if (llave === null || visita.async) {
            return;
        }

        const boton =
            ultimoBoton?.isConnected &&
            Date.now() - ultimoClic < VENTANA_CLIC_MS
                ? ultimoBoton
                : null;

        boton?.setAttribute('data-accion-en-curso', '');
        boton?.setAttribute('aria-busy', 'true');
        enCurso.set(llave, boton);
    });

    router.on('finish', (event) => {
        const llave = clave(event.detail.visit);

        if (llave === null || !enCurso.has(llave)) {
            return;
        }

        const boton = enCurso.get(llave);
        boton?.removeAttribute('data-accion-en-curso');
        boton?.removeAttribute('aria-busy');
        enCurso.delete(llave);
    });
}
