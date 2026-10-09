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
    <span class="flex w-full items-center group-data-[collapsible=icon]:hidden">
        <img
            :src="horizontalColor"
            alt="MR. LANA PEOPLE"
            class="h-11 w-auto max-w-full object-contain"
        />
    </span>
    <span
        class="hidden size-8 shrink-0 items-center justify-center group-data-[collapsible=icon]:flex"
    >
        <img
            :src="simboloColor"
            alt="MR. LANA PEOPLE"
            class="size-7 object-contain"
        />
    </span>
</template>
