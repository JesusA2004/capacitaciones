<script setup lang="ts">
import {
    ChevronDown,
    ChevronUp,
    LocateFixed,
    Maximize2,
    Minus,
    Plus,
} from '@lucide/vue';
import { useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import TarjetaNodoOrganigrama from '@/components/Organigrama/TarjetaNodoOrganigrama.vue';
import {
    calcularLayout,
    OPCIONES_LAYOUT,
    zoomParaAjustar,
} from '@/lib/organigramaLayout';
import type { NodoOrganigramaPersona, RegionOrganigrama } from '@/types';

/**
 * Lienzo del organigrama editable: posiciones calculadas (lib/organigramaLayout:
 * cada nivel en su fila, padre centrado, hojas apiladas, conectores
 * ortogonales en SVG) + zoom/arrastre suaves.
 *
 * - Arrastrar el fondo mueve el lienzo; rueda = desplazar; Ctrl/⌘ + rueda (o
 *   pellizco en trackpad) = zoom hacia el cursor.
 * - Nada de flexbox anidado: así ninguna tarjeta se encima ni se desalinea.
 */
const props = defineProps<{
    nodos: NodoOrganigramaPersona[];
    regiones: RegionOrganigrama[];
    seleccion: string | null;
    /** clave → personas en su rama (solo nodos con hijos). */
    aCargo: Map<string, number>;
    colapsados: Set<string>;
    coincidencias: Set<string> | null;
}>();

const emit = defineEmits<{
    seleccionar: [clave: string];
    alternar: [clave: string];
}>();

const ZOOM_MIN = 0.2;
const ZOOM_MAX = 1.5;

const caja = ref<HTMLElement | null>(null);
const tamanoCaja = ref({ ancho: 0, alto: 0 });
const escala = ref(1);
const tx = ref(0);
const ty = ref(0);
const suave = ref(false);
const arrastrando = ref(false);

useResizeObserver(caja, (entradas) => {
    const rect = entradas[0]?.contentRect;

    if (rect) {
        tamanoCaja.value = { ancho: rect.width, alto: rect.height };
    }
});

const layout = computed(() => {
    const conRamaOculta = new Set(
        [...props.colapsados].filter((clave) => props.aCargo.has(clave)),
    );

    return calcularLayout(
        props.nodos.map((n) => ({ clave: n.clave, padre: n.padre })),
        OPCIONES_LAYOUT,
        conRamaOculta,
    );
});

const tarjetas = computed(() =>
    props.nodos.flatMap((nodo) => {
        const pos = layout.value.posiciones.get(nodo.clave);

        return pos ? [{ nodo, pos }] : [];
    }),
);

const raices = computed(() => {
    const claves = new Set(props.nodos.map((n) => n.clave));

    return new Set(
        props.nodos
            .filter((n) => !n.padre || !claves.has(n.padre))
            .map((n) => n.clave),
    );
});

function animar(): void {
    suave.value = true;
    window.setTimeout(() => (suave.value = false), 260);
}

function fijarEscala(valor: number, centroX?: number, centroY?: number): void {
    const nueva = Math.min(
        ZOOM_MAX,
        Math.max(ZOOM_MIN, Math.round(valor * 100) / 100),
    );
    const cx = centroX ?? tamanoCaja.value.ancho / 2;
    const cy = centroY ?? tamanoCaja.value.alto / 2;
    // Mantiene fijo el punto bajo el cursor (o el centro).
    tx.value = cx - ((cx - tx.value) / escala.value) * nueva;
    ty.value = cy - ((cy - ty.value) / escala.value) * nueva;
    escala.value = nueva;
}

function acercar(): void {
    animar();
    fijarEscala(escala.value * 1.2);
}

function alejar(): void {
    animar();
    fijarEscala(escala.value / 1.2);
}

function ajustar(): void {
    animar();
    const nueva = zoomParaAjustar(
        { ancho: layout.value.ancho, alto: layout.value.alto },
        tamanoCaja.value,
        ZOOM_MIN,
        1,
    );
    escala.value = nueva;
    tx.value = (tamanoCaja.value.ancho - layout.value.ancho * nueva) / 2;
    ty.value = 72;
}

/** Centra un nodo en el recuadro sin cambiar el zoom (mínimo 0.6). */
function enfocar(clave: string): void {
    const posicion = layout.value.posiciones.get(clave);

    if (!posicion) {
        return;
    }

    animar();
    escala.value = Math.max(escala.value, 0.6);
    // El panel lateral (28rem) tapa la derecha en escritorio: se centra en
    // lo que queda visible.
    const visible =
        tamanoCaja.value.ancho > 900
            ? tamanoCaja.value.ancho - 448
            : tamanoCaja.value.ancho;
    tx.value = visible / 2 - (posicion.x + posicion.ancho / 2) * escala.value;
    ty.value = Math.min(
        72,
        tamanoCaja.value.alto / 3 - posicion.y * escala.value,
    );
}

/** Al abrir: legible (≥ 0.6) y centrado sobre la cabeza del árbol. */
function vistaInicial(): void {
    const ajuste = zoomParaAjustar(
        { ancho: layout.value.ancho, alto: layout.value.alto },
        tamanoCaja.value,
        ZOOM_MIN,
        1,
    );
    escala.value = Math.max(0.6, ajuste);
    const primera = [...raices.value][0];
    const posicion = primera ? layout.value.posiciones.get(primera) : undefined;
    tx.value = posicion
        ? tamanoCaja.value.ancho / 2 -
          (posicion.x + posicion.ancho / 2) * escala.value
        : 24;
    ty.value = 72;
}

onMounted(async () => {
    await nextTick();
    const rect = caja.value?.getBoundingClientRect();

    if (rect) {
        tamanoCaja.value = { ancho: rect.width, alto: rect.height };
    }

    vistaInicial();
});

defineExpose({ enfocar, ajustar });

// Al buscar, se enfoca la primera coincidencia.
watch(
    () => props.coincidencias,
    (valor) => {
        const primera = valor ? [...valor][0] : undefined;

        if (primera) {
            void nextTick(() => enfocar(primera));
        }
    },
);

// --- Arrastre y rueda ---
let inicio = { x: 0, y: 0, tx: 0, ty: 0 };

function alPresionar(evento: PointerEvent): void {
    if (
        evento.button !== 0 ||
        (evento.target as HTMLElement).closest('[data-nodo],[data-control]')
    ) {
        return;
    }

    arrastrando.value = true;
    inicio = {
        x: evento.clientX,
        y: evento.clientY,
        tx: tx.value,
        ty: ty.value,
    };
    (evento.currentTarget as HTMLElement).setPointerCapture(evento.pointerId);
}

function alMover(evento: PointerEvent): void {
    if (!arrastrando.value) {
        return;
    }

    tx.value = inicio.tx + (evento.clientX - inicio.x);
    ty.value = inicio.ty + (evento.clientY - inicio.y);
}

function alSoltar(evento: PointerEvent): void {
    arrastrando.value = false;
    (evento.currentTarget as HTMLElement).releasePointerCapture?.(
        evento.pointerId,
    );
}

function alRodar(evento: WheelEvent): void {
    evento.preventDefault();

    if (evento.ctrlKey || evento.metaKey) {
        const rect = caja.value?.getBoundingClientRect();
        const factor = Math.exp(-evento.deltaY * 0.0025);
        fijarEscala(
            escala.value * factor,
            rect ? evento.clientX - rect.left : undefined,
            rect ? evento.clientY - rect.top : undefined,
        );

        return;
    }

    tx.value -= evento.deltaX;
    ty.value -= evento.deltaY;
}

const transformacion = computed(
    () => `translate(${tx.value}px, ${ty.value}px) scale(${escala.value})`,
);

const LEYENDA = [
    { etiqueta: 'Q1', clase: 'bg-info-soft text-info border-info/30' },
    { etiqueta: 'Q3', clase: 'bg-crema text-bronce border-bronce/30' },
    {
        etiqueta: 'Por clasificar',
        clase: 'bg-warning-soft text-warning border-warning/40 border-dashed',
    },
    {
        etiqueta: 'Vacante',
        clase: 'bg-muted text-muted-foreground border-input border-dashed',
    },
    {
        etiqueta: 'Cubre temporalmente',
        clase: 'bg-menta text-primary border-esmeralda/60 border-dashed',
    },
];
</script>

<template>
    <div
        ref="caja"
        class="relative h-[72vh] min-h-[28rem] overflow-hidden rounded-3xl border border-border bg-background bg-[radial-gradient(circle,var(--pastel-borde)_1px,transparent_1px)] [background-size:22px_22px]"
        :class="arrastrando ? 'cursor-grabbing' : 'cursor-grab'"
        @pointerdown="alPresionar"
        @pointermove="alMover"
        @pointerup="alSoltar"
        @pointercancel="alSoltar"
        @wheel="alRodar"
    >
        <!-- Leyenda y controles -->
        <div
            data-control
            class="pointer-events-none absolute inset-x-3 top-3 z-20 flex items-start justify-between gap-2"
        >
            <div
                class="pointer-events-auto hidden flex-wrap items-center gap-1.5 rounded-2xl border border-border bg-card/95 px-3 py-2 text-[11px] shadow-sm backdrop-blur lg:flex"
            >
                <span
                    v-for="l in LEYENDA"
                    :key="l.etiqueta"
                    class="rounded-full border px-2 py-0.5 font-semibold"
                    :class="l.clase"
                    >{{ l.etiqueta }}</span
                >
                <span class="ml-1 text-muted-foreground"
                    >Arrastra para moverte · Ctrl + rueda para zoom</span
                >
            </div>
            <div
                class="pointer-events-auto ml-auto flex items-center gap-0.5 rounded-2xl border border-border bg-card/95 p-1 shadow-md backdrop-blur"
            >
                <button
                    type="button"
                    class="flex size-8 items-center justify-center rounded-xl hover:bg-muted"
                    title="Alejar"
                    aria-label="Alejar"
                    @click="alejar"
                >
                    <Minus class="size-4" />
                </button>
                <button
                    type="button"
                    class="h-8 min-w-14 rounded-xl px-2 text-xs font-semibold tabular-nums hover:bg-muted"
                    title="Ver al 100 %"
                    @click="
                        animar();
                        fijarEscala(1);
                    "
                >
                    {{ Math.round(escala * 100) }}%
                </button>
                <button
                    type="button"
                    class="flex size-8 items-center justify-center rounded-xl hover:bg-muted"
                    title="Acercar"
                    aria-label="Acercar"
                    @click="acercar"
                >
                    <Plus class="size-4" />
                </button>
                <span class="mx-0.5 h-5 w-px bg-border" />
                <button
                    type="button"
                    class="flex h-8 items-center gap-1.5 rounded-xl px-2.5 text-xs font-medium hover:bg-muted"
                    title="Ver todo el organigrama"
                    @click="ajustar"
                >
                    <Maximize2 class="size-3.5" />
                    Ver todo
                </button>
                <button
                    v-if="seleccion"
                    type="button"
                    class="flex h-8 items-center gap-1.5 rounded-xl px-2.5 text-xs font-medium hover:bg-muted"
                    title="Centrar en el seleccionado"
                    @click="enfocar(seleccion)"
                >
                    <LocateFixed class="size-3.5" />
                    Centrar
                </button>
            </div>
        </div>

        <div
            class="absolute top-0 left-0 origin-top-left will-change-transform"
            :class="suave ? 'transition-transform duration-250 ease-out' : ''"
            :style="{
                transform: transformacion,
                width: `${layout.ancho}px`,
                height: `${layout.alto}px`,
            }"
        >
            <svg
                class="pointer-events-none absolute top-0 left-0 overflow-visible"
                :width="layout.ancho"
                :height="layout.alto"
                aria-hidden="true"
            >
                <path
                    v-for="(d, i) in layout.conectores"
                    :key="i"
                    :d="d"
                    class="fill-none stroke-oro"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>

            <template v-for="{ nodo, pos } in tarjetas" :key="nodo.clave">
                <div
                    data-nodo
                    class="absolute"
                    :style="{
                        left: `${pos.x}px`,
                        top: `${pos.y}px`,
                        width: `${pos.ancho}px`,
                        height: `${pos.alto}px`,
                    }"
                >
                    <TarjetaNodoOrganigrama
                        :nodo="nodo"
                        :regiones="regiones"
                        :compacta="pos.apilado"
                        :seleccionada="seleccion === nodo.clave"
                        :raiz="raices.has(nodo.clave) && !nodo.padre"
                        :resaltada="coincidencias?.has(nodo.clave) ?? false"
                        :atenuada="
                            coincidencias !== null &&
                            !coincidencias.has(nodo.clave)
                        "
                        @seleccionar="emit('seleccionar', nodo.clave)"
                    />
                    <button
                        v-if="aCargo.has(nodo.clave)"
                        type="button"
                        class="absolute left-1/2 z-10 flex h-6 -translate-x-1/2 items-center gap-1 rounded-full border border-oro/60 bg-card px-2.5 text-[11px] font-semibold text-primary shadow-sm transition-colors hover:bg-salvia"
                        :style="{ top: `${OPCIONES_LAYOUT.alto + 6}px` }"
                        :aria-expanded="!colapsados.has(nodo.clave)"
                        :aria-label="
                            colapsados.has(nodo.clave)
                                ? 'Mostrar a quienes le reportan'
                                : 'Ocultar a quienes le reportan'
                        "
                        @click.stop="emit('alternar', nodo.clave)"
                    >
                        <ChevronDown
                            v-if="colapsados.has(nodo.clave)"
                            class="size-3.5"
                        />
                        <ChevronUp v-else class="size-3.5" />
                        {{ aCargo.get(nodo.clave) }}
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
