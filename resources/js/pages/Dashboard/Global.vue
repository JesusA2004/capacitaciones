<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TableroRh from '@/components/Dashboard/TableroRh.vue';
import { dashboard } from '@/routes';
import type { TableroRhDatos } from '@/types';

defineProps<{ tablero: TableroRhDatos }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="pagina-ancha flex flex-col gap-6">
        <TableroRh :tablero="tablero" />
    </div>
</template>
