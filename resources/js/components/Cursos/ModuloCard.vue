<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    BookOpen,
    ChevronDown,
    ChevronUp,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import LeccionFormDialog from '@/components/Cursos/LeccionFormDialog.vue';
import LeccionRow from '@/components/Cursos/LeccionRow.vue';
import { Button } from '@/components/ui/button';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
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
    /** Número del módulo dentro del curso (1, 2…). */
    numero?: number;
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
    <section
        class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm transicion-ui hover:shadow-md"
    >
        <header
            class="flex items-center justify-between gap-3 border-b border-border/60 bg-crema/40 px-4 py-3"
        >
            <div class="flex min-w-0 items-center gap-3">
                <span
                    v-if="numero"
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-card text-sm font-bold text-bronce tabular-nums shadow-sm ring-1 ring-border/60"
                    aria-hidden="true"
                    >{{ numero }}</span
                >
                <div class="min-w-0">
                    <h3 class="truncate font-semibold">
                        {{ modulo.titulo }}
                    </h3>
                    <p class="truncate text-xs text-muted-foreground">
                        {{
                            modulo.descripcion ||
                            `${modulo.lecciones.length} ${modulo.lecciones.length === 1 ? 'lección' : 'lecciones'}`
                        }}
                    </p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    :disabled="esPrimero"
                    title="Subir módulo"
                    aria-label="Subir módulo"
                    @click="mover('arriba')"
                >
                    <ChevronUp class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    :disabled="esUltimo"
                    title="Bajar módulo"
                    aria-label="Bajar módulo"
                    @click="mover('abajo')"
                >
                    <ChevronDown class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    title="Editar módulo"
                    aria-label="Editar módulo"
                    @click="emit('editar', modulo)"
                >
                    <Pencil class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    title="Eliminar módulo"
                    aria-label="Eliminar módulo"
                    @click="eliminar"
                >
                    <Trash2 class="size-4 text-destructive" />
                </Button>
            </div>
        </header>

        <div class="flex flex-col gap-2 p-3">
            <ListaAnimada
                v-if="modulo.lecciones.length"
                tag="div"
                :cantidad="modulo.lecciones.length"
                class="flex flex-col gap-2"
            >
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
            </ListaAnimada>

            <EmptyState
                v-else
                :icono="BookOpen"
                titulo="Sin lecciones"
                descripcion="Agrega la primera lección de este módulo: video, documento, cuestionario, actividad o sesión en vivo."
                class="py-6"
            />

            <Button
                variant="outline"
                size="sm"
                class="self-start border-dashed"
                @click="abrirCrearLeccion"
            >
                <Plus class="size-4" />
                Agregar lección
            </Button>
        </div>
    </section>

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
