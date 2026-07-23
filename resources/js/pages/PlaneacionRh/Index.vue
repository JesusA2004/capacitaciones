<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    Briefcase,
    Building,
    Building2,
    CalendarDays,
    ClipboardList,
    FileText,
    FolderTree,
    GraduationCap,
    HardDrive,
    LineChart,
    Rocket,
    ShieldCheck,
    UserPlus,
    Users,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Planeación Portal RH', href: '#' },
        ],
    },
});

const estructura = [
    { icono: Building2, etiqueta: 'Empresa' },
    { icono: Building, etiqueta: 'Sucursal' },
    { icono: Users, etiqueta: 'Colaborador' },
    { icono: FolderTree, etiqueta: 'Expediente' },
];

const pilares = [
    {
        icono: HardDrive,
        titulo: 'Synology NAS',
        descripcion:
            'Almacenamiento central de archivos pesados (PDFs, identificaciones, contratos, fotos). La base de datos solo guarda metadatos: ruta, hash, versión, permisos y visibilidad.',
        estado: 'Planeada',
    },
    {
        icono: FolderTree,
        titulo: 'Expediente tipo carpetas',
        descripcion:
            'Explorador visual Empresa → Sucursal → Colaborador → Expediente, con búsqueda, filtros y estado de avance por colaborador.',
        estado: 'Planeada',
    },
    {
        icono: UserPlus,
        titulo: 'Alta digital',
        descripcion:
            'Preregistro de RH + liga segura con expiración para que el nuevo colaborador capture sus datos, documentos y firme sus avisos.',
        estado: 'Planeada',
    },
    {
        icono: CalendarDays,
        titulo: 'Vacaciones',
        descripcion:
            'Saldos por antigüedad, solicitudes, aprobación por jefe/RH y calendario de sucursal.',
        estado: 'Planeada',
    },
    {
        icono: ClipboardList,
        titulo: 'Solicitudes internas',
        descripcion:
            'Permisos, constancias, aclaraciones y reposición documental en un tablero por estado.',
        estado: 'Planeada',
    },
    {
        icono: LineChart,
        titulo: 'Reportes RH',
        descripcion:
            'Altas, bajas, expedientes completos/incompletos, antigüedad y aniversarios, exportables a Excel/PDF.',
        estado: 'Planeada',
    },
    {
        icono: GraduationCap,
        titulo: 'Capacitación y desempeño',
        descripcion:
            'Cursos, videos, cuestionarios, actividades y sesiones en vivo: todo se conservó y queda oculto hasta esta fase.',
        estado: 'Próximamente',
    },
];

const roadmap = [
    {
        fase: 'Fase 1',
        titulo: 'Portal RH base',
        detalle:
            'Empresas, sucursales, colaboradores, dashboard RH, expedientes.',
        estado: 'En curso',
        icono: Rocket,
    },
    {
        fase: 'Fase 2',
        titulo: 'Documentos y alta digital',
        detalle:
            'Synology, documentos, versiones, avisos, consentimientos, alta con liga.',
        estado: 'Planeada',
        icono: FileText,
    },
    {
        fase: 'Fase 3',
        titulo: 'Vacaciones y solicitudes',
        detalle: 'Saldos, solicitudes, aprobación, calendario.',
        estado: 'Planeada',
        icono: CalendarDays,
    },
    {
        fase: 'Fase 4',
        titulo: 'Reportes y seguimiento',
        detalle: 'Reportes RH, historial laboral, exportaciones.',
        estado: 'Planeada',
        icono: LineChart,
    },
    {
        fase: 'Fase 5',
        titulo: 'Capacitación y desempeño',
        detalle: 'Cursos, videos, evaluaciones, indicadores.',
        estado: 'Planeada',
        icono: GraduationCap,
    },
];

const ESTADO_BADGE: Record<string, 'success' | 'warning' | 'secondary'> = {
    'En curso': 'success',
    Planeada: 'secondary',
    Próximamente: 'warning',
};
</script>

