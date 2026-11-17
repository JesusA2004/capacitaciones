<script setup lang="ts">
import {
    BookOpen,
    Compass,
    GraduationCap,
    Lightbulb,
    Presentation,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';

/**
 * Portada decorativa de un curso/aula (los cursos aún no suben imagen de
 * portada): degradado pastel + icono, elegido de forma estable por el id del
 * curso para que cada aula conserve su «color». Solo tokens de la paleta
 * (app.css), nunca hex sueltos.
 */
const props = withDefaults(
    defineProps<{
        cursoId: number;
        titulo?: string;
        alto?: string;
    }>(),
    { titulo: '', alto: 'h-28' },
);

const TEMAS = [
    { fondo: 'from-salvia via-menta to-verde-suave', icono: 'text-primary' },
    { fondo: 'from-crema via-crema-2 to-olivo', icono: 'text-bronce' },
    { fondo: 'from-menta via-salvia to-info-soft', icono: 'text-esmeralda' },
    { fondo: 'from-olivo via-crema to-warning-soft', icono: 'text-bronce' },
    {
        fondo: 'from-verde-suave via-success-soft to-menta',
        icono: 'text-primary',
    },
    { fondo: 'from-info-soft via-menta to-crema', icono: 'text-esmeralda' },
] as const;

const ICONOS = [
    GraduationCap,
    BookOpen,
    Lightbulb,
    Compass,
    Presentation,
    Sparkles,
];

const tema = computed(() => TEMAS[props.cursoId % TEMAS.length]);
const icono = computed(() => ICONOS[props.cursoId % ICONOS.length]);
const inicial = computed(() => props.titulo.trim().charAt(0).toUpperCase());
</script>

<template>
    <div
        class="relative overflow-hidden bg-linear-to-br"
        :class="[tema.fondo, alto]"
        aria-hidden="true"
    >
        <span
            class="absolute -top-6 -right-6 size-24 rounded-full bg-card/30"
        />
        <span
            class="absolute -bottom-10 left-6 size-28 rounded-full bg-card/20"
        />
        <component
            :is="icono"
            class="absolute right-4 bottom-3 size-12 opacity-70"
            :class="tema.icono"
        />
        <span
            v-if="inicial"
            class="absolute top-3 left-4 font-serif text-4xl font-bold opacity-25"
            :class="tema.icono"
            >{{ inicial }}</span
        >
        <slot />
    </div>
</template>
