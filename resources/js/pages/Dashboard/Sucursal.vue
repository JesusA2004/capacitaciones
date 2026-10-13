<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import InicioOperativo from '@/components/Dashboard/InicioOperativo.vue';
import type { InicioOperativoDatos, TableroRhDatos } from '@/types';

defineProps<{ inicio: InicioOperativoDatos; tablero: TableroRhDatos }>();

// Sin migas: el saludo va hasta arriba (la miga "Inicio" solo repetía la
// pantalla actual).
defineOptions({
    layout: {
        breadcrumbs: [],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="pagina-ancha flex flex-col gap-6 !pt-3 sm:!pt-4">
        <InicioOperativo :inicio="inicio" :tablero="tablero" />
    </div>
</template>
