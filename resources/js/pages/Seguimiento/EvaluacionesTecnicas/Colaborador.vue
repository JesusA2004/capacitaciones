<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Award, CalendarDays, CheckCircle2, Gauge, Route } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import AnilloCalificacion from '@/components/evaluaciones-tecnicas/AnilloCalificacion.vue';
import AvatarPersona from '@/components/evaluaciones-tecnicas/AvatarPersona.vue';
import LineaTiempoTecnica from '@/components/evaluaciones-tecnicas/LineaTiempoTecnica.vue';
import TarjetaKpi from '@/components/Seguimiento/TarjetaKpi.vue';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/evaluaciones-tecnicas';
import type { FilaEvaluacionTecnica, LineaTiempoTecnica as Linea } from '@/types';

const props = defineProps<{ linea: Linea }>();

defineOptions({
    layout: (p: { linea?: Linea }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Evaluaciones técnicas', href: index() },
            { title: p.linea?.colaborador.nombre ?? 'Colaborador', href: '' },
        ],
    }),
});

function hrefDe(h: FilaEvaluacionTecnica): string | null {
    return h.id !== null ? show.url(h.id) : null;
}

const persona = props.linea.colaborador;
</script>

<template>
    <Head :title="`Evaluaciones técnicas · ${persona.nombre}`" />

    <div class="pagina-ancha flex flex-col gap-5 pb-10">
        <CrudPageHeader :titulo="persona.nombre" descripcion="Línea de tiempo de evaluaciones técnicas" detalle />

        <!-- Ficha -->
        <section
            class="flex entrada-suave flex-col gap-5 overflow-hidden rounded-3xl border border-border bg-gradient-to-br from-crema/70 via-card to-salvia/40 p-6 shadow-xs md:flex-row md:items-center"
        >
            <div class="flex min-w-0 flex-1 items-center gap-4">
                <AvatarPersona :nombre="persona.nombre" :foto-url="persona.foto_url" tamano="lg" />
                <div class="min-w-0">
                    <h1 class="font-display text-2xl font-semibold tracking-tight text-balance">
                        {{ persona.nombre }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ persona.puesto ?? 'Sin puesto' }}
                        <template v-if="persona.sucursal"> · {{ persona.sucursal }}</template>
                    </p>
                    <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span class="inline-flex items-center gap-1">
                            <CalendarDays class="size-3.5" />
                            Ingreso {{ formatearFecha(persona.fecha_ingreso) }}
                            <template v-if="persona.dias_desde_ingreso !== null">
                                · día {{ persona.dias_desde_ingreso }}
                            </template>
                        </span>
                        <span v-if="persona.jefe">Jefe directo: {{ persona.jefe }}</span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <AnilloCalificacion :calificacion="linea.resumen.promedio" :tamano="104" />
                <div class="grid grid-cols-2 gap-2 md:w-64">
                    <TarjetaKpi etiqueta="Contestadas" :valor="linea.resumen.contestadas" :icono="CheckCircle2" />
                    <TarjetaKpi etiqueta="Aprobadas" :valor="linea.resumen.aprobadas" :icono="Award" tono="menta" />
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-border bg-card p-5 shadow-xs sm:p-6">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold">
                <Route class="size-4 text-bronce" /> Su calendario
            </h2>
            <LineaTiempoTecnica
                v-if="linea.hitos.length"
                :hitos="linea.hitos"
                :fecha-ingreso="persona.fecha_ingreso"
                :dias-desde-ingreso="persona.dias_desde_ingreso"
                :href-de="hrefDe"
            />
            <EmptyState
                v-else
                :icono="Gauge"
                titulo="Su puesto aún no tiene calendario de evaluaciones técnicas"
                descripcion="Recursos Humanos lo configura en «Calendario por puesto» (p. ej. Seguimiento 15, 30 y 60)."
            />
        </section>
    </div>
</template>
