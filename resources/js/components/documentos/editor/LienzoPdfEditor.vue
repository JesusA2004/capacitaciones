<script setup lang="ts">
import { Lock } from '@lucide/vue';
import { useResizeObserver } from '@vueuse/core';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import type { PaginaPdf } from '@/composables/usePdfDocumento';
import { cn } from '@/lib/utils';
import type { CampoEditor } from '@/types';

/**
 * Lienzo del editor para PDF: la página ORIGINAL de Jurídico se dibuja como
 * fondo bloqueado (pdf.js) y encima van las cajas de los espacios, en
 * milímetros (mismo sistema que el overlay del backend). El fondo nunca se
 * mueve; solo se mueven/redimensionan las cajas.
 *
 * Colores: verde = confirmado, amarillo = sugerencia por confirmar, rojo =
 * sin dato, gris = firma/huella/testigo (no lleva dato).
 */
const props = defineProps<{
    pagina: PaginaPdf | null;
    campos: CampoEditor[];
    seleccion: string[];
    zoom: number;
    cuadricula: boolean;
    regla: boolean;
    editable: boolean;
    dibujar: (
        numero: number,
        lienzo: HTMLCanvasElement,
        pxPorMm: number,
    ) => Promise<void>;
    resaltado: string | null;
}>();

const emit = defineEmits<{
    seleccionar: [fieldKey: string | null, aditivo: boolean];
    'antes-de-cambiar': [];
    mover: [cambios: { field_key: string; x: number; y: number }[]];
    redimensionar: [fieldKey: string, width: number, height: number];
    'soltar-variable': [clave: string, x: number, y: number];
}>();

const contenedor = useTemplateRef<HTMLElement>('contenedor');
const lienzo = useTemplateRef<HTMLCanvasElement>('lienzo');
const anchoDisponible = ref(800);
const guias = ref<{ x: number[]; y: number[] }>({ x: [], y: [] });

useResizeObserver(contenedor, (entradas) => {
    const ancho = entradas[0]?.contentRect.width ?? 800;

    if (Math.abs(ancho - anchoDisponible.value) > 4) {
        anchoDisponible.value = ancho;
    }
});

const pxPorMm = computed(() =>
    props.pagina
        ? (Math.max(320, anchoDisponible.value - 48) * props.zoom) /
          props.pagina.anchoMm
        : 3,
);

const delaPagina = computed(() =>
    props.campos.filter(
        (c) => c.page === props.pagina?.numero && c.x !== null && c.y !== null,
    ),
);

async function redibujar() {
    await nextTick();

    if (props.pagina && lienzo.value) {
        await props.dibujar(props.pagina.numero, lienzo.value, pxPorMm.value);
    }
}

watch(() => [props.pagina?.numero, pxPorMm.value], () => void redibujar(), {
    immediate: true,
});

function color(campo: CampoEditor): string {
    if (campo.zona !== 'dato') {
        return 'border-muted-foreground/60 bg-muted/40 text-muted-foreground';
    }

    if (campo.estado === 'confirmado' && (campo.source_key || campo.expression)) {
        return 'border-success bg-success-soft/50 text-success';
    }

    if (campo.estado === 'sugerido') {
        return 'border-warning bg-warning-soft/60 text-warning-foreground';
    }

    return 'border-danger bg-danger-soft/60 text-danger';
}

// ───────── Arrastrar / redimensionar (en mm, con guías) ─────────
const SNAP_MM = 1.2;

type Arrastre = {
    modo: 'mover' | 'redimensionar';
    fieldKey: string;
    inicioX: number;
    inicioY: number;
    origen: { field_key: string; x: number; y: number; w: number; h: number }[];
};

let arrastre: Arrastre | null = null;

