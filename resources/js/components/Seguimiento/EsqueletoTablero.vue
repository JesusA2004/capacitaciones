<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

/** Skeleton del tablero de Seguimiento mientras Reportes contesta. */
withDefaults(defineProps<{ conSelector?: boolean }>(), { conSelector: true });
</script>

<template>
    <div class="flex flex-col gap-5" aria-busy="true" aria-label="Cargando datos de Reportes">
        <Skeleton v-if="conSelector" class="h-36 rounded-2xl" />
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Skeleton v-for="i in 5" :key="i" class="h-28 rounded-2xl" />
        </div>
        <div class="grid gap-3 lg:grid-cols-3">
            <Skeleton class="h-80 rounded-2xl lg:col-span-2" />
            <Skeleton class="h-80 rounded-2xl" />
        </div>
        <Skeleton class="h-72 rounded-2xl" />
    </div>
</template>
