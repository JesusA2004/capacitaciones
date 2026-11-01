<script setup lang="ts">
import { computed } from 'vue';

/**
 * Lista cuyas filas entran, salen y se reacomodan con suavidad. Cada hijo
 * directo necesita `:key`. Con más filas que `tope` no anima: en listas largas
 * el movimiento estorba y cuesta rendimiento.
 */
const props = withDefaults(
    defineProps<{
        tag?: string;
        cantidad?: number;
        tope?: number;
    }>(),
    { tag: 'ul', cantidad: 0, tope: 30 },
);

const animar = computed(() => props.cantidad <= props.tope);

/** Saca la fila del flujo al salir, sin que cambie de tamaño ni de lugar. */
function antesDeSalir(el: Element): void {
    if (!(el instanceof HTMLElement)) {
        return;
    }

    el.style.width = `${el.offsetWidth}px`;
    el.style.height = `${el.offsetHeight}px`;
    el.style.left = `${el.offsetLeft}px`;
    el.style.top = `${el.offsetTop}px`;
    el.style.position = 'absolute';
}
</script>

<template>
    <TransitionGroup
        :tag="tag"
        class="relative"
        :name="animar ? 'lista-animada' : 'lista-sin-animar'"
        :css="animar"
        @before-leave="antesDeSalir"
    >
        <slot />
    </TransitionGroup>
</template>
