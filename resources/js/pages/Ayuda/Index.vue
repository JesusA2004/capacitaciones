<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    Briefcase,
    Cake,
    ClipboardList,
    Compass,
    FolderKanban,
    LayoutGrid,
    UserRound,
} from '@lucide/vue';
import { programarTourPendiente } from '@/composables/useTourGuiado';
import { cn } from '@/lib/utils';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ayuda', href: '/ayuda' }],
    },
});

type Modulo = {
    tourId: string;
    titulo: string;
    href: string;
    icono: unknown;
    descripcion: string;
    tono: string;
};

type Seccion = { titulo: string; modulos: Modulo[] };

const TONO_PANEL = 'bg-blue-500/10 text-blue-600 dark:text-blue-400';
const TONO_PERSONAL = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
const TONO_RECLUTAMIENTO = 'bg-amber-500/10 text-amber-600 dark:text-amber-400';
const TONO_ANALISIS = 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400';

const secciones: Seccion[] = [
    {
        titulo: 'Panel',
        modulos: [
            {
                tourId: 'dashboard',
                titulo: 'Inicio',
                href: '/dashboard',
                icono: LayoutGrid,
                descripcion:
                    'Resumen operativo: colaboradores, vacantes, cumpleaños próximos y demás KPIs de un vistazo.',
                tono: TONO_PANEL,
            },
        ],
    },
    {
        titulo: 'Personal',
        modulos: [
            {
                tourId: 'expedientes',
                titulo: 'Expedientes',
                href: '/rh/expedientes',
                icono: FolderKanban,
                descripcion:
                    'Pantalla maestra de personas: datos, documentos, cuenta de acceso e historial laboral de cada colaborador.',
                tono: TONO_PERSONAL,
            },
            {
                tourId: 'solicitudes',
                titulo: 'Solicitudes',
                href: '/rh/solicitudes',
                icono: ClipboardList,
                descripcion:
                    'Bandeja unificada de vacaciones, permisos, bajas y demás solicitudes de los colaboradores.',
                tono: TONO_PERSONAL,
            },
            {
                tourId: 'cumpleanos',
                titulo: 'Cumpleaños',
                href: '/rh/cumpleanos',
                icono: Cake,
                descripcion:
                    'Calendario de cumpleaños del equipo, con tarjeta de felicitación personalizable.',
                tono: TONO_PERSONAL,
            },
        ],
    },
    {
        titulo: 'Reclutamiento y vacantes',
        modulos: [
            {
                tourId: 'vacantes',
                titulo: 'Vacantes',
                href: '/rh/vacantes',
                icono: Briefcase,
                descripcion:
                    'Tablero de cobertura de plantilla: mueve una vacante entre estados o cúbrela con un candidato.',
                tono: TONO_RECLUTAMIENTO,
            },
            {
                tourId: 'candidatos',
                titulo: 'Candidatos',
                href: '/rh/candidatos',
                icono: UserRound,
                descripcion:
                    'Tablero de reclutamiento por fases sucesivas, del primer contacto hasta la contratación.',
                tono: TONO_RECLUTAMIENTO,
            },
        ],
    },
    {
        titulo: 'Análisis',
        modulos: [
            {
                tourId: 'reportes',
                titulo: 'Reportes',
                href: '/reportes',
                icono: Activity,
                descripcion:
                    'Tablas cruzadas filtrables, con gráficas, exportables a Excel o PDF.',
                tono: TONO_ANALISIS,
            },
        ],
    },
];

function irYGuiar(modulo: Modulo): void {
    programarTourPendiente(modulo.href, modulo.tourId);
    router.visit(modulo.href);
}
</script>

<template>
    <Head title="Ayuda" />

    <div class="flex w-full flex-col gap-8 p-4">
        <div
            class="flex flex-col items-center gap-6 rounded-2xl bg-gradient-to-br from-primary/10 via-primary/5 to-transparent p-6 text-center sm:p-10"
        >
            <span
                class="flex size-16 items-center justify-center rounded-full bg-primary/10 text-primary"
            >
                <Compass class="size-8" />
            </span>

            <div class="max-w-xl space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Guía del sistema
                </h1>
                <p class="text-sm text-pretty text-muted-foreground sm:text-base">
                    Elige un módulo para ver de qué se trata. "Ver cómo
                    funciona" te lleva ahí mismo y arranca un recorrido guiado
                    que resalta, sobre la propia pantalla, para qué sirve cada
                    parte.
                </p>
            </div>
        </div>

        <div
            v-for="(seccion, indiceSeccion) in secciones"
            :key="seccion.titulo"
            class="animate-in fade-in slide-in-from-bottom-2 fill-mode-both space-y-3"
            :style="{ animationDelay: `${indiceSeccion * 60}ms` }"
        >
            <h2
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ seccion.titulo }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="modulo in seccion.modulos"
                    :key="modulo.tourId"
                    class="flex flex-col gap-3 rounded-xl border p-4 transition-[border-color,box-shadow] duration-200 hover:border-primary/20 hover:shadow-sm"
                >
                    <div class="flex items-start gap-3">
                        <span
                            :class="
                                cn(
                                    'flex size-9 shrink-0 items-center justify-center rounded-full',
                                    modulo.tono,
                                )
                            "
                        >
                            <component :is="modulo.icono" class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="font-medium">{{ modulo.titulo }}</p>
                            <p class="text-sm text-pretty text-muted-foreground">
                                {{ modulo.descripcion }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-auto flex items-center gap-2 pt-1">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                            @click="irYGuiar(modulo)"
                        >
                            <Compass class="size-3.5" /> Ver cómo funciona
                        </button>
                        <Link
                            :href="modulo.href"
                            class="ml-auto text-xs text-muted-foreground hover:underline"
                        >
                            Ir directo
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
