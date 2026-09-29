import { router } from '@inertiajs/vue3';
import { computed, ref, shallowRef } from 'vue';
import {
    avisoSelectorFaltante,
    decisionSinElemento,
    esperaParaElemento,
} from '@/lib/tours/motor';
import type { EstadoTour } from '@/lib/tours/motor';
import type { Tour } from '@/lib/tours/tipos';

/**
 * Estado y motor del tour guiado: singleton a nivel de módulo (mismo patrón
 * que `useCurrentUrl`) para que el botón de ayuda, la página /ayuda y el
 * overlay global (montado una sola vez en `AppSidebarLayout.vue`) compartan
 * el mismo estado. Como vive fuera de cualquier componente, sobrevive a las
 * navegaciones de Inertia: eso permite avanzar de una pantalla a otra.
 *
 * Máquina de estados (lib/tours/motor.ts, EstadoTour):
 *
 *   idle ─iniciar→ navigating ─(Inertia terminó)→ waiting-target
 *        ─(elemento encontrado y ya a la vista, o se agotó la espera)→ showing
 *   showing ─Siguiente/Atrás→ navigating | waiting-target → showing …
 *   cualquiera ─Salir/Esc/X/Terminar→ finishing → idle   (inmediato)
 *
 * Siguiente/Atrás solo actúan en `showing` (un doble clic no avanza dos
 * pasos). "Saltar paso" (en el aviso de carga) sí actúa mientras carga. Un
 * `turno` invalida cualquier espera pendiente cuando el usuario avanza o
 * sale, y un `finally` garantiza que nunca quede cargando para siempre.
 */
const CLAVE_STORAGE = 'tours-vistos';

/** Máximo que se espera a que Inertia termine de cambiar de pantalla. */
const ESPERA_NAVEGACION_MS = 10000;
/** Máximo que se espera a que termine el scroll hacia el elemento. */
const ESPERA_SCROLL_MS = 700;
/** Alto que se reserva para la caja de la guía (con su separación). */
const ESPACIO_CAJA = 290;
/** Dónde queda el borde superior del elemento al llevarlo a la vista. */
const MARGEN_SUPERIOR = 72;
/** Dos pulsaciones de Siguiente/Atrás más juntas que esto son un doble clic. */
const PAUSA_ENTRE_PULSACIONES_MS = 350;

const tourActivo = ref<Tour | null>(null);
const pasoActual = ref(0);
const estado = ref<EstadoTour>('idle');
const elemento = shallowRef<HTMLElement | null>(null);

/** Pantalla en la que se mostró cada paso (para poder volver con "Atrás"). */
const rutasResueltas = new Map<number, string>();
/** Invalida esperas pendientes cuando el usuario avanza/sale a mitad. */
let turno = 0;
/**
 * Pantalla actual y cuándo terminó de cargar: el tiempo de espera de los
 * elementos se cuenta desde aquí (ver esperaParaElemento en motor.ts).
 */
let llegada: { ruta: string; ms: number } | null = null;
/** Dónde estaba el usuario al iniciar, para regresarlo al terminar. */
let origen: { ruta: string; scrollY: number } | null = null;

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