function iniciar(evento: PointerEvent, campo: CampoEditor, modo: Arrastre['modo']) {
    if (!props.editable || campo.bloqueado) {
        emit('seleccionar', campo.field_key, evento.shiftKey);

        return;
    }

    evento.stopPropagation();
    evento.preventDefault();

    if (!props.seleccion.includes(campo.field_key)) {
        emit('seleccionar', campo.field_key, evento.shiftKey);
    }

    const llaves =
        modo === 'mover' && props.seleccion.includes(campo.field_key)
            ? props.seleccion
            : [campo.field_key];

    emit('antes-de-cambiar');
    arrastre = {
        modo,
        fieldKey: campo.field_key,
        inicioX: evento.clientX,
        inicioY: evento.clientY,
        origen: props.campos
            .filter((c) => llaves.includes(c.field_key) && !c.bloqueado)
            .map((c) => ({
                field_key: c.field_key,
                x: c.x ?? 0,
                y: c.y ?? 0,
                w: c.width ?? 40,
                h: c.height ?? 5,
            })),
    };

    window.addEventListener('pointermove', alMover);
    window.addEventListener('pointerup', terminar, { once: true });
}

function ajustar(valor: number, candidatos: number[]): [number, number | null] {
    for (const candidato of candidatos) {
        if (Math.abs(valor - candidato) <= SNAP_MM) {
            return [candidato, candidato];
        }
    }

    return [Math.round(valor * 2) / 2, null];
}

function bordesAjeno(): { x: number[]; y: number[] } {
    const propios = new Set(arrastre?.origen.map((o) => o.field_key));
    const otros = delaPagina.value.filter((c) => !propios.has(c.field_key));

    return {
        x: otros.flatMap((c) => [c.x ?? 0, (c.x ?? 0) + (c.width ?? 0)]),
        y: otros.flatMap((c) => [c.y ?? 0, (c.y ?? 0) + (c.height ?? 0)]),
    };
}

function alMover(evento: PointerEvent) {
    if (!arrastre || !props.pagina) {
        return;
    }

    const dx = (evento.clientX - arrastre.inicioX) / pxPorMm.value;
    const dy = (evento.clientY - arrastre.inicioY) / pxPorMm.value;
    const bordes = bordesAjeno();
    const principal = arrastre.origen.find(
        (o) => o.field_key === arrastre?.fieldKey,
    );

    if (!principal) {
        return;
    }

    if (arrastre.modo === 'redimensionar') {
        const [derecha, guiaX] = ajustar(principal.x + principal.w + dx, bordes.x);
        const [abajo, guiaY] = ajustar(principal.y + principal.h + dy, bordes.y);
        guias.value = { x: guiaX !== null ? [guiaX] : [], y: guiaY !== null ? [guiaY] : [] };
        emit(
            'redimensionar',
            principal.field_key,
            Math.max(3, derecha - principal.x),
            Math.max(3, abajo - principal.y),
        );

        return;
    }

    const [x, guiaX] = ajustar(principal.x + dx, bordes.x);
    const [y, guiaY] = ajustar(principal.y + dy, bordes.y);
    const ddx = x - principal.x;
    const ddy = y - principal.y;
    guias.value = { x: guiaX !== null ? [guiaX] : [], y: guiaY !== null ? [guiaY] : [] };

    emit(
        'mover',
        arrastre.origen.map((o) => ({
            field_key: o.field_key,
            x: Math.min(Math.max(0, o.x + ddx), (props.pagina?.anchoMm ?? 216) - o.w),
            y: Math.min(Math.max(0, o.y + ddy), (props.pagina?.altoMm ?? 280) - o.h),
        })),
    );
}

function terminar() {
    window.removeEventListener('pointermove', alMover);
    arrastre = null;
    guias.value = { x: [], y: [] };
}

// ───────── Soltar una variable del panel ─────────
function alSoltar(evento: DragEvent) {
    const clave = evento.dataTransfer?.getData('text/x-variable');
    const capa = evento.currentTarget as HTMLElement;

    if (!clave || !props.editable) {
        return;
    }

    const caja = capa.getBoundingClientRect();
    emit(
        'soltar-variable',
        clave,
        Math.max(0, (evento.clientX - caja.left) / pxPorMm.value - 2),
        Math.max(0, (evento.clientY - caja.top) / pxPorMm.value - 2.5),
    );
}

const marcasRegla = computed(() =>
    props.pagina
        ? Array.from({ length: Math.floor(props.pagina.anchoMm / 10) + 1 }, (_, i) => i * 10)
        : [],
);
</script>

