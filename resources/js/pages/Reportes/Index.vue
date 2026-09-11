<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Briefcase,
    CalendarClock,
    ClipboardList,
    Download,
    FileText,
    RefreshCw,
    UserCheck,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import DashboardRhContenido from '@/components/Dashboard/DashboardRhContenido.vue';
import DashboardSection from '@/components/Dashboard/DashboardSection.vue';
import MetricCard from '@/components/Dashboard/MetricCard.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index } from '@/routes/reportes';
import { excel, pdf } from '@/routes/reportes/exportar';
import type { DashboardRhProps, PuntoConteo } from '@/types';

const props = defineProps<{
    metricas: DashboardRhProps;
    otrosModulos: PuntoConteo[];
    puedeExportar: boolean;
    generadoEn: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Reportes', href: index.url() },
        ],
    },
});

/**
 * "Tiempo real" vía polling con recargas parciales de Inertia (sin tocar la
 * infraestructura de Reverb, que hoy solo se usa para notificaciones por
 * usuario): cada INTERVALO_MS se vuelve a pedir exactamente lo que ya está
 * en pantalla. Se pausa cuando la pestaña no está visible para no gastar
 * peticiones de fondo, y se retoma al volver a ella.
 */
const INTERVALO_MS = 45_000;

const actualizando = ref(false);
const ultimaActualizacion = ref(new Date(props.generadoEn));
let temporizador: ReturnType<typeof setInterval> | undefined;

function actualizar() {
    if (actualizando.value) {
        return;
    }

    actualizando.value = true;
    router.reload({
        only: ['metricas', 'otrosModulos', 'generadoEn'],
        onSuccess: () => {
            ultimaActualizacion.value = new Date();
        },
        onFinish: () => {
            actualizando.value = false;
        },
    });
}

function detenerPolling() {
    if (temporizador !== undefined) {
        clearInterval(temporizador);
        temporizador = undefined;
    }
}

function iniciarPolling() {
    detenerPolling();
    temporizador = setInterval(() => {
        if (document.visibilityState === 'visible') {
            actualizar();
        }
    }, INTERVALO_MS);
}

function alCambiarVisibilidad() {
    if (document.visibilityState === 'visible') {
        actualizar();
        iniciarPolling();
    } else {
        detenerPolling();
    }
}

onMounted(() => {
    iniciarPolling();
    document.addEventListener('visibilitychange', alCambiarVisibilidad);
});

onUnmounted(() => {
    detenerPolling();
    document.removeEventListener('visibilitychange', alCambiarVisibilidad);
});

const horaFormateada = computed(() =>
    ultimaActualizacion.value.toLocaleTimeString('es-MX', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }),
);

const iconoModulo: Record<string, typeof Briefcase> = {
    'Vacantes abiertas': Briefcase,
    'Candidatos viables': UserCheck,
    'Vacaciones solicitadas': CalendarClock,
    'Solicitudes pendientes': ClipboardList,
};
</script>

<template>
    <Head title="Reportes" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Reportes"
                description="Panorama de toda la organización, actualizado solo — sin recargar la página."
            />

            <div class="flex flex-wrap items-center gap-2">
                <div
                    class="flex items-center gap-1.5 rounded-full border border-border/60 bg-card px-3 py-1.5 text-xs text-muted-foreground"
                >
                    <span class="relative flex size-2">
                        <span
                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[var(--brand-primary)] opacity-75"
                        />
                        <span
                            class="relative inline-flex size-2 rounded-full bg-[var(--brand-primary)]"
                        />
                    </span>
                    Actualizado {{ horaFormateada }}
                </div>

                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="actualizando"
                    @click="actualizar"
                >
                    <RefreshCw
                        class="size-4"
                        :class="actualizando && 'animate-spin'"
                    />
                    Actualizar ahora
                </Button>

                <template v-if="puedeExportar">
                    <Button as-child variant="outline" size="sm">
                        <a :href="excel.url()">
                            <Download class="size-4" />
                            Excel
                        </a>
                    </Button>
                    <Button as-child variant="outline" size="sm">
                        <a :href="pdf.url()">
                            <FileText class="size-4" />
                            PDF
                        </a>
                    </Button>
                </template>
            </div>
        </div>

        <DashboardSection
            titulo="Reclutamiento y solicitudes"
            descripcion="Otros módulos activos, mismo alcance organizacional que el resto del portal."
            :columnas="4"
        >
            <MetricCard
                v-for="item in otrosModulos"
                :key="item.etiqueta"
                :titulo="item.etiqueta"
                :valor="item.valor"
                :icono="iconoModulo[item.etiqueta]"
            />
        </DashboardSection>

        <DashboardRhContenido
            :cards="metricas.cards"
            :graficas="metricas.graficas"
            :proximos-aniversarios="metricas.proximosAniversarios"
            :documentos-pendientes-revision="metricas.documentosPendientesRevision"
            :alertas="metricas.alertas"
        />
    </div>
</template>
