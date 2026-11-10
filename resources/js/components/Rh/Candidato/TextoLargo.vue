<script setup lang="ts">
import { ChevronDown, Quote } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Nota u observación larga en un bloque legible: respeta los saltos de
 * línea, nunca corta palabras fuera de la caja y, si es enorme, muestra el
 * inicio con «Ver más» (nada se pierde: el texto completo está a un clic).
 */
const props = withDefaults(
    defineProps<{
        texto: string;
        etiqueta?: string | null;
        limite?: number;
    }>(),
    { etiqueta: null, limite: 320 },
);

const abierto = ref(false);
const esLargo = computed(() => props.texto.length > props.limite);
const visible = computed(() =>
    esLargo.value && !abierto.value
        ? `${props.texto.slice(0, props.limite).trimEnd()}…`
        : props.texto,
);
</script>

<template>
    <div
        class="relative min-w-0 rounded-xl border-l-4 border-oro/60 bg-crema/40 px-3.5 py-2.5"
    >
        <p
            v-if="etiqueta"
            class="mb-1 flex items-center gap-1.5 text-xs font-medium text-bronce"
        >
            <Quote class="size-3.5" aria-hidden="true" />
            {{ etiqueta }}
        </p>
        <p
            class="text-sm leading-relaxed break-words whitespace-pre-line text-foreground"
        >
            {{ visible }}
        </p>
        <button
            v-if="esLargo"
            type="button"
            class="mt-1 inline-flex items-center gap-1 rounded-md text-xs font-semibold text-[var(--mrl-petroleo)] hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-expanded="abierto"
            @click="abierto = !abierto"
        >
            {{ abierto ? 'Ver menos' : 'Ver más' }}
            <ChevronDown
                class="size-3.5 transicion-ui"
                :class="abierto ? 'rotate-180' : ''"
            />
        </button>
    </div>
</template>
