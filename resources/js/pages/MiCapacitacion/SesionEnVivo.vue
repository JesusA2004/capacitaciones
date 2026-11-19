<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarClock,
    CheckCircle2,
    Clock,
    UserCheck,
    Video,
} from '@lucide/vue';
import { computed } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index, show as showCurso } from '@/routes/mi-capacitacion';
import type { AsistenciaItem, LeccionItem, SesionEnVivoItem } from '@/types';

const props = defineProps<{
    leccion: LeccionItem;
    sesion: SesionEnVivoItem;
    miAsistencia: AsistenciaItem | null;
    curso?: { id: number; titulo: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi capacitación', href: index.url() },
            { title: 'Sesión en vivo', href: '#' },
        ],
    },
});

const ESTADO: Record<string, { texto: string; clase: string }> = {
    pendiente: {
        texto: 'Aún no registrada',
        clase: 'bg-muted text-muted-foreground',
    },
    presente: { texto: 'Presente', clase: 'bg-success-soft text-primary' },
    asistencia_parcial: {
        texto: 'Asistencia parcial',
        clase: 'bg-warning-soft text-bronce',
    },
    tarde: { texto: 'Llegó tarde', clase: 'bg-warning-soft text-bronce' },
    ausente: { texto: 'Ausente', clase: 'bg-danger-soft text-destructive' },
    pendiente_revision: {
        texto: 'En revisión',
        clase: 'bg-warning-soft text-bronce',
    },
    corregida_manualmente: {
        texto: 'Corregida',
        clase: 'bg-info-soft text-primary',
    },
};

const inicio = computed(() => new Date(props.sesion.fecha_inicio));
const dia = computed(() =>
    inicio.value.toLocaleDateString('es-MX', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }),
);
const hora = computed(() =>
    inicio.value.toLocaleTimeString('es-MX', {
        hour: '2-digit',
        minute: '2-digit',
    }),
);
const yaPaso = computed(() => inicio.value.getTime() < Date.now());
const estado = computed(
    () => ESTADO[props.miAsistencia?.estado ?? 'pendiente'] ?? ESTADO.pendiente,
);
</script>

<template>
    <Head :title="sesion.titulo" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="sesion.titulo"
            :descripcion="
                curso ? `${curso.titulo} · ${leccion.titulo}` : leccion.titulo
            "
            :icono="Video"
            detalle
        >
            <Button variant="outline" as-child>
                <Link :href="curso ? showCurso(curso.id) : index()">
                    <ArrowLeft class="size-4" />
                    {{ curso ? 'Volver al curso' : 'Mi capacitación' }}
                </Link>
            </Button>
        </CrudPageHeader>

        <div
            class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)]"
        >
            <section
                class="relative entrada-suave overflow-hidden rounded-3xl border border-border/60 bg-linear-to-br from-salvia via-menta to-crema p-6 shadow-sm sm:p-8"
            >
                <span
                    class="pointer-events-none absolute -top-16 -right-10 size-56 rounded-full bg-card/30"
                    aria-hidden="true"
                />
                <div class="relative flex flex-col gap-5">
                    <div class="flex flex-wrap gap-3">
                        <span
                            class="inline-flex items-center gap-2 rounded-2xl bg-card/80 px-4 py-2 text-sm font-medium capitalize shadow-sm"
                        >
                            <CalendarClock class="size-4 text-primary" />
                            {{ dia }}
                        </span>
                        <span
                            class="inline-flex items-center gap-2 rounded-2xl bg-card/80 px-4 py-2 text-sm font-medium tabular-nums shadow-sm"
                        >
                            <Clock class="size-4 text-primary" />
                            {{ hora }} h
                        </span>
                    </div>
                    <p
                        v-if="sesion.descripcion"
                        class="max-w-prose text-sm text-pretty whitespace-pre-line"
                    >
                        {{ sesion.descripcion }}
                    </p>
                    <div>
                        <Button v-if="sesion.enlace_reunion" size="lg" as-child>
                            <a
                                :href="sesion.enlace_reunion"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Video class="size-5" />
                                {{
                                    yaPaso
                                        ? 'Entrar a la sesión'
                                        : 'Unirse a la sesión'
                                }}
                            </a>
                        </Button>
                        <p
                            v-else
                            class="rounded-xl bg-card/70 px-4 py-3 text-sm text-muted-foreground"
                        >
                            El enlace de la reunión todavía no está disponible.
                            Vuelve más cerca de la fecha.
                        </p>
                    </div>
                </div>
            </section>

            <aside
                class="flex entrada-suave flex-col gap-3 rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-salvia text-primary"
                    >
                        <UserCheck class="size-5" />
                    </span>
                    <div>
                        <p class="font-semibold">Tu asistencia</p>
                        <Badge variant="secondary" :class="estado.clase">
                            <CheckCircle2
                                v-if="miAsistencia?.estado === 'presente'"
                                class="size-3"
                            />
                            {{ estado.texto }}
                        </Badge>
                    </div>
                </div>
                <p class="text-sm text-pretty text-muted-foreground">
                    La asistencia se registra durante o después de la sesión;
                    esta lección se completa sola al quedar marcada como
                    presente.
                </p>
            </aside>
        </div>
    </div>
</template>
