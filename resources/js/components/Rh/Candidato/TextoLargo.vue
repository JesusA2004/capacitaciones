<script setup lang="ts">
import { ChevronDown, NotebookPen } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Nota u observación que ALGUIEN escribió a mano (no es un dato que calcule
 * el sistema): por eso va como cita, entre comillas, con el ícono de nota y
 * la leyenda «… escritas por {autor}». Respeta saltos de línea, nunca corta
 * palabras fuera de la caja y, si es larga, enseña el inicio con «Ver más»
 * (el texto completo está a un clic).
 */
const props = withDefaults(
    defineProps<{
        texto: string;
        /** Qué es: «Observaciones», «Resumen de resultados»… */
        etiqueta?: string | null;
        /** Quién lo escribió, cuando se sabe. */
        autor?: string | null;
        limite?: number;
    }>(),
    { etiqueta: 'Observaciones', autor: null, limite: 220 },
);

const abierto = ref(false);
const esLargo = computed(() => props.texto.length > props.limite);
const visible = computed(() =>
    esLargo.value && !abierto.value
        ? `${props.texto.slice(0, props.limite).trimEnd()}…`
        : props.texto,
);
const leyenda = computed(() => {
    const etiqueta = props.etiqueta ?? 'Observaciones';

    if (!props.autor) {
        return `${etiqueta} · nota capturada a mano`;
    }

    // «Observaciones escritas por…»; con otro nombre («Resumen de
    // resultados») se evita la concordancia: «· nota escrita por…».
    return etiqueta.startsWith('Observaciones')
        ? `${etiqueta} escritas por ${props.autor}`
        : `${etiqueta} · nota escrita por ${props.autor}`;
});
</script>

<template>
    <figure
        class="relative min-w-0 rounded-xl border-l-4 border-oro/60 bg-crema/40 px-3.5 py-2.5"
        data-nota-capturada
    >
        <figcaption
            class="mb-1 flex items-start gap-1.5 text-xs font-medium break-words text-bronce"
        >
            <NotebookPen class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            {{ leyenda }}
        </figcaption>
        <blockquote
            class="text-sm leading-relaxed break-words whitespace-pre-line text-foreground italic"
        >
            «{{ visible }}»
        </blockquote>
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
    </figure>
</template>
