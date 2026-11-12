import type { VisitOptions } from '@inertiajs/core';

/**
 * Cambio de pantalla con la View Transitions API: la pantalla que se va se
 * funde con la que llega (ver «Cambio de pantalla» en app.css) en vez de
 * cortar de golpe. Solo aplica al NAVEGAR a otra ruta; filtrar, paginar,
 * guardar o recargar datos de la misma pantalla no lleva transición (ahí ya
 * actúa `data-tablas-actualizando`). Donde el navegador no la soporta,
 * Inertia la ignora y queda la entrada por CSS de la página nueva.
 *
 * `<Link>` siempre manda `viewTransition: false` por defecto, así que un
 * `false` no distingue «no la quiero» de «no dije nada»: solo se respeta un
 * valor explícito afirmativo (true o una función). Para una visita SIN
 * transición basta `preserveState` o `async`.
 */
export function opcionesDeVisita(
    href: string,
    opciones: VisitOptions,
): VisitOptions {
    if (opciones.viewTransition) {
        return {};
    }

    return { viewTransition: esCambioDePantalla(href, opciones) };
}

function esCambioDePantalla(href: string, opciones: VisitOptions): boolean {
    const metodo = String(opciones.method ?? 'get').toLowerCase();

    if (
        metodo !== 'get' ||
        (opciones.preserveState !== undefined &&
            opciones.preserveState !== false) ||
        opciones.async === true ||
        opciones.prefetch === true ||
        (opciones.only?.length ?? 0) > 0 ||
        (opciones.except?.length ?? 0) > 0
    ) {
        return false;
    }

    try {
        const destino = new URL(href, window.location.href);

        return (
            destino.origin === window.location.origin &&
            destino.pathname !== window.location.pathname
        );
    } catch {
        return false;
    }
}