<template>
    <Head title="Planeación Portal RH" />

    <div class="flex flex-col gap-8 p-4">
        <div class="flex flex-col gap-2">
            <Badge variant="outline" class="w-fit gap-1 text-xs">
                <ShieldCheck class="size-3.5" />
                Uso interno · super_admin
            </Badge>
            <h1 class="text-2xl font-semibold tracking-tight">
                Planeación Portal RH
            </h1>
            <p class="max-w-2xl text-sm text-muted-foreground">
                Portal Integral de Colaboradores y Recursos Humanos Mr. Lana: un
                solo lugar para expedientes, documentos, altas, vacaciones,
                solicitudes y reportes, con la estructura multiempresa y
                multisucursal como base y la capacitación conservada como fase
                futura.
            </p>
        </div>

        <Card class="rounded-3xl border-border/60">
            <CardHeader>
                <CardTitle class="text-base"
                    >Estructura organizacional</CardTitle
                >
            </CardHeader>
            <CardContent>
                <div
                    class="flex flex-wrap items-center justify-center gap-3 sm:gap-4"
                >
                    <template
                        v-for="(nivel, index) in estructura"
                        :key="nivel.etiqueta"
                    >
                        <div
                            class="flex flex-col items-center gap-2 rounded-2xl border border-border/60 bg-card px-5 py-4 shadow-sm"
                        >
                            <span
                                class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"
                            >
                                <component :is="nivel.icono" class="size-5" />
                            </span>
                            <span class="text-sm font-medium">{{
                                nivel.etiqueta
                            }}</span>
                        </div>
                        <ArrowRight
                            v-if="index < estructura.length - 1"
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                    </template>
                </div>
                <p class="mt-4 text-center text-xs text-muted-foreground">
                    Todo documento, solicitud, vacaciones y expediente podrá
                    filtrarse por empresa y sucursal.
                </p>
            </CardContent>
        </Card>

        <div class="flex flex-col gap-3">
            <h2 class="text-base font-semibold">Pilares del portal</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Card
                    v-for="pilar in pilares"
                    :key="pilar.titulo"
                    class="rounded-2xl border-border/60 transition-all duration-200 hover:border-primary/40 hover:shadow-md"
                >
                    <CardHeader class="flex-row items-start justify-between">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <component :is="pilar.icono" class="size-4" />
                        </span>
                        <Badge :variant="ESTADO_BADGE[pilar.estado]">{{
                            pilar.estado
                        }}</Badge>
                    </CardHeader>
                    <CardContent>
                        <p class="mb-1 text-sm font-medium">
                            {{ pilar.titulo }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ pilar.descripcion }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>

        <div class="flex flex-col gap-3">
            <h2 class="text-base font-semibold">Roadmap</h2>
            <div class="flex flex-col gap-3">
                <div
                    v-for="etapa in roadmap"
                    :key="etapa.fase"
                    class="flex items-start gap-4 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-secondary)]/10 text-[var(--brand-secondary)]"
                    >
                        <component :is="etapa.icono" class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="text-xs font-semibold text-muted-foreground"
                                >{{ etapa.fase }}</span
                            >
                            <span class="text-sm font-medium">{{
                                etapa.titulo
                            }}</span>
                            <Badge :variant="ESTADO_BADGE[etapa.estado]">{{
                                etapa.estado
                            }}</Badge>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ etapa.detalle }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <Card class="rounded-3xl border-border/60 bg-muted/30">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <Briefcase class="size-4" />
                    Nota sobre capacitación
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p class="text-sm text-muted-foreground">
                    Cursos, videos, biblioteca multimedia, cuestionarios,
                    actividades, sesiones y asistencias no se eliminaron: el
                    feature flag
                    <code class="rounded bg-muted px-1 py-0.5 text-xs"
                        >CAPACITACION_ENABLED</code
                    >
                    los mantiene ocultos del menú y protegidos por middleware
                    hasta la Fase 5. Ver
                    <code class="rounded bg-muted px-1 py-0.5 text-xs"
                        >docs/CAPACITACION_PROXIMAMENTE.md</code
                    >.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
