<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarClock,
    ClipboardCheck,
    GraduationCap,
    LineChart,
    Video,
} from '@lucide/vue';
import { dashboard } from '@/routes';
import { proximamente } from '@/routes/capacitacion';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: proximamente() },
        ],
    },
});

const modulosConservados = [
    { icono: BookOpen, titulo: 'Cursos y lecciones' },
    { icono: Video, titulo: 'Biblioteca multimedia' },
    { icono: ClipboardCheck, titulo: 'Cuestionarios y actividades' },
    { icono: CalendarClock, titulo: 'Sesiones en vivo y asistencias' },
    { icono: LineChart, titulo: 'Reportes de desempeño' },
];
</script>

<template>
    <Head title="Capacitación" />

    <div
        class="flex flex-1 flex-col items-center justify-center gap-8 p-6 text-center"
    >
        <div class="flex flex-col items-center gap-4">
            <span
                class="flex size-16 items-center justify-center rounded-3xl bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]"
            >
                <GraduationCap class="size-8" />
            </span>

            <div class="space-y-2">
                <span
                    class="inline-flex items-center rounded-full bg-warning/10 px-3 py-1 text-xs font-medium text-warning"
                >
                    Próximamente
                </span>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Capacitación y Desempeño
                </h1>
                <p class="max-w-md text-sm text-muted-foreground">
                    Disponible en Fase 2. Cursos, videos, evaluaciones,
                    seguimiento e indicadores de desempeño.
                </p>
            </div>
        </div>

        <div class="grid w-full max-w-2xl grid-cols-1 gap-3 sm:grid-cols-2">
            <div
                v-for="modulo in modulosConservados"
                :key="modulo.titulo"
                class="flex items-center gap-3 rounded-2xl border border-border/60 bg-card p-4 text-left shadow-sm"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <component :is="modulo.icono" class="size-4" />
                </span>
                <span class="text-sm font-medium">{{ modulo.titulo }}</span>
            </div>
        </div>

        <p class="max-w-md text-xs text-muted-foreground">
            Nada de lo construido se eliminó: cursos, videos, cuestionarios,
            actividades, sesiones y asistencias siguen guardados y volverán a
            estar disponibles cuando esta fase se active.
        </p>

        <Link
            :href="dashboard()"
            class="text-sm font-medium text-[var(--brand-primary)] underline-offset-4 hover:underline"
        >
            Volver al inicio
        </Link>
    </div>
</template>
