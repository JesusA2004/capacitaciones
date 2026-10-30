<script setup lang="ts">
import TableroRh from '@/components/Dashboard/TableroRh.vue';
import type { InicioOperativoDatos, TableroRhDatos } from '@/types';

/**
 * Inicio operativo: el Tablero de RH completo, sin una segunda capa de cifras
 * repetidas (cada dato aparece una sola vez; pág. 3 de la propuesta).
 */
defineProps<{
    inicio: InicioOperativoDatos;
    tablero: TableroRhDatos;
}>();
</script>

<template>
    <TableroRh :inicio="inicio" :tablero="tablero" />
</template>
