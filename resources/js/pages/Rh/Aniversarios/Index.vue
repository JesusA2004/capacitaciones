<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import CelebracionesPanel from '@/components/Celebraciones/CelebracionesPanel.vue';
import type { NavegacionCelebraciones } from '@/components/Celebraciones/CelebracionesPanel.vue';
import { dashboard } from '@/routes';
import { configuracion, index as indexAniversarios } from '@/routes/rh/aniversarios';
import type { EventoCelebracion, FiltrosCelebracion, OpcionCelebracion } from '@/types';

/**
 * Aniversarios laborales (RH): misma pantalla que Cumpleaños
 * (CelebracionesPanel); solo cambian datos, textos y la tarjeta.
 */
const props = defineProps<{
    fechaHoy: string;
    mes: number;
    anio: number;
    hoy: EventoCelebracion[];
    delMes: EventoCelebracion[];
    proximos: EventoCelebracion[];
    rango: { desde: string; hasta: string };
    filtros: { empresa_id: number | null; sucursal_id: number | null; departamento_id: number | null; busqueda: string | null };
    catalogos: {
        empresas: OpcionCelebracion[];
        sucursales: OpcionCelebracion[];
        departamentos: OpcionCelebracion[];
    };
    permisos: { gestionar: boolean; enviar: boolean; moderar: boolean };
    configuracionActiva: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Celebraciones', href: indexAniversarios.url() },
            { title: 'Aniversarios', href: '' },
        ],
    },
});

const filtros = computed<FiltrosCelebracion>(() => ({
    busqueda: props.filtros.busqueda ?? '',
    empresa_id: props.filtros.empresa_id ? String(props.filtros.empresa_id) : '',
    sucursal_id: props.filtros.sucursal_id ? String(props.filtros.sucursal_id) : '',
    departamento_id: props.filtros.departamento_id ? String(props.filtros.departamento_id) : '',
    colaborador_id: '',
    estatus: '',
}));

function navegar({ anio, mes, filtros: f, rango }: NavegacionCelebraciones) {
    router.get(
        indexAniversarios.url(),
        {
            mes,
            anio,
            desde: rango.desde,
            hasta: rango.hasta,
            busqueda: f.busqueda || undefined,
            empresa_id: f.empresa_id || undefined,
            sucursal_id: f.sucursal_id || undefined,
            departamento_id: f.departamento_id || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head title="Aniversarios" />

    <CelebracionesPanel
        tipo="aniversario_laboral"
        :fecha-hoy="fechaHoy"
        :anio="anio"
        :mes="mes"
        :hoy="hoy"
        :del-mes="delMes"
        :proximos="proximos"
        :rango="rango"
        :filtros="filtros"
        :catalogos="catalogos"
        :permisos="{ gestionar: permisos.gestionar, descargar: permisos.gestionar, enviar: permisos.enviar, calendario: true }"
        :configuracion-url="permisos.gestionar ? configuracion.url() : null"
        @navegar="navegar"
    >
        <template #avisos>
            <p v-if="!configuracionActiva" class="rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                Los aniversarios están desactivados en Configuración: no se preparan tarjetas automáticamente cada día.
            </p>
        </template>
    </CelebracionesPanel>
</template>
