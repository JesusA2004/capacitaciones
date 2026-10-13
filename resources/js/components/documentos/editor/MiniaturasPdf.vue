<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import type { PaginaPdf } from '@/composables/usePdfDocumento';
import { cn } from '@/lib/utils';
import type { CampoEditor } from '@/types';

/**
 * Columna de páginas del editor (miniaturas del ORIGINAL) con cuántos
 * espacios tiene cada una y si alguno está pendiente.
 */
const props = defineProps<{
    paginas: PaginaPdf[];
    actual: number;
    campos: CampoEditor[];
    dibujar: (numero: number, lienzo: HTMLCanvasElement, pxPorMm: number) => Promise<void>;
}>();

const emit = defineEmits<{ elegir: [numero: number] }>();

const lienzos = ref<Record<number, HTMLCanvasElement | null>>({});

watch(
    () => props.paginas.length,
    async () => {
        await nextTick();

        for (const p of props.paginas) {
            const lienzo = lienzos.value[p.numero];

            if (lienzo) {
                await props.dibujar(p.numero, lienzo, 110 / p.anchoMm);
            }
        }
    },
    { immediate: true },
);

function pendientes(numero: number): number {
    return props.campos.filter((c) => c.page === numero && c.zona === 'dato' && c.estado !== 'confirmado').length;
}
</script>

<template>
    <ol class="flex flex-col gap-3">
        <li v-for="p in paginas" :key="p.numero">
            <button
                type="button"
                :class="cn('flex w-full flex-col items-center gap-1 rounded-lg p-1.5', actual === p.numero ? 'bg-primary/10 ring-2 ring-primary' : 'hover:bg-muted')"
                :aria-label="`Página ${p.numero}`"
                @click="emit('elegir', p.numero)"
            >
                <canvas :ref="(el) => (lienzos[p.numero] = el as HTMLCanvasElement | null)" class="bg-white shadow-sm ring-1 ring-black/10" />
                <span class="flex items-center gap-1 text-xs">
                    {{ p.numero }}
                    <span v-if="pendientes(p.numero)" class="rounded-full bg-warning-soft px-1.5 text-[10px] text-warning-foreground">{{ pendientes(p.numero) }}</span>
                </span>
            </button>
        </li>
    </ol>
</template>
