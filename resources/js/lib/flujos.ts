/**
 * Lleva a una sección de la misma pantalla (documento de salida, finiquito…) y le
 * da el foco; sin animación si la persona pidió reducir el movimiento.
 */
export function irASeccion(id: string): void {
    const seccion = document.getElementById(id);

    if (!seccion) {
        return;
    }

    const reducir = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    seccion.scrollIntoView({ behavior: reducir ? 'auto' : 'smooth' });
    seccion.setAttribute('tabindex', '-1');
    seccion.focus({ preventScroll: true });
}
