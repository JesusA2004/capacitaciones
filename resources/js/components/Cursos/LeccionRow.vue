<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronUp,
    ClipboardCheck,
    Clock,
    FileText,
    HelpCircle,
    Link2,
    Pencil,
    PlayCircle,
    Settings2,
    ShieldCheck,
    Trash2,
    Video,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import {
    destroy as destroyLeccion,
    mover as moverLeccion,
} from '@/routes/cursos/lecciones';
import { edit as editActividad } from '@/routes/cursos/lecciones/actividad';
import { edit as editCuestionario } from '@/routes/cursos/lecciones/cuestionario';
import { edit as editSesion } from '@/routes/cursos/lecciones/sesion';
import type { LeccionItem } from '@/types';

const props = defineProps<{
    cursoId: number;
    moduloId: number;
    leccion: LeccionItem;
    esPrimera: boolean;
    esUltima: boolean;
}>();

const emit = defineEmits<{
    editar: [leccion: LeccionItem];
    eliminada: [];
}>();

const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const TIPOS: Record<
    string,
    { icono: Component; clase: string; etiqueta: string }
> = {
    video: {
        icono: PlayCircle,
        clase: 'bg-info-soft text-primary',
        etiqueta: 'Video',
    },
    documento: {
        icono: FileText,
        clase: 'bg-crema text-bronce',
        etiqueta: 'Documento',
    },
    guia: { icono: FileText, clase: 'bg-crema text-bronce', etiqueta: 'Guía' },
    texto: {
        icono: FileText,
        clase: 'bg-crema text-bronce',
        etiqueta: 'Texto',
    },
    enlace: {
        icono: Link2,
        clase: 'bg-salvia text-primary',
        etiqueta: 'Enlace',
    },
    confirmacion: {
        icono: ShieldCheck,
        clase: 'bg-success-soft text-primary',
        etiqueta: 'Confirmación',
    },
    cuestionario: {
        icono: HelpCircle,
        clase: 'bg-warning-soft text-bronce',
        etiqueta: 'Cuestionario',
    },
    actividad: {
        icono: ClipboardCheck,
        clase: 'bg-menta text-esmeralda',
        etiqueta: 'Actividad',
    },
    sesion_en_vivo: {
        icono: Video,
        clase: 'bg-oro/20 text-bronce',
        etiqueta: 'Sesión en vivo',
    },
};

const tipo = computed(
    () =>
        TIPOS[props.leccion.tipo] ?? {
            icono: FileText,
            clase: 'bg-muted text-muted-foreground',
            etiqueta: props.leccion.tipo,
        },
);

/** Las lecciones calificables tienen su propio constructor. */
const configurar = computed(() => {
    const parametros = {
        curso: props.cursoId,
        modulo: props.moduloId,
        leccion: props.leccion.id,
    };

    switch (props.leccion.tipo) {
        case 'cuestionario':
            return {
                href: editCuestionario.url(parametros),
                texto: 'Preguntas',
            };
        case 'actividad':
            return { href: editActividad.url(parametros), texto: 'Configurar' };
        case 'sesion_en_vivo':
            return { href: editSesion.url(parametros), texto: 'Sesión' };
        default:
            return null;
    }
});

function mover(direccion: 'arriba' | 'abajo') {
    router.post(
        moverLeccion.url({
            curso: props.cursoId,
            modulo: props.moduloId,
            leccion: props.leccion.id,
        }),
        { direccion },
        { preserveScroll: true },
    );
}

async function eliminar() {
    const confirmado = await confirmarEliminacion(
        `la lección «${props.leccion.titulo}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(
        destroyLeccion.url({
            curso: props.cursoId,
            modulo: props.moduloId,
            leccion: props.leccion.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito('La lección se eliminó correctamente.');
                emit('eliminada');
            },
            onError: () => mostrarError('No fue posible eliminar la lección.'),
        },
    );
}
</script>

<template>
    <div
        class="group flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-card px-3 py-2.5 transicion-ui hover:border-oro/40 hover:shadow-sm"
    >
        <div class="flex min-w-0 items-center gap-3">
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                :class="tipo.clase"
                aria-hidden="true"
            >
                <component :is="tipo.icono" class="size-4" />
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">
                    {{ leccion.titulo }}
                </p>
                <p
                    class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                >
                    <span>{{ tipo.etiqueta }}</span>
                    <span
                        v-if="leccion.duracion_estimada_minutos"
                        class="inline-flex items-center gap-1"
                        ><Clock class="size-3" />
                        {{ leccion.duracion_estimada_minutos }} min</span
                    >
                    <span v-if="leccion.obligatoria" class="text-bronce"
                        >Obligatoria</span
                    >
                    <span v-else>Opcional</span>
                </p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-1">
            <Button v-if="configurar" variant="secondary" size="sm" as-child>
                <Link :href="configurar.href">
                    <Settings2 class="size-4" />
                    {{ configurar.texto }}
                </Link>
            </Button>
            <Button
                variant="ghost"
                size="icon"
                :disabled="esPrimera"
                title="Subir"
                aria-label="Subir lección"
                @click="mover('arriba')"
            >
                <ChevronUp class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                :disabled="esUltima"
                title="Bajar"
                aria-label="Bajar lección"
                @click="mover('abajo')"
            >
                <ChevronDown class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                title="Editar"
                aria-label="Editar lección"
                @click="emit('editar', leccion)"
            >
                <Pencil class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                title="Eliminar"
                aria-label="Eliminar lección"
                @click="eliminar"
            >
                <Trash2 class="size-4 text-destructive" />
            </Button>
        </div>
    </div>
</template>
