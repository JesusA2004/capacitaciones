<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { computed, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';
import { ESTILO_CUADRANTE, INDICE_NIVEL } from '@/lib/nineBox';
import { cn } from '@/lib/utils';
import type {
    ClaveCuadranteNineBox,
    CuadranteNineBox,
    PersonaNineBox,
} from '@/types';

/**
 * Cuadrícula 3×3 Desempeño (columnas, bajo → alto) × Potencial (filas,
 * alto arriba). Las personas se pintan en UNA capa absoluta encima de las
 * celdas: cada avatar tiene su posición calculada y, al cambiar de periodo,
 * se DESLIZA a su nuevo cuadrante (transición de transform). Si una celda
 * no alcanza, el último lugar es un «+N» que abre el cuadrante.
 */
const props = defineProps<{
    personas: PersonaNineBox[];
    cuadrantes: CuadranteNineBox[];
    conteos: Record<ClaveCuadranteNineBox, number>;
    seleccionado: ClaveCuadranteNineBox | null;
    personaActiva: number | null;
    /** ids que coinciden con la búsqueda (null = sin búsqueda). */
    coincidencias: Set<number> | null;
}>();

const emit = defineEmits<{
    elegirPersona: [persona: PersonaNineBox];
    elegirCuadrante: [clave: ClaveCuadranteNineBox | null];
}>();

const lienzo = ref<HTMLElement | null>(null);
const ancho = ref(0);
const alto = ref(0);

useResizeObserver(lienzo, (entradas) => {
    const caja = entradas[0]?.contentRect;

    if (caja) {
        ancho.value = caja.width;
        alto.value = caja.height;
    }
});

const HUECO = 10;
const ENCABEZADO = 58;
const PIE = 26;
const MARGEN = 12;
const PASO = 40;

// Filas de arriba hacia abajo: potencial alto, medio, bajo.
const celdas = computed(() =>
    [...props.cuadrantes].sort(
        (a, b) =>
            INDICE_NIVEL[b.potencial] - INDICE_NIVEL[a.potencial] ||
            INDICE_NIVEL[a.desempeno] - INDICE_NIVEL[b.desempeno],
    ),
);

const geometria = computed(() => {
    const celdaAncho = Math.max(0, (ancho.value - HUECO * 2) / 3);
    const celdaAlto = Math.max(0, (alto.value - HUECO * 2) / 3);
    const porFila = Math.max(1, Math.floor((celdaAncho - MARGEN * 2 + 4) / PASO));
    const filas = Math.max(1, Math.floor((celdaAlto - ENCABEZADO - PIE + 4) / PASO));

    return { celdaAncho, celdaAlto, porFila, capacidad: porFila * filas };
});

type Ficha = {
    persona: PersonaNineBox;
    x: number;
    y: number;
    visible: boolean;
    retardo: number;
};

type Excedente = { clave: ClaveCuadranteNineBox; x: number; y: number; resto: number };

const distribucion = computed(() => {
    const { celdaAncho, celdaAlto, porFila, capacidad } = geometria.value;
    const fichas: Ficha[] = [];
    const excedentes: Excedente[] = [];
    const porCuadrante = new Map<ClaveCuadranteNineBox, PersonaNineBox[]>();

    for (const persona of props.personas) {
        if (persona.cuadrante) {
            const lista = porCuadrante.get(persona.cuadrante) ?? [];
            lista.push(persona);
            porCuadrante.set(persona.cuadrante, lista);
        }
    }

    for (const cuadrante of props.cuadrantes) {
        const lista = porCuadrante.get(cuadrante.clave) ?? [];
        const columna = INDICE_NIVEL[cuadrante.desempeno];
        const fila = 2 - INDICE_NIVEL[cuadrante.potencial];
        const origenX = columna * (celdaAncho + HUECO) + MARGEN;
        const origenY = fila * (celdaAlto + HUECO) + ENCABEZADO;
        const desborda = lista.length > capacidad;
        const visibles = desborda ? Math.max(0, capacidad - 1) : lista.length;
        const lugar = (i: number) => ({
            x: origenX + (i % porFila) * PASO,
            y: origenY + Math.floor(i / porFila) * PASO,
        });

        lista.forEach((persona, i) => {
            const posicion = lugar(Math.min(i, visibles));
            fichas.push({
                persona,
                ...posicion,
                visible: i < visibles,
                retardo: Math.min(i * 14, 320),
            });
        });

        if (desborda) {
            excedentes.push({ clave: cuadrante.clave, ...lugar(visibles), resto: lista.length - visibles });
        }
    }

    return { fichas, excedentes };
});

function nombreCorto(nombre: string): string {
    return nombre.split(/\s+/).slice(0, 2).join(' ');
}
</script>

<template>
    <div class="flex gap-3">
        <!-- Eje vertical: potencial -->
        <div class="flex w-6 shrink-0 flex-col items-center" aria-hidden="true">
            <div class="grid flex-1 grid-rows-3 gap-2.5">
                <span
                    v-for="nivel in ['Alto', 'Medio', 'Bajo']"
                    :key="nivel"
                    class="flex items-center justify-center text-[11px] font-medium text-muted-foreground [writing-mode:vertical-rl] rotate-180"
                    >{{ nivel }}</span
                >
            </div>
        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <div
                ref="lienzo"
                class="relative h-[clamp(480px,64vh,760px)]"
                role="group"
                aria-label="Matriz Nine Box: desempeño en columnas, potencial en filas"
            >
                <!-- Celdas -->
                <div class="grid h-full grid-cols-3 grid-rows-3 gap-2.5">
                    <button
                        v-for="(cuadrante, i) in celdas"
                        :key="cuadrante.clave"
                        type="button"
                        :aria-pressed="seleccionado === cuadrante.clave"
                        :aria-label="`${cuadrante.etiqueta}: ${conteos[cuadrante.clave] ?? 0} personas`"
                        :style="{ animationDelay: `${i * 40}ms` }"
                        :class="
                            cn(
                                'group/celda relative flex aparece-zoom cursor-pointer flex-col justify-between overflow-hidden rounded-2xl border border-border/70 p-3 text-left transicion-ui',
                                ESTILO_CUADRANTE[cuadrante.clave].fondo,
                                seleccionado === cuadrante.clave
                                    ? 'ring-2 ring-primary/60 ring-offset-2 ring-offset-background'
                                    : 'hover:shadow-md hover:brightness-[1.02]',
                                seleccionado !== null && seleccionado !== cuadrante.clave && 'opacity-70',
                            )
                        "
                        @click="emit('elegirCuadrante', seleccionado === cuadrante.clave ? null : cuadrante.clave)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-semibold"
                                    :class="ESTILO_CUADRANTE[cuadrante.clave].acento"
                                >
                                    {{ cuadrante.etiqueta }}
                                </p>
                                <p class="truncate text-[11px] text-muted-foreground">
                                    Desempeño {{ cuadrante.desempeno }} · potencial {{ cuadrante.potencial }}
                                </p>
                            </div>
                            <span
                                class="rounded-xl bg-card/70 px-2 py-0.5 text-xl leading-tight font-semibold tabular-nums shadow-xs"
                                :class="ESTILO_CUADRANTE[cuadrante.clave].acento"
                            >
                                <NumeroAnimado :valor="conteos[cuadrante.clave] ?? 0" :duracion="650" />
                            </span>
                        </div>
                        <p
                            class="line-clamp-1 text-[11px] text-muted-foreground opacity-0 transicion-ui group-hover/celda:opacity-100"
                        >
                            {{ cuadrante.descripcion }}
                        </p>
                    </button>
                </div>

                <!-- Personas: una sola capa para que se deslicen entre cuadrantes -->
                <TransitionGroup
                    tag="div"
                    class="pointer-events-none absolute inset-0"
                    enter-from-class="opacity-0 scale-50"
                    leave-to-class="opacity-0 scale-50"
                    enter-active-class="transition-[opacity,scale] duration-(--mov-lento)"
                    leave-active-class="transition-[opacity,scale] duration-(--mov-base)"
                >
                    <button
                        v-for="ficha in distribucion.fichas"
                        :key="ficha.persona.id"
                        type="button"
                        class="group pointer-events-auto absolute top-0 left-0 rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring motion-reduce:transition-none"
                        :class="[
                            ficha.visible ? 'cursor-pointer' : 'pointer-events-none',
                            personaActiva === ficha.persona.id && 'z-20',
                        ]"
                        :style="{
                            transform: `translate(${ficha.x}px, ${ficha.y}px) scale(${ficha.visible ? 1 : 0.4})`,
                            opacity: !ficha.visible ? 0 : coincidencias && !coincidencias.has(ficha.persona.id) ? 0.18 : 1,
                            transition: `transform 700ms var(--curva-salida) ${ficha.retardo}ms, opacity 300ms ease`,
                        }"
                        :tabindex="ficha.visible ? 0 : -1"
                        :aria-label="`${ficha.persona.nombre}: ${ficha.persona.sucursal ?? ''}`"
                        @click.stop="emit('elegirPersona', ficha.persona)"
                    >
                        <ColaboradorAvatar
                            :nombre="ficha.persona.nombre"
                            :foto-url="ficha.persona.foto_url"
                            tamano="sm"
                            :class="
                                cn(
                                    'ring-2 transition-transform duration-200 group-hover:scale-115 group-focus-visible:scale-115',
                                    ficha.persona.cuadrante ? ESTILO_CUADRANTE[ficha.persona.cuadrante].aro : 'ring-background',
                                    personaActiva === ficha.persona.id && 'scale-115 ring-primary',
                                )
                            "
                        />
                        <span
                            class="pointer-events-none absolute top-full left-1/2 z-30 mt-1 -translate-x-1/2 rounded-md bg-foreground px-1.5 py-0.5 text-[10px] whitespace-nowrap text-background opacity-0 shadow-md transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100"
                            >{{ nombreCorto(ficha.persona.nombre) }}</span
                        >
                    </button>
                    <button
                        v-for="excedente in distribucion.excedentes"
                        :key="`mas-${excedente.clave}`"
                        type="button"
                        class="pointer-events-auto absolute top-0 left-0 flex size-9 cursor-pointer items-center justify-center rounded-full bg-card text-[11px] font-semibold text-foreground shadow-sm ring-2 ring-border transition-transform duration-700 hover:scale-110"
                        :style="{ transform: `translate(${excedente.x}px, ${excedente.y}px)` }"
                        :aria-label="`Ver ${excedente.resto} personas más`"
                        @click.stop="emit('elegirCuadrante', excedente.clave)"
                    >
                        +{{ excedente.resto }}
                    </button>
                </TransitionGroup>
            </div>

            <!-- Eje horizontal: desempeño -->
            <div class="grid grid-cols-3 gap-2.5" aria-hidden="true">
                <span
                    v-for="nivel in ['Bajo', 'Medio', 'Alto']"
                    :key="nivel"
                    class="text-center text-[11px] font-medium text-muted-foreground"
                    >{{ nivel }}</span
                >
            </div>
            <p class="text-center text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Desempeño (datos de Reportes) →
            </p>
        </div>
    </div>
</template>
