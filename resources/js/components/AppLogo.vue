<script setup lang="ts">
/**
 * Logo oficial MR. LANA PEOPLE en el sidebar (archivos originales de
 * docs/formatosRH/svg, sin recolorear ni deformar): horizontal a color con
 * el menú abierto y solo el símbolo con el menú colapsado (zona cuadrada).
 * El sistema es solo claro: el sidebar marfil siempre lleva el logo a color.
 */
import horizontalColor from '@/assets/brand/people/MrLanaPeople_horizontal_color.svg';
import simboloColor from '@/assets/brand/people/MrLanaPeople_simbolo_color.svg';
</script>

<template>
    <span
        class="flex w-full items-center justify-center px-1 group-data-[collapsible=icon]:hidden"
    >
        <img
            :src="horizontalColor"
            alt="MR. LANA PEOPLE"
            class="h-[4.5rem] w-full max-w-[13.5rem] object-contain drop-shadow-[0_1px_1px_rgb(49_91_89/0.08)]"
        />
    </span>
    <span
        class="hidden size-8 shrink-0 items-center justify-center group-data-[collapsible=icon]:flex"
    >
        <img
            :src="simboloColor"
            alt="MR. LANA PEOPLE"
            class="size-8 object-contain"
        />
    </span>
</template>
