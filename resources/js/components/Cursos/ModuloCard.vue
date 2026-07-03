<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import LeccionFormDialog from '@/components/Cursos/LeccionFormDialog.vue';
import LeccionRow from '@/components/Cursos/LeccionRow.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import {
    destroy as destroyModulo,
    mover as moverModulo,
} from '@/routes/cursos/modulos';
import type {
    CursoModuloItem,
    LeccionItem,
    RecursoMultimediaOpcion,
} from '@/types';

const props = defineProps<{
    cursoId: number;
    modulo: CursoModuloItem;
    esPrimero: boolean;
    esUltimo: boolean;
    recursosMultimediaDisponibles: RecursoMultimediaOpcion[];
}>();

const emit = defineEmits<{
    editar: [modulo: CursoModuloItem];
}>();

const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const dialogLeccionAbierto = ref(false);
const leccionSeleccionada = ref<LeccionItem | null>(null);

function abrirCrearLeccion() {
    leccionSeleccionada.value = null;
    dialogLeccionAbierto.value = true;
}

function abrirEditarLeccion(leccion: LeccionItem) {
    leccionSeleccionada.value = leccion;
    dialogLeccionAbierto.value = true;
}

function mover(direccion: 'arriba' | 'abajo') {
    router.post(
        moverModulo.url({ curso: props.cursoId, modulo: props.modulo.id }),
        { direccion },
        { preserveScroll: true },
    );
}

async function eliminar() {
    const confirmado = await confirmarEliminacion(
        `el módulo «${props.modulo.titulo}» y todas sus lecciones`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(
        destroyModulo.url({ curso: props.cursoId, modulo: props.modulo.id }),
        {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito('El módulo se eliminó correctamente.'),
            onError: () => mostrarError('No fue posible eliminar el módulo.'),
        },
    );
}
</script>

<template>
    <div class="rounded-lg border bg-muted/30">
        <div
            class="flex items-center justify-between gap-3 border-b bg-muted/50 px-4 py-3"
        >
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">
                    {{ modulo.titulo }}
                </p>
                <p
                    v-if="modulo.descripcion"
                    class="truncate text-xs text-muted-foreground"
                >
                    {{ modulo.descripcion }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    :disabled="esPrimero"
                    title="Subir módulo"
                    @click="mover('arriba')"
                >
                    <ChevronUp class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    :disabled="esUltimo"
                    title="Bajar módulo"
                    @click="mover('abajo')"
                >
                    <ChevronDown class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    title="Editar módulo"
                    @click="emit('editar', modulo)"
                >
                    <Pencil class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    title="Eliminar módulo"
                    @click="eliminar"
                >
                    <Trash2 class="size-4 text-destructive" />
                </Button>
            </div>
        </div>

        <div class="flex flex-col gap-2 p-3">
            <LeccionRow
                v-for="(leccion, indice) in modulo.lecciones"
                :key="leccion.id"
                :curso-id="cursoId"
                :modulo-id="modulo.id"
                :leccion="leccion"
                :es-primera="indice === 0"
                :es-ultima="indice === modulo.lecciones.length - 1"
                @editar="abrirEditarLeccion"
            />

            <EmptyState
                v-if="modulo.lecciones.length === 0"
                titulo="Sin lecciones"
                descripcion="Agrega la primera lección de este módulo."
            />

            <Button
                variant="outline"
                size="sm"
                class="self-start"
                @click="abrirCrearLeccion"
            >
                <Plus class="size-4" />
                Agregar lección
            </Button>
        </div>
    </div>

    <LeccionFormDialog
        v-if="dialogLeccionAbierto"
        v-model:open="dialogLeccionAbierto"
        :curso-id="cursoId"
        :modulo-id="modulo.id"
        :leccion="leccionSeleccionada"
        :lecciones-disponibles="modulo.lecciones"
        :recursos-multimedia-disponibles="recursosMultimediaDisponibles"
        :key="leccionSeleccionada?.id ?? 'nueva'"
    />
</template>
