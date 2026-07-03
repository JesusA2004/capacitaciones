<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Award,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ClipboardCheck,
    FileText,
    HelpCircle,
    Link2,
    Lock,
    PlayCircle,
    ShieldCheck,
    Video,
} from '@lucide/vue';
import type { Component } from 'vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ReproductorVideo from '@/components/MiCapacitacion/ReproductorVideo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index } from '@/routes/mi-capacitacion';
import { descargar as descargarConstancia } from '@/routes/mi-capacitacion/constancias';
import { completar } from '@/routes/mi-capacitacion/lecciones';
import { show as showActividad } from '@/routes/mi-capacitacion/lecciones/actividad';
import { show as showCuestionario } from '@/routes/mi-capacitacion/lecciones/cuestionario';
import { show as showSesion } from '@/routes/mi-capacitacion/lecciones/sesion';
import type {
    CertificadoItem,
    CursoItem,
    EstadoLeccion,
    InscripcionResumen,
} from '@/types';

defineProps<{
    curso: CursoItem;
    inscripcion: InscripcionResumen;
    estadoLecciones: Record<number, EstadoLeccion>;
    certificado: CertificadoItem | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi capacitación', href: index.url() },
            { title: 'Curso', href: '#' },
        ],
    },
});

const { mostrarAdvertencia, mostrarExito } = useAlertas();

const leccionAbiertaId = ref<number | null>(null);

const iconosPorTipo: Record<string, Component> = {
    video: PlayCircle,
    documento: FileText,
    guia: FileText,
    texto: FileText,
    enlace: Link2,
    cuestionario: HelpCircle,
    actividad: ClipboardCheck,
    sesion_en_vivo: Video,
    confirmacion: ShieldCheck,
};

function alternarLeccion(leccionId: number, estado: EstadoLeccion) {
    if (estado.bloqueada) {
        mostrarAdvertencia(
            estado.motivo_bloqueo ?? 'Esta lección está bloqueada.',
        );

        return;
    }

    leccionAbiertaId.value =
        leccionAbiertaId.value === leccionId ? null : leccionId;
}

function completarLeccion(leccionId: number) {
    router.post(
        completar.url(leccionId),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Lección completada correctamente.'),
        },
    );
}

function alCompletarVideo() {
    router.reload({ only: ['estadoLecciones'] });
}
</script>

<template>
    <Head :title="curso.titulo" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <Heading
                :title="curso.titulo"
                :description="curso.objetivo ?? undefined"
            />
            <div class="mt-2 flex items-center gap-2">
                <Badge
                    :variant="
                        inscripcion.estado === 'completada'
                            ? 'default'
                            : 'secondary'
                    "
                >
                    {{
                        inscripcion.estado === 'completada'
                            ? 'Completado'
                            : 'En progreso'
                    }}
                </Badge>

                <Button v-if="certificado" as-child size="sm" variant="outline">
                    <a :href="descargarConstancia.url(certificado.id)">
                        <Award class="size-4" />
                        Descargar constancia
                    </a>
                </Button>
            </div>
        </div>

        <div
            v-for="modulo in curso.modulos"
            :key="modulo.id"
            class="rounded-lg border"
        >
            <div class="border-b bg-muted/50 px-4 py-3">
                <p class="text-sm font-semibold">{{ modulo.titulo }}</p>
                <p
                    v-if="modulo.descripcion"
                    class="text-xs text-muted-foreground"
                >
                    {{ modulo.descripcion }}
                </p>
            </div>

            <div class="flex flex-col divide-y">
                <div v-for="leccion in modulo.lecciones" :key="leccion.id">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-muted/30"
                        :class="{
                            'opacity-60':
                                estadoLecciones[leccion.id]?.bloqueada,
                        }"
                        @click="
                            alternarLeccion(
                                leccion.id,
                                estadoLecciones[leccion.id],
                            )
                        "
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <component
                                :is="iconosPorTipo[leccion.tipo] ?? FileText"
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="truncate text-sm">{{
                                leccion.titulo
                            }}</span>
                            <Badge
                                v-if="leccion.obligatoria"
                                variant="secondary"
                                class="shrink-0"
                                >obligatoria</Badge
                            >
                        </div>
                        <div
                            class="flex shrink-0 items-center gap-2 text-muted-foreground"
                        >
                            <Lock
                                v-if="estadoLecciones[leccion.id]?.bloqueada"
                                class="size-4"
                            />
                            <CheckCircle2
                                v-else-if="
                                    estadoLecciones[leccion.id]?.completada
                                "
                                class="size-4 text-[var(--success)]"
                            />
                            <component
                                :is="
                                    leccionAbiertaId === leccion.id
                                        ? ChevronDown
                                        : ChevronRight
                                "
                                class="size-4"
                            />
                        </div>
                    </button>

                    <div
                        v-if="leccionAbiertaId === leccion.id"
                        class="space-y-3 border-t bg-muted/20 px-4 py-3"
                    >
                        <ReproductorVideo
                            v-if="
                                leccion.tipo === 'video' &&
                                leccion.recurso_multimedia_id
                            "
                            :key="leccion.id"
                            :leccion-id="leccion.id"
                            @completada="alCompletarVideo"
                        />

                        <a
                            v-if="leccion.tipo === 'cuestionario'"
                            :href="showCuestionario.url(leccion.id)"
                            class="inline-block text-sm font-medium text-primary underline"
                        >
                            Resolver cuestionario
                        </a>

                        <a
                            v-if="leccion.tipo === 'actividad'"
                            :href="showActividad.url(leccion.id)"
                            class="inline-block text-sm font-medium text-primary underline"
                        >
                            Ver actividad
                        </a>

                        <a
                            v-if="leccion.tipo === 'sesion_en_vivo'"
                            :href="showSesion.url(leccion.id)"
                            class="inline-block text-sm font-medium text-primary underline"
                        >
                            Ver sesión en vivo
                        </a>
                        <p
                            v-if="leccion.contenido"
                            class="text-sm whitespace-pre-line"
                        >
                            {{ leccion.contenido }}
                        </p>
                        <a
                            v-if="leccion.url"
                            :href="leccion.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm text-primary underline"
                        >
                            Abrir enlace
                        </a>
                        <Button
                            v-if="
                                leccion.tipo !== 'video' &&
                                leccion.tipo !== 'cuestionario' &&
                                leccion.tipo !== 'actividad' &&
                                leccion.tipo !== 'sesion_en_vivo' &&
                                !estadoLecciones[leccion.id]?.completada
                            "
                            size="sm"
                            @click="completarLeccion(leccion.id)"
                        >
                            Marcar como completada
                        </Button>
                        <p
                            v-else-if="estadoLecciones[leccion.id]?.completada"
                            class="text-sm text-muted-foreground"
                        >
                            Ya completaste esta lección.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
