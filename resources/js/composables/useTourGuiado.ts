import { computed, ref } from 'vue';
import type { Tour } from '@/lib/tours/tipos';

/**
 * Estado del tour guiado: singleton a nivel de módulo (mismo patrón que
 * `useCurrentUrl`) para que el botón de ayuda de cualquier página y el
 * overlay global (montado una sola vez en `AppSidebarLayout.vue`) compartan
 * el mismo estado sin necesidad de una librería de store.
 */
const CLAVE_STORAGE = 'tours-vistos';

const tourActivo = ref<Tour | null>(null);
const pasoActual = ref(0);

function leerVistos(): string[] {
    try {
        const crudo = localStorage.getItem(CLAVE_STORAGE);

        return crudo ? (JSON.parse(crudo) as string[]) : [];
    } catch {
        return [];
    }
}

function marcarVisto(id: string): void {
    try {
        const vistos = new Set(leerVistos());
        vistos.add(id);
        localStorage.setItem(CLAVE_STORAGE, JSON.stringify([...vistos]));
    } catch {
        // localStorage no disponible (modo privado, etc.): no es crítico.
    }
}

const CLAVE_PENDIENTE = 'tour-pendiente';

/**
 * Permite que la página de Ayuda diga "al llegar a esta ruta, inicia este
 * tour" antes de navegar — así "Ver cómo funciona" hace ambas cosas en un
 * solo clic en vez de obligar a un segundo clic sobre el botón flotante.
 */
export function programarTourPendiente(href: string, tourId: string): void {
    try {
        sessionStorage.setItem(
            CLAVE_PENDIENTE,
            JSON.stringify({ href, tourId }),
        );
    } catch {
        // sessionStorage no disponible: simplemente no se autoinicia.
    }
}

/**
 * Consume (una sola vez) el tour pendiente si la ruta coincide con la
 * actual. Devuelve `null` si no hay nada pendiente o la ruta no coincide.
 */
export function tomarTourPendiente(pathname: string): string | null {
    try {
        const crudo = sessionStorage.getItem(CLAVE_PENDIENTE);

        if (!crudo) {
return null;
}

        sessionStorage.removeItem(CLAVE_PENDIENTE);
        const { href, tourId } = JSON.parse(crudo) as {
            href: string;
            tourId: string;
        };

        return href === pathname ? tourId : null;
    } catch {
        return null;
    }
}

export function useTourGuiado() {
    const paso = computed(
        () => tourActivo.value?.pasos[pasoActual.value] ?? null,
    );
    const totalPasos = computed(() => tourActivo.value?.pasos.length ?? 0);
    const esUltimoPaso = computed(
        () => pasoActual.value >= totalPasos.value - 1,
    );
    const esPrimerPaso = computed(() => pasoActual.value === 0);

    function iniciar(tour: Tour): void {
        if (tour.pasos.length === 0) {
return;
}

        tourActivo.value = tour;
        pasoActual.value = 0;
    }

    function siguiente(): void {
        if (!tourActivo.value) {
return;
}

        if (esUltimoPaso.value) {
            finalizar();

            return;
        }

        pasoActual.value++;
    }

    function anterior(): void {
        if (pasoActual.value > 0) {
pasoActual.value--;
}
    }

    function finalizar(): void {
        if (tourActivo.value) {
marcarVisto(tourActivo.value.id);
}

        tourActivo.value = null;
        pasoActual.value = 0;
    }

    function haVisto(id: string): boolean {
        return leerVistos().includes(id);
    }

    return {
        tourActivo: computed(() => tourActivo.value),
        paso,
        pasoActual: computed(() => pasoActual.value),
        totalPasos,
        esUltimoPaso,
        esPrimerPaso,
        iniciar,
        siguiente,
        anterior,
        finalizar,
        haVisto,
    };
}