<template>
    <div ref="contenedor" class="flex w-full min-w-0 justify-center overflow-auto p-6">
        <div v-if="pagina" class="relative shrink-0">
            <!-- Regla superior (cm) -->
            <div
                v-if="regla"
                class="pointer-events-none absolute -top-5 left-0 h-4 text-[9px] text-muted-foreground"
                :style="{ width: `${pagina.anchoMm * pxPorMm}px` }"
            >
                <span
                    v-for="mm in marcasRegla"
                    :key="mm"
                    class="absolute top-0 border-l border-muted-foreground/40 pl-0.5"
                    :style="{ left: `${mm * pxPorMm}px` }"
                    >{{ mm / 10 }}</span
                >
            </div>

            <div
                class="relative bg-white shadow-md ring-1 ring-black/10"
                :style="{
                    width: `${pagina.anchoMm * pxPorMm}px`,
                    height: `${pagina.altoMm * pxPorMm}px`,
                }"
            >
                <canvas ref="lienzo" class="pointer-events-none block select-none" />

                <!-- Cuadrícula de 5 mm (solo guía visual, nunca sale en el PDF) -->
                <div
                    v-if="cuadricula"
                    class="pointer-events-none absolute inset-0 opacity-40"
                    :style="{
                        backgroundImage:
                            'linear-gradient(to right, var(--border) 1px, transparent 1px), linear-gradient(to bottom, var(--border) 1px, transparent 1px)',
                        backgroundSize: `${5 * pxPorMm}px ${5 * pxPorMm}px`,
                    }"
                />

                <div
                    class="absolute inset-0"
                    @pointerdown.self="emit('seleccionar', null, false)"
                    @dragover.prevent
                    @drop.prevent="alSoltar"
                >
                    <div
                        v-for="campo in delaPagina"
                        :key="campo.field_key"
                        role="button"
                        tabindex="0"
                        :aria-label="campo.label ?? campo.field_key"
                        :class="
                            cn(
                                'group absolute flex items-center overflow-hidden rounded-[2px] border-2 px-0.5 text-[10px] leading-none font-medium',
                                color(campo),
                                seleccion.includes(campo.field_key) && 'ring-2 ring-primary ring-offset-1',
                                resaltado === campo.field_key && 'animate-pulse ring-2 ring-danger',
                                editable && !campo.bloqueado ? 'cursor-move' : 'cursor-pointer',
                            )
                        "
                        :style="{
                            left: `${(campo.x ?? 0) * pxPorMm}px`,
                            top: `${(campo.y ?? 0) * pxPorMm}px`,
                            width: `${(campo.width ?? 40) * pxPorMm}px`,
                            height: `${(campo.height ?? 5) * pxPorMm}px`,
                            justifyContent:
                                campo.text_align === 'C'
                                    ? 'center'
                                    : campo.text_align === 'R'
                                      ? 'flex-end'
                                      : 'flex-start',
                        }"
                        @pointerdown="iniciar($event, campo, 'mover')"
                    >
                        <Lock v-if="campo.bloqueado" class="mr-0.5 size-2.5 shrink-0" />
                        <span class="truncate">{{
                            campo.zona !== 'dato'
                                ? campo.label ?? 'Firma'
                                : campo.label ?? 'Sin dato'
                        }}</span>
                        <span
                            v-if="editable && !campo.bloqueado && seleccion.includes(campo.field_key)"
                            class="absolute right-0 bottom-0 size-2.5 cursor-se-resize rounded-tl-sm bg-primary"
                            aria-hidden="true"
                            @pointerdown="iniciar($event, campo, 'redimensionar')"
                        />
                    </div>

                    <!-- Guías de alineación al arrastrar -->
                    <div
                        v-for="x in guias.x"
                        :key="`gx-${x}`"
                        class="pointer-events-none absolute top-0 bottom-0 w-px bg-primary"
                        :style="{ left: `${x * pxPorMm}px` }"
                    />
                    <div
                        v-for="y in guias.y"
                        :key="`gy-${y}`"
                        class="pointer-events-none absolute right-0 left-0 h-px bg-primary"
                        :style="{ top: `${y * pxPorMm}px` }"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
