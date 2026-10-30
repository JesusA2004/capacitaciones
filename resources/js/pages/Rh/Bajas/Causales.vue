<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PestanasBajas from '@/components/bajas/PestanasBajas.vue';
import CausalesRotacionPanel from '@/components/Rh/CausalesRotacionPanel.vue';
import { dashboard } from '@/routes';
import rutasBajas from '@/routes/rh/bajas';
import type {
    CausalGrupoAdmin,
    CausalRotacionAdmin,
    HistorialCatalogoBajas,
    PestanasBajas as Pestanas,
} from '@/types';

/**
 * Bajas → «Catálogo de causales» (solo RH). La autorización real vive en
 * CausalRotacionPolicy.
 */
defineProps<{
    pestanas: Pestanas;
    causales: CausalRotacionAdmin[];
    grupos: CausalGrupoAdmin[];
    documentos_por_forma: Record<string, { value: string; label: string }[]>;
    historial: HistorialCatalogoBajas[];
    tipos_baja: {
        value: string;
        label: string;
        forma?: string;
        token?: string;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas', href: rutasBajas.index() },
            { title: 'Catálogo de causales', href: '' },
        ],
    },
});
</script>

<template>
    <Head title="Catálogo de causales" />

    <div class="pagina-ancha flex flex-col gap-5">
        <PestanasBajas :pestanas="pestanas" actual="causales" />
        <CausalesRotacionPanel
            :causales="causales"
            :tipos="tipos_baja"
            :grupos="grupos"
            :documentos-por-forma="documentos_por_forma"
            :historial="historial"
        />
    </div>
</template>
