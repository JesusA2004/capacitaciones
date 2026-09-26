<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, PenLine, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import CelebracionesPanel from '@/components/Celebraciones/CelebracionesPanel.vue';
import type { NavegacionCelebraciones } from '@/components/Celebraciones/CelebracionesPanel.vue';
import { dashboard } from '@/routes';
import { felicitacion, index } from '@/routes/rh/cumpleanos';
import { index as configuracionIndex } from '@/routes/rh/cumpleanos/configuracion';
import type { EventoCelebracion, FiltrosCelebracion, OpcionCelebracion } from '@/types';

/**
 * Cumpleaños (RH). Toda la pantalla vive en CelebracionesPanel, compartida
 * con Aniversarios; aquí solo van los datos y lo exclusivo de cumpleaños:
 * aviso de colaboradores sin fecha de nacimiento y el acceso a cambiar la
 * frase de la tarjeta (Felicitacion.vue).
 */
const props = defineProps<{
    fechaHoy: string;
    mes: number;
    anio: number;
    filtros: Partial<Record<'sucursal_id' | 'departamento_id' | 'colaborador_id' | 'estatus' | 'busqueda', string>>;
    hoy: EventoCelebracion[];
    delMes: EventoCelebracion[];
    proximos: EventoCelebracion[];
    rango: { desde: string; hasta: string };
    sinFechaNacimiento: { id: number; nombre: string; sucursal: string | null }[];
    opciones: {
        sucursales: OpcionCelebracion[];
        departamentos: OpcionCelebracion[];
        colaboradores: OpcionCelebracion[];
    };
    config: { enabled: boolean };
    permisos: {
        calendario: boolean;
        descargarImagen: boolean;
        configurar: boolean;
        enviar: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Celebraciones', href: index.url() },
            { title: 'Cumpleaños', href: '' },
        ],
    },
});

const ESTATUS = [
    { valor: 'activo', etiqueta: 'Activo' },
    { valor: 'en_incorporacion', etiqueta: 'En incorporación' },
    { valor: 'inactivo', etiqueta: 'Inactivo' },
    { valor: 'suspendido', etiqueta: 'Suspendido' },
];

const filtros = computed<FiltrosCelebracion>(() => ({
    busqueda: props.filtros.busqueda ?? '',
    sucursal_id: props.filtros.sucursal_id ? String(props.filtros.sucursal_id) : '',
    departamento_id: props.filtros.departamento_id ? String(props.filtros.departamento_id) : '',
    colaborador_id: props.filtros.colaborador_id ? String(props.filtros.colaborador_id) : '',
    estatus: props.filtros.estatus ?? '',
    empresa_id: '',
}));

function navegar({ anio, mes, filtros: f, rango }: NavegacionCelebraciones) {
    router.get(
        index.url(),
        {
            mes,
            anio,
            rango_desde: rango.desde,
            rango_hasta: rango.hasta,
            busqueda: f.busqueda || undefined,
            sucursal_id: f.sucursal_id || undefined,
            departamento_id: f.departamento_id || undefined,
            colaborador_id: f.colaborador_id || undefined,
            estatus: f.estatus || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head title="Cumpleaños" />

    <CelebracionesPanel
        tipo="cumpleanos"
        :fecha-hoy="fechaHoy"
        :anio="anio"
        :mes="mes"
        :hoy="hoy"
        :del-mes="delMes"
        :proximos="proximos"
        :rango="rango"
        :filtros="filtros"
        :catalogos="{ ...opciones, estatus: ESTATUS }"
        :permisos="{ gestionar: true, descargar: permisos.descargarImagen, enviar: permisos.enviar, calendario: permisos.calendario }"
        :configuracion-url="permisos.configurar ? configuracionIndex.url() : null"
        @navegar="navegar"
    >
        <template #avisos>
            <p v-if="!config.enabled" class="rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                Las felicitaciones automáticas están apagadas (<code>CUMPLEANOS_ENABLED=false</code>): nadie recibirá su tarjeta sin que RH la envíe.
            </p>

            <details v-if="sinFechaNacimiento.length > 0" data-tour="cumpleanos-sin-fecha" class="group rounded-lg bg-[var(--warning)]/10 px-3 py-2 text-sm">
                <summary class="flex cursor-pointer list-none items-center gap-2 font-medium text-[var(--warning)]">
                    <TriangleAlert class="size-4 shrink-0" />
                    <span class="min-w-0 flex-1">
                        {{ sinFechaNacimiento.length }} {{ sinFechaNacimiento.length === 1 ? 'colaborador activo no tiene' : 'colaboradores activos no tienen' }}
                        fecha de nacimiento y no aparecen en cumpleaños
                    </span>
                    <ChevronDown class="size-4 shrink-0 transition-transform group-open:rotate-180" />
                </summary>
                <p class="mt-2 text-muted-foreground">Complétala en su expediente:</p>
                <ul class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                    <li v-for="colaborador in sinFechaNacimiento" :key="colaborador.id">
                        {{ colaborador.nombre }}<span v-if="colaborador.sucursal" class="text-muted-foreground"> · {{ colaborador.sucursal }}</span>
                    </li>
                </ul>
            </details>
        </template>

        <template #dialogo="{ evento }">
            <Link :href="felicitacion.url(evento.colaborador_id)" class="inline-flex items-center gap-1.5 text-sm text-primary hover:underline">
                <PenLine class="size-4" />
                Elegir otra frase para esta tarjeta
            </Link>
        </template>
    </CelebracionesPanel>
</template>
