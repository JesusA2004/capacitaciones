<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BookOpen, CheckCircle2, Star } from '@lucide/vue';
import { computed } from 'vue';
import DashboardChartCard from '@/components/Dashboard/DashboardChartCard.vue';
import DashboardSection from '@/components/Dashboard/DashboardSection.vue';
import KpiCard from '@/components/Dashboard/KpiCard.vue';
import MetricCard from '@/components/Dashboard/MetricCard.vue';
import ProximasSesionesCard from '@/components/Dashboard/ProximasSesionesCard.vue';
import Heading from '@/components/Heading.vue';
import { asistenciaADonut, parADonut } from '@/lib/graficas';
import { dashboard } from '@/routes';
import { index as indexMiCapacitacion } from '@/routes/mi-capacitacion';
import type { GraficasColaborador, SesionProximaItem } from '@/types';

const props = defineProps<{
    cursosEnProgreso: number;
    cursosCompletados: number;
    proximasSesiones: SesionProximaItem[];
    graficas: GraficasColaborador;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});

const cursosDonut = computed(() => [
    {
        clave: 'completados',
        etiqueta: 'Completados',
        valor: props.graficas.cursosPorEstado.completados,
    },
    {
        clave: 'en_progreso',
        etiqueta: 'En progreso',
        valor: props.graficas.cursosPorEstado.en_progreso,
    },
    {
        clave: 'pendientes',
        etiqueta: 'Pendientes',
        valor: props.graficas.cursosPorEstado.pendientes,
    },
]);

const asistenciaDonut = computed(() =>
    asistenciaADonut(props.graficas.asistenciaSesiones),
);

const videosDonut = computed(() =>
    parADonut(
        props.graficas.videosCompletados.completados,
        props.graficas.videosCompletados.total -
            props.graficas.videosCompletados.completados,
        'Vistos',
        'Pendientes',
    ),
);
</script>

<template>
    <Head title="Inicio" />

    <div class="flex flex-col gap-8 p-4">
        <Heading
            title="Inicio"
            description="Tu resumen de capacitación: qué tienes pendiente y qué ya completaste."
        />

        <DashboardSection titulo="Tu avance" :columnas="3">
            <KpiCard
                titulo="Cursos en progreso"
                :valor="cursosEnProgreso"
                :icono="BookOpen"
                tono="info"
                :href="indexMiCapacitacion.url()"
            />
            <KpiCard
                titulo="Cursos completados"
                :valor="cursosCompletados"
                :icono="CheckCircle2"
                tono="success"
            />
            <MetricCard
                titulo="Calificación promedio"
                :valor="graficas.calificacionPromedio"
                subvalor="de 100"
                :icono="Star"
            />
        </DashboardSection>

        <DashboardSection
            titulo="Tu actividad"
            descripcion="Cómo va tu capacitación en los cursos asignados."
            :columnas="3"
        >
            <DashboardChartCard
                title="Tus cursos"
                type="donut"
                :data="cursosDonut"
                label-key="etiqueta"
                value-key="valor"
                :height="180"
                empty-title="Todavía no tienes cursos"
                empty-description="Cuando te asignen un curso aparecerá aquí."
            />
            <DashboardChartCard
                title="Videos vistos"
                type="donut"
                :data="videosDonut"
                label-key="etiqueta"
                value-key="valor"
                :height="180"
                empty-title="Sin videos todavía"
                empty-description="Los videos de tus lecciones aparecerán aquí conforme los veas."
            />
            <DashboardChartCard
                title="Tu asistencia"
                description="Sesiones en vivo, últimos 30 días"
                type="donut"
                :data="asistenciaDonut"
                label-key="etiqueta"
                value-key="valor"
                :height="180"
                empty-title="Sin sesiones recientes"
                empty-description="Tu asistencia a sesiones en vivo aparecerá aquí después de tu próxima sesión."
            />
        </DashboardSection>

        <ProximasSesionesCard :sesiones="proximasSesiones" />
    </div>
</template>
