<script setup lang="ts">
/**
 * Cambio entre dos contenidos (pestañas, pasos, vacío ↔ con datos). Primero
 * sale uno y luego entra el otro, en 120 ms cada uno. El hijo necesita `:key`
 * o `v-if` y una sola raíz.
 */
</script>

<template>
    <Transition name="cambio-suave" mode="out-in">
        <slot />
    </Transition>
</template>
