<script setup lang="ts">
import { Maximize2, Minus, Plus } from '@lucide/vue';
import { computed, nextTick, provide, ref } from 'vue';
import OrganigramaPersonaNodo from '@/components/Administracion/OrganigramaPersonaNodo.vue';
import { Button } from '@/components/ui/button';
import {
    CLAVE_DETALLE_ORGANIGRAMA,
    detallePorZoom,
    ESTILO_TIPO_PUESTO,
} from '@/lib/organigrama';
import type { NodoOrganigramaPersona } from '@/types';

/**
 * Organigrama por personas: mismo recuadro con scroll en ambas direcciones
 * que el árbol de puestos (OrganigramaArbol.vue).
 *
 * El zoom usa la propiedad CSS `zoom` (no `transform: scale`): así el área
 * de scroll se encoge junto con el árbol en vez de dejar un hueco del
 * tamaño original. Al alejar, las tarjetas cambian a un modo más simple
 * con foto y nombre grandes (ver detallePorZoom) para seguir leyéndose.
 */
const props = defineProps<{ nodos: NodoOrganigramaPersona[] }>();

const ZOOM_MINIMO = 0.3;
const ZOOM_MAXIMO = 1.4;

const zoom = ref(1);
const contenedor = ref<HTMLElement | null>(null);
const contenido = ref<HTMLElement | null>(null);

const detalle = computed(() => detallePorZoom(zoom.value));
provide(CLAVE_DETALLE_ORGANIGRAMA, detalle);

function fijarZoom(valor: number): void {
    zoom.value = Math.min(
        ZOOM_MAXIMO,
        Math.max(ZOOM_MINIMO, Math.round(valor * 100) / 100),
    );
}

function acercar() {
    fijarZoom(zoom.value + 0.1);
}

function alejar() {
    fijarZoom(zoom.value - 0.1);
}

function restablecer() {
    fijarZoom(1);
}

/** Acomoda todo el ancho del árbol dentro del recuadro visible. */
async function ajustar(): Promise<void> {
    // Se mide al 100% (con el detalle completo) para no ajustar sobre un
    // ancho ya reducido por el modo compacto.
    zoom.value = 1;
    await nextTick();

    const disponible = (contenedor.value?.clientWidth ?? 0) - 48;
    const natural = contenido.value?.scrollWidth ?? 0;

    if (disponible > 0 && natural > disponible) {
        fijarZoom(disponible / natural);
    }
}

const porClave = computed(
    () => new Map(props.nodos.map((nodo) => [nodo.clave, nodo])),
);

/** Hijos ordenados: primero los de mayor jerarquía, luego por nombre. */
const hijosPorPadre = computed(() => {
    const mapa = new Map<string, NodoOrganigramaPersona[]>();

    for (const nodo of props.nodos) {
        const padre =
            nodo.padre && porClave.value.has(nodo.padre) ? nodo.padre : '';
        mapa.set(padre, [...(mapa.get(padre) ?? []), nodo]);
    }

    for (const lista of mapa.values()) {
        lista.sort(
            (a, b) =>
                (a.puesto.nivel ?? 99) - (b.puesto.nivel ?? 99) ||
                (a.sucursal?.nombre ?? '').localeCompare(
                    b.sucursal?.nombre ?? '',
                ) ||
                (a.persona?.nombre ?? '').localeCompare(
                    b.persona?.nombre ?? '',
                ),
        );
    }

    return mapa;
});

const raices = computed(() => hijosPorPadre.value.get('') ?? []);

function obtenerHijos(clave: string): NodoOrganigramaPersona[] {
    return hijosPorPadre.value.get(clave) ?? [];
}

const leyenda = Object.values(ESTILO_TIPO_PUESTO);
</script>

<template>
    <div class="relative rounded-2xl border border-border/60 bg-muted/20">
        <div
            class="pointer-events-none absolute top-3 right-3 left-3 z-10 flex items-start justify-between gap-2"
        >
            <div
                class="pointer-events-auto hidden items-center gap-3 rounded-xl border border-border/60 bg-card/95 px-3 py-1.5 text-xs text-muted-foreground shadow-sm backdrop-blur lg:flex"
            >
                <span
                    v-for="tipo in leyenda"
                    :key="tipo.etiqueta"
                    class="inline-flex items-center gap-1.5"
                >
                    <span
                        class="h-2 w-4 rounded-full bg-gradient-to-r"
                        :class="tipo.franja"
                    />
                    {{ tipo.etiqueta }}
                </span>
            </div>

            <div
                class="pointer-events-auto ml-auto flex items-center gap-1 rounded-xl border border-border/60 bg-card/95 p-1 shadow-md backdrop-blur"
            >
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    title="Alejar"
                    @click="alejar"
                >
                    <Minus class="size-3.5" />
                </Button>
                <Button
                    variant="ghost"
                    title="Restablecer zoom"
                    class="h-7 w-auto px-2"
                    @click="restablecer"
                >
                    <span class="text-xs font-semibold tabular-nums"
                        >{{ Math.round(zoom * 100) }}%</span
                    >
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    title="Acercar"
                    @click="acercar"
                >
                    <Plus class="size-3.5" />
                </Button>
                <Button
                    variant="ghost"
                    class="h-7 gap-1 px-2 text-xs"
                    title="Ajustar al ancho de la pantalla"
                    @click="ajustar"
                >
                    <Maximize2 class="size-3.5" />
                    Ajustar
                </Button>
            </div>
        </div>

        <div
            ref="contenedor"
            class="max-h-[70vh] overflow-auto px-6 pt-16 pb-6"
        >
            <div
                ref="contenido"
                class="flex w-max min-w-full items-start justify-center gap-6"
                :style="{ zoom }"
            >
                <OrganigramaPersonaNodo
                    v-for="raiz in raices"
                    :key="raiz.clave"
                    :nodo="raiz"
                    :obtener-hijos="obtenerHijos"
                />
            </div>
        </div>
    </div>
</template>
