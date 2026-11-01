<script setup lang="ts" generic="T extends string">
/**
 * Control segmentado para separar las partes de una pantalla. Solo cambia qué
 * se ve: cada parte sigue montada y conserva lo capturado.
 */
defineProps<{
    opciones: { valor: T; etiqueta: string; contador?: number }[];
    etiqueta: string;
}>();

const modelo = defineModel<T>({ required: true });
</script>

<template>
    <div
        role="group"
        :aria-label="etiqueta"
        class="scroll-x-limpio flex w-fit max-w-full gap-1 rounded-xl bg-muted/60 p-1"
    >
        <button
            v-for="o in opciones"
            :key="o.valor"
            type="button"
            class="inline-flex min-h-9 shrink-0 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm font-medium transicion-ui"
            :class="
                modelo === o.valor
                    ? 'bg-card text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground'
            "
            :aria-pressed="modelo === o.valor"
            @click="modelo = o.valor"
        >
            {{ o.etiqueta }}
            <span
                v-if="o.contador"
                class="rounded-full bg-warning-soft px-1.5 text-xs text-warning tabular-nums"
                >{{ o.contador }}</span
            >
        </button>
    </div>
</template>