function prefiereSinMovimiento(): boolean {
    return (
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    );
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

/** Navega con Inertia y espera a que termine (o se agote el tiempo). */
function navegar(ruta: string): Promise<boolean> {
    return new Promise((resolver) => {
        let resuelto = false;
        const terminar = (ok: boolean): void => {
            if (!resuelto) {
                resuelto = true;
                resolver(ok);
            }
        };

        window.setTimeout(
            () => terminar(rutaActual() === ruta),
            ESPERA_NAVEGACION_MS,
        );
        router.visit(ruta, {
            preserveScroll: false,
            onFinish: () => terminar(rutaActual() === ruta),
        });
    });
}

/**
 * Lleva el elemento al centro de la pantalla y espera a que el scroll se
 * asiente (la posición no cambia en 3 cuadros seguidos) antes de calcular
 * dónde va la caja: nunca se posiciona con el rect previo al scroll.
 */
async function llevarAVista(nodo: HTMLElement): Promise<void> {
    const caja = nodo.getBoundingClientRect();
    const alto = window.innerHeight;
    const yaVisible = caja.top >= 0 && caja.bottom <= alto;
    const cabeConLaCaja = caja.height + ESPACIO_CAJA <= alto;
    const hayLugar =
        caja.top >= ESPACIO_CAJA || alto - caja.bottom >= ESPACIO_CAJA;

    // Ya se ve y queda espacio arriba o abajo para la caja: no se mueve.
    // Un elemento más alto que la pantalla solo necesita verse.
    if (yaVisible && (hayLugar || !cabeConLaCaja)) {
        return;
    }

    // Si el elemento y la caja caben juntos, se sube cerca del borde
    // superior (no al centro): así queda lugar debajo para la caja y no
    // hay que taparlo. scroll-margin-top funciona también dentro de
    // contenedores con scroll propio, no solo en la ventana.
    const margenAnterior = nodo.style.scrollMarginTop;
    nodo.style.scrollMarginTop = `${MARGEN_SUPERIOR}px`;
    nodo.scrollIntoView({
        behavior: prefiereSinMovimiento() ? 'auto' : 'smooth',
        block: 'start',
        inline: 'nearest',
    });
    nodo.style.scrollMarginTop = margenAnterior;

    let anterior = Number.NaN;
    let quietos = 0;

    await esperar(() => {
        const actual = nodo.getBoundingClientRect().top;
        quietos = Math.abs(actual - anterior) < 0.5 ? quietos + 1 : 0;
        anterior = actual;

        return quietos >= 3;
    }, ESPERA_SCROLL_MS);
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
    const vigente = (): boolean => miTurno === turno;

    // Síncrono, antes de cualquier await: un segundo clic ya no está en
    // "showing" y no avanza otro paso.
    pasoActual.value = indice;
    elemento.value = null;
    estado.value =
        destino && destino !== rutaActual() ? 'navigating' : 'waiting-target';

    try {
        // `rutaDesde` sin enlace (p. ej. lista vacía): no hay a dónde ir.
        if (paso.rutaDesde && !destino) {
            await irAPaso(indice + direccion, direccion);

            return;
        }

        const rutaDelPaso = destino ?? rutaActual();
        rutasResueltas.set(indice, rutaDelPaso);

        if (rutaDelPaso !== rutaActual()) {
            const llego = await navegar(rutaDelPaso);

            if (!vigente()) {
                return;
            }

            if (!llego) {
                // Sin conexión o error del servidor: se explica el paso
                // centrado en vez de colgarse.
                return;
            }
        }

        const pantalla: { ruta: string; ms: number } =
            llegada?.ruta === rutaDelPaso
                ? llegada
                : { ruta: rutaDelPaso, ms: performance.now() };
        llegada = pantalla;

        estado.value = 'waiting-target';

        if (!paso.selector) {
            return;
        }

        const selector = paso.selector;

        await esperar(
            () => buscarElementoVisible(selector) !== null,
            esperaParaElemento(pantalla.ms, performance.now()),
        );

        if (!vigente()) {
            return;
        }

        const encontrado = buscarElementoVisible(selector);

        if (!encontrado) {
            if (import.meta.env.DEV) {
                console.warn(avisoSelectorFaltante(tour.id, indice, paso));
            }

            if (decisionSinElemento(paso) === 'omitir') {
                await irAPaso(indice + direccion, direccion);
            }

            // Obligatorio sin elemento: paso centrado sin spotlight.
            return;
        }

        await llevarAVista(encontrado);

        if (vigente()) {
            elemento.value = encontrado;
        }
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn(`[tour "${tour.id}"] paso ${indice + 1}:`, error);
        }
    } finally {
        // Único punto que pasa a "showing": con el elemento a la vista, o
        // centrado si no apareció / la ruta no cargó / hubo un error. Nunca
        // queda cargando y los botones siempre vuelven a funcionar. Un paso
        // que ya no es el vigente (el usuario avanzó o salió) no toca nada.
        if (vigente() && tourActivo.value) {
            estado.value = 'showing';
        }
    }
}

function iniciar(tour: Tour): void {
    if (tour.pasos.length === 0) {
        return;
    }

    rutasResueltas.clear();
    llegada = null;
    origen = { ruta: rutaActual(), scrollY: window.scrollY };
    tourActivo.value = tour;
    void irAPaso(0, 1);
}

/**
 * Un doble clic no avanza dos pasos: si el siguiente paso ya está en la
 * misma pantalla, la transición termina antes del segundo clic, así que
 * además de exigir "showing" se ignora una segunda pulsación muy seguida.
 */
let ultimaPulsacion = 0;

function pulsacionValida(): boolean {
    const ahora = performance.now();

    if (ahora - ultimaPulsacion < PAUSA_ENTRE_PULSACIONES_MS) {
        return false;
    }

    ultimaPulsacion = ahora;

    return true;
}

/** Siguiente paso. Solo con el paso a la vista y sin doble clic. */
function siguiente(): void {
    if (tourActivo.value && estado.value === 'showing' && pulsacionValida()) {
        void irAPaso(pasoActual.value + 1, 1);
    }
}

function anterior(): void {
    if (
        tourActivo.value &&
        estado.value === 'showing' &&
        pasoActual.value > 0 &&
        pulsacionValida()
    ) {
        void irAPaso(pasoActual.value - 1, -1);
    }
}

/**
 * "Saltar paso" del aviso de carga: funciona aunque el paso actual siga
 * esperando (invalida la espera en curso y pasa al siguiente).
 */
function saltarPaso(): void {
    if (tourActivo.value && estado.value !== 'finishing') {
        void irAPaso(pasoActual.value + 1, 1);
    }
}

/** Salta al primer paso del siguiente módulo (recorrido completo). */
function saltarSeccion(): void {
    const tour = tourActivo.value;

    if (!tour || estado.value !== 'showing') {
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

/**
 * Terminar/Salir/Esc/X: cierra de inmediato desde cualquier estado (no
 * depende de que el elemento exista), recuerda el tour como visto y, si
 * el usuario sigue en la pantalla donde empezó, le regresa su scroll.
 */
function finalizar(): void {
    turno++;
    estado.value = 'finishing';

    if (tourActivo.value) {
        marcarVisto(tourActivo.value.id);
    }

    const regreso = origen;
    tourActivo.value = null;
    pasoActual.value = 0;
    elemento.value = null;
    rutasResueltas.clear();
    llegada = null;
    origen = null;
    estado.value = 'idle';

    if (regreso && regreso.ruta === rutaActual()) {
        window.scrollTo({ top: regreso.scrollY, behavior: 'auto' });
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
    const cargando = computed(
        () =>
            estado.value === 'navigating' || estado.value === 'waiting-target',
    );

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
        cargando,
        elemento: computed(() => elemento.value),
        secciones,
        haySiguienteSeccion,
        iniciar,
        siguiente,
        anterior,
        saltarPaso,
        saltarSeccion,
        finalizar,
        haVisto,
    };
}
