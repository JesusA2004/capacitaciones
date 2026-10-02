<script setup lang="ts">
/**
 * Logo del sidebar: el favicon real (public/favicon.svg) tal cual, sin
 * recrearlo dentro de otro cuadro de color. Con el sidebar colapsado solo
 * queda visible el favicon (el texto se oculta con el ancho del grupo).
 */
</script>

<template>
    <img
        src="/favicon.svg"
        alt=""
        aria-hidden="true"
        width="36"
        height="36"
        class="size-9 shrink-0 object-contain group-data-[collapsible=icon]:size-8"
    />
    <div
        class="ml-1 grid flex-1 text-left text-sm group-data-[collapsible=icon]:hidden"
    >
        <span
            class="mb-0.5 truncate leading-tight font-semibold text-foreground"
        >
            MR. LANA <span class="text-[var(--brand-primary)]">PEOPLE</span>
        </span>
        <span class="truncate text-[11px] leading-tight text-muted-foreground"
            >Reclutamiento, Personal y RH</span
        >
    </div>
</template>
