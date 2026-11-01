import { router } from '@inertiajs/vue3';

/** Por debajo de esto la respuesta llega sin que se note: no se atenúa. */
const RETRASO_MS = 120;

/**
 * Filtrar o paginar conserva la página y solo cambia las filas: mientras
 * llegan, toda tabla se atenúa (`data-tablas-actualizando`, ver app.css). Así
 * nadie tiene que declararlo tabla por tabla. Las recargas en segundo plano
 * (`async`) no cuentan: nadie las pidió.
 */
export function initializeActualizacionTablas(): void {
    let temporizador: number | undefined;

    router.on('start', (event) => {
        const visita = event.detail.visit;

        if (
            visita.method !== 'get' ||
            visita.preserveState !== true ||
            visita.async
        ) {
            return;
        }

        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(() => {
            document.documentElement.setAttribute(
                'data-tablas-actualizando',
                '',
            );
        }, RETRASO_MS);
    });

    router.on('finish', () => {
        window.clearTimeout(temporizador);
        document.documentElement.removeAttribute('data-tablas-actualizando');
    });
}
