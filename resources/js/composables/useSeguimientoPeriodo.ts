import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Cambio de mes/semana (y filtros extra) en las pantallas de Seguimiento:
 * recarga SOLO los datos (prop diferida `datos` + `filtros`) y avisa con
 * `cargando` para pintar skeletons mientras Reportes contesta.
 */
export function useSeguimientoPeriodo(url: () => string) {
    const cargando = ref(false);

    function ir(consulta: Record<string, number | string | null>): void {
        const limpia: Record<string, number | string> = {};

        for (const [clave, valor] of Object.entries(consulta)) {
            if (valor !== null && valor !== '') {
                limpia[clave] = valor;
            }
        }

        router.get(url(), limpia, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['datos', 'filtros', 'sucursalId'],
            onStart: () => {
                cargando.value = true;
            },
            onFinish: () => {
                cargando.value = false;
            },
        });
    }

    return { cargando, ir };
}
