import { router } from '@inertiajs/vue3';
import { computed, ref, shallowRef } from 'vue';
import type { Tour } from '@/lib/tours/tipos';

/**
 * Estado y motor del tour guiado: singleton a nivel de módulo (mismo patrón
 * que `useCurrentUrl`) para que el botón de ayuda, la página /ayuda y el
 * overlay global (montado una sola vez en `AppSidebarLayout.vue`) compartan
 * el mismo estado sin necesidad de una librería de store. Como vive fuera
 * de cualquier componente, sobrevive a las navegaciones de Inertia: eso es
 * lo que permite que un recorrido avance de una pantalla a otra.
 */
const CLAVE_STORAGE = 'tours-vistos';

/** Máximo que se espera a que Inertia termine de cambiar de pantalla. */
const ESPERA_NAVEGACION_MS = 10000;
/** Máximo que se espera a que aparezca el elemento de un paso. */
const ESPERA_ELEMENTO_MS = 2500;

export type EstadoTour = 'inactivo' | 'cargando' | 'listo';

const tourActivo = ref<Tour | null>(null);
const pasoActual = ref(0);
const estado = ref<EstadoTour>('inactivo');
const elemento = shallowRef<HTMLElement | null>(null);

/** Pantalla en la que se mostró cada paso (para poder volver con "Atrás"). */
const rutasResueltas = new Map<number, string>();
/** Invalida esperas pendientes cuando el usuario avanza/sale a mitad. */
let turno = 0;

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

function normalizarRuta(ruta: string): string {
    return ruta.replace(/\/+$/, '') || '/';
}

function rutaActual(): string {
    return normalizarRuta(window.location.pathname);
}

/**
 * Primer elemento que coincide con el selector y que realmente se ve: hay
 * pantallas con dos versiones del mismo bloque (escritorio y celular) y la
 * oculta mide 0×0.
 */
export function buscarElementoVisible(selector: string): HTMLElement | null {
    for (const candidato of document.querySelectorAll<HTMLElement>(selector)) {
        const caja = candidato.getBoundingClientRect();

        if (caja.width > 0 || caja.height > 0) {
            return candidato;
        }
    }

    return null;
}

function esperar(condicion: () => boolean, maximoMs: number): Promise<boolean> {
    return new Promise((resolver) => {
        const inicio = performance.now();

        const revisar = (): void => {
            if (condicion()) {
                resolver(true);

                return;
            }

            if (performance.now() - inicio >= maximoMs) {
                resolver(false);

                return;
            }

            window.setTimeout(revisar, 80);
        };

        revisar();
    });
}

/**
 * Pantalla en la que debe mostrarse el paso `indice`: su `ruta` explícita,
 * el destino del enlace señalado por `rutaDesde` (resuelto sobre la
 * pantalla actual) o, si no tiene ninguna, la del paso anterior.
 */
function resolverRuta(tour: Tour, indice: number): string | null {
    const paso = tour.pasos[indice];

    if (paso.ruta) {
        return normalizarRuta(paso.ruta);
    }

    const yaResuelta = rutasResueltas.get(indice);

    if (yaResuelta) {
        return yaResuelta;
    }

    if (paso.rutaDesde) {
        const enlace = buscarElementoVisible(paso.rutaDesde);
        const href = enlace?.getAttribute('href');

        return href
            ? normalizarRuta(new URL(href, window.location.origin).pathname)
            : null;
    }

    return rutasResueltas.get(indice - 1) ?? rutaActual();
}

