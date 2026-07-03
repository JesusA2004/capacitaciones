<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronUp,
    FileText,
    Link2,
    Pencil,
    PlayCircle,
    ShieldCheck,
    Trash2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import {
    destroy as destroyLeccion,
    mover as moverLeccion,
} from '@/routes/cursos/lecciones';
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

const iconosPorTipo: Record<string, Component> = {
    video: PlayCircle,
    documento: FileText,
    guia: FileText,
    texto: FileText,
    enlace: Link2,
    confirmacion: ShieldCheck,
};

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
        class="flex items-center justify-between gap-3 rounded-md border bg-background px-3 py-2"
    >
        <div class="flex min-w-0 items-center gap-2">
            <component
                :is="iconosPorTipo[leccion.tipo] ?? FileText"
                class="size-4 shrink-0 text-muted-foreground"
            />
            <span class="truncate text-sm">{{ leccion.titulo }}</span>
            <Badge
                v-if="leccion.obligatoria"
                variant="secondary"
                class="shrink-0"
                >obligatoria</Badge
            >
        </div>
        <div class="flex shrink-0 items-center gap-1">
            <Button
                variant="ghost"
                size="icon"
                :disabled="esPrimera"
                title="Subir"
                @click="mover('arriba')"
            >
                <ChevronUp class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                :disabled="esUltima"
                title="Bajar"
                @click="mover('abajo')"
            >
                <ChevronDown class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                title="Editar"
                @click="emit('editar', leccion)"
            >
                <Pencil class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                title="Eliminar"
                @click="eliminar"
            >
                <Trash2 class="size-4 text-destructive" />
            </Button>
        </div>
    </div>
</template>
