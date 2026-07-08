<script setup lang="ts">
import { X } from '@lucide/vue';
import { ref } from 'vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import { Button } from '@/components/ui/button';

/**
 * Barra de herramientas de un CRUD: buscador + (si el módulo tiene más
 * filtros) un botón que abre CrudFilterSheet, igual en escritorio y móvil
 * en vez de mantener selects siempre visibles que estorban en pantallas
 * angostas.
 */
withDefaults(
    defineProps<{
        modelValue: string;
        placeholder?: string;
        contadorFiltrosActivos?: number;
        tituloFiltros?: string;
        descripcionFiltros?: string;
    }>(),
    {
        contadorFiltrosActivos: 0,
        tituloFiltros: 'Filtros',
    },
);

const emit = defineEmits<{
    'update:modelValue': [valor: string];
    limpiar: [];
    'aplicar-filtros': [];
}>();

const sheetAbierto = ref(false);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <CrudSearchInput
            :model-value="modelValue"
            :placeholder="placeholder"
            @update:model-value="(valor) => emit('update:modelValue', valor)"
        />

        <CrudFilterSheet
            v-if="$slots.filtros"
            :open="sheetAbierto"
            @update:open="(valor) => (sheetAbierto = valor)"
            :titulo="tituloFiltros"
            :descripcion="descripcionFiltros"
            :contador-activos="contadorFiltrosActivos"
            @aplicar="emit('aplicar-filtros')"
            @limpiar="emit('limpiar')"
        >
            <slot name="filtros" />
        </CrudFilterSheet>

        <Button variant="ghost" size="sm" @click="emit('limpiar')">
            <X class="size-4" />
            Limpiar filtros
        </Button>
    </div>
</template>