async function irAPaso(indice: number, direccion: 1 | -1): Promise<void> {
    const tour = tourActivo.value;

    if (!tour) {
        return;
    }

    if (indice >= tour.pasos.length) {
        finalizar();

        return;
    }

    // Retroceder más allá del primer paso (porque los anteriores se
    // omitieron) equivale a quedarse en el primero disponible.
    if (indice < 0) {
        await irAPaso(0, 1);

        return;
    }

    const miTurno = ++turno;
    const paso = tour.pasos[indice];
    const destino = resolverRuta(tour, indice);

    pasoActual.value = indice;
    elemento.value = null;
    estado.value = 'cargando';

    // `rutaDesde` sin enlace (p. ej. lista vacía): no hay a dónde ir.
    if (paso.rutaDesde && !destino) {
        await irAPaso(indice + direccion, direccion);

        return;
    }

    const rutaDelPaso = destino ?? rutaActual();
    rutasResueltas.set(indice, rutaDelPaso);

    if (rutaDelPaso !== rutaActual()) {
        router.visit(rutaDelPaso, { preserveScroll: false });

        const llego = await esperar(
            () => rutaActual() === rutaDelPaso,
            ESPERA_NAVEGACION_MS,
        );

        if (miTurno !== turno) {
            return;
        }

        if (!llego) {
            // La navegación no se completó (sin conexión, error del
            // servidor): se explica el paso centrado en vez de colgarse.
            estado.value = 'listo';

            return;
        }
    }

    if (paso.selector) {
        const selector = paso.selector;

        await esperar(
            () => buscarElementoVisible(selector) !== null,
            ESPERA_ELEMENTO_MS,
        );

        if (miTurno !== turno) {
            return;
        }

        const encontrado = buscarElementoVisible(selector);

        if (!encontrado && paso.opcional) {
            await irAPaso(indice + direccion, direccion);

            return;
        }

        elemento.value = encontrado;
    }

    estado.value = 'listo';
}

function iniciar(tour: Tour): void {
    if (tour.pasos.length === 0) {
        return;
    }

    rutasResueltas.clear();
    tourActivo.value = tour;
    void irAPaso(0, 1);
}

function siguiente(): void {
    if (tourActivo.value && estado.value === 'listo') {
        void irAPaso(pasoActual.value + 1, 1);
    }
}

function anterior(): void {
    if (tourActivo.value && estado.value === 'listo' && pasoActual.value > 0) {
        void irAPaso(pasoActual.value - 1, -1);
    }
}

/** Salta al primer paso del siguiente módulo (recorrido completo). */
function saltarSeccion(): void {
    const tour = tourActivo.value;

    if (!tour || estado.value !== 'listo') {
        return;
    }

    const seccion = tour.pasos[pasoActual.value]?.seccion;
    const siguienteSeccion = tour.pasos.findIndex(
        (paso, indice) => indice > pasoActual.value && paso.seccion !== seccion,
    );

    void irAPaso(
        siguienteSeccion === -1 ? tour.pasos.length : siguienteSeccion,
        1,
    );
}

function finalizar(): void {
    turno++;

    if (tourActivo.value) {
        marcarVisto(tourActivo.value.id);
    }

    tourActivo.value = null;
    pasoActual.value = 0;
    estado.value = 'inactivo';
    elemento.value = null;
    rutasResueltas.clear();
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

    /** Secciones (módulos) del tour activo, en orden, sin repetir. */
    const secciones = computed(() => {
        const nombres: string[] = [];

        for (const { seccion } of tourActivo.value?.pasos ?? []) {
            if (seccion && !nombres.includes(seccion)) {
                nombres.push(seccion);
            }
        }

        return nombres;
    });

    /** Si después del módulo actual todavía queda otro. */
    const haySiguienteSeccion = computed(() => {
        const pasos = tourActivo.value?.pasos ?? [];
        const actual = pasos[pasoActual.value]?.seccion;

        return pasos
            .slice(pasoActual.value + 1)
            .some((p) => p.seccion !== actual && p.seccion !== 'Final');
    });

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
        estado: computed(() => estado.value),
        elemento: computed(() => elemento.value),
        secciones,
        haySiguienteSeccion,
        iniciar,
        siguiente,
        anterior,
        saltarSeccion,
        finalizar,
        haVisto,
    };
}
