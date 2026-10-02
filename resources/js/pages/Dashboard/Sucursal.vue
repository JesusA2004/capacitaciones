<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TableroRh from '@/components/Dashboard/TableroRh.vue';
import type { TableroRhDatos } from '@/types';

defineProps<{ tablero: TableroRhDatos }>();

// Sin migas: el título del tablero va hasta arriba (la miga "Inicio"
// solo repetía la pantalla actual).
defineOptions({
    layout: {
        breadcrumbs: [],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="pagina-ancha flex flex-col gap-6 !pt-3 sm:!pt-4">
        <TableroRh :tablero="tablero" />
    </div>
</template>
