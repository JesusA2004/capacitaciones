<script setup lang="ts">
import { Eye } from '@lucide/vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import type { ButtonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/**
 * Botón que dispara una vista previa (contratos, documentos del proceso,
 * finiquito, responsivas…). Mientras se genera muestra spinner, cambia el
 * texto y queda deshabilitado: nunca se espera «en silencio».
 */
withDefaults(
    defineProps<{
        cargando?: boolean;
        deshabilitado?: boolean;
        texto?: string;
        textoCargando?: string;
        icono?: Component;
        variant?: ButtonVariants['variant'];
        size?: ButtonVariants['size'];
        /** Solo ícono (el texto queda como aria-label). */
        soloIcono?: boolean;
    }>(),
    {
        cargando: false,
        deshabilitado: false,
        texto: 'Vista previa',
        textoCargando: 'Generando…',
        icono: () => Eye,
        variant: 'outline',
        size: 'default',
        soloIcono: false,
    },
);

defineEmits<{ click: [evento: MouseEvent] }>();
</script>

<template>
    <Button
        type="button"
        :variant="variant"
        :size="size"
        :disabled="cargando || deshabilitado"
        :aria-busy="cargando"
        :aria-label="soloIcono ? texto : undefined"
        :title="soloIcono ? texto : undefined"
        @click="(e: MouseEvent) => $emit('click', e)"
    >
        <Spinner v-if="cargando" />
        <component :is="icono" v-else />
        <span v-if="!soloIcono">{{ cargando ? textoCargando : texto }}</span>
    </Button>
</template>
