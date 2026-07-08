<script setup lang="ts">
import type { Component } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';

/**
 * Envoltorio delgado sobre Common/EmptyState.vue para los CRUDs: mismo
 * componente, nombre que deja claro su contexto (siempre va acompañado de
 * un botón "Crear el primero..." en el slot por defecto).
 */
withDefaults(
    defineProps<{
        icono?: Component;
        titulo?: string;
        descripcion?: string;
    }>(),
    {},
);
</script>

<template>
    <EmptyState :icono="icono" :titulo="titulo" :descripcion="descripcion">
        <slot />
    </EmptyState>
</template>
