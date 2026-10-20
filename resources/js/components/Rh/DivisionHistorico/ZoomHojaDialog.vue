<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Loader2,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { useElementSize } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PaginaPdf } from '@/composables/usePdfDocumento';

/**
 * Una hoja del PDF histórico en grande, para decidir a qué documento
 * pertenece. Flechas ← → (o los botones) recorren las hojas; Espacio la
 * selecciona/deselecciona en el editor.
 */
const props = defineProps<{
    numero: number | null;
    total: number;
    hojas: PaginaPdf[];
    dibujar: (
        numero: number,
        lienzo: HTMLCanvasElement,
        pxPorMm: number,
    ) => Promise<void>;
    clasificacion: string;
    detalle: string | null;
    seleccionada: boolean;
}>();

const emit = defineEmits<{
    cerrar: [];
    ir: [numero: number];
    alternar: [numero: number];
}>();

const caja = ref<HTMLElement | null>(null);
const lienzo = ref<HTMLCanvasElement | null>(null);
const { width: anchoCaja } = useElementSize(caja);
const zoom = ref(1);
const dibujando = ref(false);
const abierto = computed(() => props.numero !== null);

async function pintar(): Promise<void> {
    await nextTick();
    const hoja = props.hojas.find((h) => h.numero === props.numero);

    if (!hoja || !lienzo.value || anchoCaja.value <= 0) {
        return;
    }

    dibujando.value = true;

    try {
        const ancho = Math.min(anchoCaja.value, 1000) * zoom.value;
        await props.dibujar(hoja.numero, lienzo.value, ancho / hoja.anchoMm);
    } finally {
        dibujando.value = false;
    }
}

watch(
    () => [props.numero, zoom.value, Math.round(anchoCaja.value)] as const,
    () => void pintar(),
);

function tecla(e: KeyboardEvent): void {
    if (props.numero === null) {
        return;
    }

    if (e.key === 'ArrowRight' && props.numero < props.total) {
        e.preventDefault();
        emit('ir', props.numero + 1);
    } else if (e.key === 'ArrowLeft' && props.numero > 1) {
        e.preventDefault();
        emit('ir', props.numero - 1);
    } else if (e.key === ' ') {
        e.preventDefault();
        emit('alternar', props.numero);
    }
}
</script>

<template>
    <Dialog :open="abierto" @update:open="(v: boolean) => !v && emit('cerrar')">
        <DialogContent
            class="flex h-[calc(100svh-2rem)] max-w-[calc(100%-1rem)] flex-col gap-3 p-4 sm:max-w-5xl"
            @keydown="tecla"
        >
            <DialogHeader>
                <DialogTitle>Hoja {{ numero }} de {{ total }}</DialogTitle>
                <DialogDescription class="flex flex-wrap items-center gap-2">
                    <Badge variant="outline">{{ clasificacion }}</Badge>
                    <span v-if="detalle">{{ detalle }}</span>
                </DialogDescription>
            </DialogHeader>

            <div
                ref="caja"
                class="relative min-h-0 flex-1 overflow-auto rounded-xl bg-crema/60 p-2"
            >
                <Loader2
                    v-if="dibujando"
                    class="absolute top-3 right-3 size-4 animate-spin text-muted-foreground"
                />
                <canvas
                    ref="lienzo"
                    class="mx-auto block rounded-sm bg-white shadow-sm"
                    role="img"
                    :aria-label="`Hoja ${numero} del PDF histórico`"
                />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    variant="outline"
                    :disabled="!numero || numero <= 1"
                    @click="numero && emit('ir', numero - 1)"
                >
                    <ChevronLeft class="size-4" /> Anterior
                </Button>
                <Button
                    variant="outline"
                    :disabled="!numero || numero >= total"
                    @click="numero && emit('ir', numero + 1)"
                >
                    Siguiente <ChevronRight class="size-4" />
                </Button>
                <div class="flex items-center gap-1">
                    <Button
                        size="icon"
                        variant="ghost"
                        aria-label="Alejar"
                        :disabled="zoom <= 0.5"
                        @click="zoom = Math.max(0.5, zoom - 0.25)"
                    >
                        <ZoomOut class="size-4" />
                    </Button>
                    <span class="w-11 text-center text-xs tabular-nums"
                        >{{ Math.round(zoom * 100) }}%</span
                    >
                    <Button
                        size="icon"
                        variant="ghost"
                        aria-label="Acercar"
                        :disabled="zoom >= 2.5"
                        @click="zoom = Math.min(2.5, zoom + 0.25)"
                    >
                        <ZoomIn class="size-4" />
                    </Button>
                </div>
                <Button
                    class="ml-auto"
                    :variant="seleccionada ? 'secondary' : 'default'"
                    @click="numero && emit('alternar', numero)"
                >
                    {{
                        seleccionada
                            ? 'Quitar de la selección'
                            : 'Seleccionar esta hoja'
                    }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
