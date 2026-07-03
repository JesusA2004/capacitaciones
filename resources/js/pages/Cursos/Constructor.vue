<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Archive, Pencil, Plus, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CursoMetaFormDialog from '@/components/Cursos/CursoMetaFormDialog.vue';
import ModuloCard from '@/components/Cursos/ModuloCard.vue';
import ModuloFormDialog from '@/components/Cursos/ModuloFormDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard } from '@/routes';
import { archivar, destroy, index, publicar } from '@/routes/cursos';
import type {
    CursoItem,
    CursoModuloItem,
    CursoRequisitoItem,
    RecursoMultimediaOpcion,
    ResponsableOpcion,
} from '@/types';

const props = defineProps<{
    curso: CursoItem;
    cursosDisponibles: CursoRequisitoItem[];
    responsablesDisponibles: ResponsableOpcion[];
    recursosMultimediaDisponibles: RecursoMultimediaOpcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cursos', href: index.url() },
            { title: 'Constructor de curso', href: '#' },
        ],
    },
});

const { tienePermiso } = usePermisos();
const {
    confirmarEliminacion,
    confirmarPublicacion,
    mostrarExito,
    mostrarError,
} = useAlertas();

const dialogMetaAbierto = ref(false);
const dialogModuloAbierto = ref(false);
const moduloSeleccionado = ref<CursoModuloItem | null>(null);

function abrirCrearModulo() {
    moduloSeleccionado.value = null;
    dialogModuloAbierto.value = true;
}

function abrirEditarModulo(modulo: CursoModuloItem) {
    moduloSeleccionado.value = modulo;
    dialogModuloAbierto.value = true;
}

async function publicarCurso() {
    const confirmado = await confirmarPublicacion(
        `el curso «${props.curso.titulo}»`,
    );

    if (!confirmado) {
        return;
    }

    router.post(
        publicar.url(props.curso.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('El curso se publicó correctamente.'),
            onError: () => mostrarError('No fue posible publicar el curso.'),
        },
    );
}

function archivarCurso() {
    router.post(archivar.url(props.curso.id), {}, { preserveScroll: true });
}

async function eliminarCurso() {
    const confirmado = await confirmarEliminacion(
        `el curso «${props.curso.titulo}» y todo su contenido`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(props.curso.id));
}
</script>

<template>
    <Head :title="curso.titulo" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="mb-1 flex items-center gap-2">
                    <Heading
                        :title="curso.titulo"
                        :description="curso.objetivo ?? undefined"
                    />
                    <Badge
                        :variant="
                            curso.estado === 'publicado'
                                ? 'default'
                                : 'secondary'
                        "
                        >{{ curso.estado }}</Badge
                    >
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="dialogMetaAbierto = true">
                    <Pencil class="size-4" />
                    Editar detalles
                </Button>
                <Button
                    v-if="
                        tienePermiso('cursos.publicar') &&
                        curso.estado !== 'publicado'
                    "
                    @click="publicarCurso"
                >
                    <Send class="size-4" />
                    Publicar
                </Button>
                <Button
                    v-if="
                        tienePermiso('cursos.publicar') &&
                        curso.estado === 'publicado'
                    "
                    variant="outline"
                    @click="archivarCurso"
                >
                    <Archive class="size-4" />
                    Archivar
                </Button>
                <Button
                    v-if="tienePermiso('cursos.eliminar')"
                    variant="outline"
                    @click="eliminarCurso"
                >
                    <Trash2 class="size-4 text-destructive" />
                    Eliminar
                </Button>
            </div>
        </div>

        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Módulos y lecciones
                </h2>
                <Button size="sm" @click="abrirCrearModulo">
                    <Plus class="size-4" />
                    Agregar módulo
                </Button>
            </div>

            <EmptyState
                v-if="!curso.modulos || curso.modulos.length === 0"
                titulo="Sin módulos todavía"
                descripcion="Agrega el primer módulo para comenzar a construir el curso."
            />

            <ModuloCard
                v-for="(modulo, indice) in curso.modulos"
                :key="modulo.id"
                :curso-id="curso.id"
                :modulo="modulo"
                :es-primero="indice === 0"
                :es-ultimo="indice === (curso.modulos?.length ?? 0) - 1"
                :recursos-multimedia-disponibles="recursosMultimediaDisponibles"
                @editar="abrirEditarModulo"
            />
        </div>
    </div>

    <CursoMetaFormDialog
        v-if="dialogMetaAbierto"
        v-model:open="dialogMetaAbierto"
        :curso="curso"
        :cursos-disponibles="cursosDisponibles"
        :responsables-disponibles="responsablesDisponibles"
    />

    <ModuloFormDialog
        v-if="dialogModuloAbierto"
        v-model:open="dialogModuloAbierto"
        :curso-id="curso.id"
        :modulo="moduloSeleccionado"
        :key="moduloSeleccionado?.id ?? 'nuevo'"
    />
</template>
