import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { update as actualizarModoNavegacion } from '@/routes/modo-navegacion';

/**
 * Modo de navegación (sección 1 de la reestructuración de MR. LANA PEOPLE):
 * separa la experiencia "colaborador" (portal personal) de "operativo"
 * (herramientas de RH/gerencia/dirección). El backend decide qué modos
 * tiene disponibles cada usuario según sus permisos — ver
 * App\Services\Navigation\NavigationService — este composable solo lee ese
 * resultado y ofrece cambiar de modo cuando el usuario tiene ambos.
 */
export function useNavegacion() {
    const page = usePage();

    const navegacion = computed(() => page.props.navegacion);
    const modoActual = computed(() => navegacion.value?.modoActual ?? 'operativo');
    const modosDisponibles = computed(() => navegacion.value?.modosDisponibles ?? []);
    const esColaborador = computed(() => modoActual.value === 'colaborador');
    const esOperativo = computed(() => modoActual.value === 'operativo');
    const tieneAmbosModos = computed(() => modosDisponibles.value.length > 1);

    function cambiarModo(modo: 'colaborador' | 'operativo') {
        if (modo === modoActual.value) {
            return;
        }

        router.post(
            actualizarModoNavegacion.url(),
            { modo },
            { preserveScroll: true },
        );
    }

    return {
        navegacion,
        modoActual,
        modosDisponibles,
        esColaborador,
        esOperativo,
        tieneAmbosModos,
        cambiarModo,
    };
}
