<script setup lang="ts">
/**
 * Logo oficial MR. LANA PEOPLE en el sidebar (archivos originales de
 * docs/formatosRH/svg, sin recolorear ni deformar): horizontal a color con
 * el menú abierto, negativo en modo oscuro y solo el símbolo con el menú
 * colapsado (zona cuadrada).
 */
import horizontalColor from '@/assets/brand/people/MrLanaPeople_horizontal_color.svg';
import horizontalNegativo from '@/assets/brand/people/MrLanaPeople_horizontal_negativo.svg';
import simboloColor from '@/assets/brand/people/MrLanaPeople_simbolo_color.svg';
import simboloNegativo from '@/assets/brand/people/MrLanaPeople_simbolo_negativo.svg';
</script>

<template>
    <span class="flex w-full items-center group-data-[collapsible=icon]:hidden">
        <img
            :src="horizontalColor"
            alt="MR. LANA PEOPLE"
            class="h-11 w-auto max-w-full object-contain dark:hidden"
        />
        <img
            :src="horizontalNegativo"
            alt="MR. LANA PEOPLE"
            class="hidden h-11 w-auto max-w-full object-contain dark:block"
        />
    </span>
    <span
        class="hidden size-8 shrink-0 items-center justify-center group-data-[collapsible=icon]:flex"
    >
        <img
            :src="simboloColor"
            alt="MR. LANA PEOPLE"
            class="size-7 object-contain dark:hidden"
        />
        <img
            :src="simboloNegativo"
            alt="MR. LANA PEOPLE"
            class="hidden size-7 object-contain dark:block"
        />
    </span>
</template>
