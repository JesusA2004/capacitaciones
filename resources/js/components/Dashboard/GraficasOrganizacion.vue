<script setup lang="ts">
import { AlertTriangle, ClipboardList, Star, Users, Video } from '@lucide/vue';
import { computed } from 'vue';
import DashboardChartCard from '@/components/Dashboard/DashboardChartCard.vue';
import DashboardSection from '@/components/Dashboard/DashboardSection.vue';
import EmptyDashboardState from '@/components/Dashboard/EmptyDashboardState.vue';
import MetricCard from '@/components/Dashboard/MetricCard.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { asistenciaADonut, parADonut } from '@/lib/graficas';
import type { GraficasOrganizacion } from '@/types';

/**
 * Las 12 gráficas organizacionales (avance general, cumplimiento por
 * sucursal y capacitaciones vencidas ya se muestran arriba, en
 * DashboardHero/BranchComplianceChart, para no duplicar la misma cifra en
 * dos formatos). Compartido por Dashboard/Global.vue y Dashboard/Sucursal.vue,
 * siempre con datos ya acotados por AlcanceOrganizacionalService.
 */
const props = defineProps<{
    graficas: GraficasOrganizacion;
}>();

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

const cuestionariosDonut = computed(() =>
    parADonut(
        props.graficas.cuestionarios.aprobados,
        props.graficas.cuestionarios.reprobados,
        'Aprobados',
        'Reprobados',
    ),
);

const asistenciaDonut = computed(() =>
    asistenciaADonut(props.graficas.asistenciaSesiones),
);

const totalActividadesPendientes = computed(() => {
    const { recientes, atrasadas, criticas } =
        props.graficas.actividadesPendientes;

    return recientes + atrasadas + criticas;
});
</script>

<template>
    <DashboardSection
        titulo="Panorama general"
        descripcion="Cifras clave de toda la organización visible para ti."
        :columnas="4"
    >
        <MetricCard
            titulo="Colaboradores activos"
            :valor="graficas.colaboradoresActivos"
            :icono="Users"
            tono="info"
        />
        <MetricCard
            titulo="Calificación promedio"
            :valor="graficas.calificacionPromedio"
            subvalor="de 100"
            :icono="Star"
        />
        <MetricCard
            titulo="Actividades por calificar"
            :valor="totalActividadesPendientes"
            :subvalor="
                graficas.actividadesPendientes.criticas > 0
                    ? `${graficas.actividadesPendientes.criticas} críticas`
                    : undefined
            "
            :icono="ClipboardList"
            :tono="
                graficas.actividadesPendientes.criticas > 0
                    ? 'danger'
                    : 'default'
            "
        />
        <MetricCard
            titulo="Videos completados"
            :valor="`${graficas.videosCompletados.completados}/${graficas.videosCompletados.total}`"
            :icono="Video"
        />
    </DashboardSection>

    <DashboardSection
        titulo="Cursos"
        descripcion="Estado, avance y abandono de los cursos asignados."
        :columnas="3"
    >
        <DashboardChartCard
            title="Cursos por estado"
            type="donut"
            :data="cursosDonut"
            label-key="etiqueta"
            value-key="valor"
            empty-title="Sin cursos todavía"
        />
        <DashboardChartCard
            title="Top cursos con más avance"
            type="bar"
            :data="graficas.topCursosAvance"
            x-key="curso"
            y-key="porcentaje"
            empty-title="Sin avance registrado"
        />
        <DashboardChartCard
            title="Cursos con mayor abandono"
            description="% de inscripciones vencidas sin completar"
            type="bar"
            :data="graficas.cursosMayorAbandono"
            x-key="curso"
            y-key="porcentaje"
            :colors="['var(--destructive)']"
            empty-title="Sin abandono detectado"
        />
    </DashboardSection>

    <DashboardSection titulo="Evaluación y asistencia" :columnas="3">
        <DashboardChartCard
            title="Cuestionarios"
            description="Aprobados vs. reprobados (último intento)"
            type="donut"
            :data="cuestionariosDonut"
            label-key="etiqueta"
            value-key="valor"
            empty-title="Sin cuestionarios calificados"
        />
        <DashboardChartCard
            title="Asistencia a sesiones en vivo"
            description="Últimos 30 días"
            type="donut"
            :data="asistenciaDonut"
            label-key="etiqueta"
            value-key="valor"
            empty-title="Sin sesiones recientes"
        />
        <DashboardChartCard
            title="Cumplimiento por departamento"
            type="bar"
            :data="graficas.cumplimientoPorDepartamento"
            x-key="departamento"
            y-key="porcentaje"
            empty-title="Sin departamentos con datos"
        />
    </DashboardSection>

    <DashboardSection
        titulo="Evolución mensual"
        descripcion="Cursos completados en los últimos 6 meses."
        :columnas="1"
    >
        <DashboardChartCard
            title="Cursos completados por mes"
            type="area"
            :data="graficas.evolucionMensual"
            x-key="mes"
            y-key="completados"
            :height="200"
            empty-title="Sin completados en el periodo"
        />
    </DashboardSection>

    <DashboardSection
        id="atencion-requerida"
        titulo="Atención requerida"
        descripcion="Colaboradores con más asignaciones vencidas."
        :columnas="1"
    >
        <Card
            class="rounded-2xl border-border/60 shadow-sm transition-all duration-200 hover:border-primary/40 hover:shadow-lg"
        >
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <AlertTriangle class="size-4 text-destructive" />
                    Usuarios con pendientes críticos
                </CardTitle>
                <CardDescription
                    >Colaboradores con más asignaciones vencidas dentro de tu
                    alcance.</CardDescription
                >
            </CardHeader>
            <CardContent>
                <EmptyDashboardState
                    v-if="!graficas.usuariosPendientesCriticos.length"
                    titulo="Nadie tiene pendientes críticos"
                    descripcion="Ningún colaborador visible para ti tiene asignaciones vencidas."
                />
                <ul v-else class="flex flex-col divide-y divide-border/60">
                    <li
                        v-for="usuario in graficas.usuariosPendientesCriticos"
                        :key="usuario.id"
                        class="flex items-center justify-between gap-3 py-2.5 text-sm first:pt-0 last:pb-0"
                    >
                        <span class="truncate">{{ usuario.nombre }}</span>
                        <Badge variant="destructive"
                            >{{ usuario.vencidas }} vencida{{
                                usuario.vencidas === 1 ? '' : 's'
                            }}</Badge
                        >
                    </li>
                </ul>
            </CardContent>
        </Card>
    </DashboardSection>
</template>
