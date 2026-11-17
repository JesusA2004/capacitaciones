<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Archive,
    BookOpen,
    ClipboardCheck,
    Clock,
    Layers,
    MoreHorizontal,
    Pencil,
    Plus,
    Send,
    Trash2,
    UserPlus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EncabezadoCurso from '@/components/Capacitacion/EncabezadoCurso.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import CursoMetaFormDialog from '@/components/Cursos/CursoMetaFormDialog.vue';
import ModuloCard from '@/components/Cursos/ModuloCard.vue';
import ModuloFormDialog from '@/components/Cursos/ModuloFormDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { useAlertas } from '@/composables/useAlertas';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard } from '@/routes';
import { create as nuevaAsignacion } from '@/routes/asignaciones';
import { index as indexCapacitacion } from '@/routes/capacitacion';
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
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Cursos', href: index.url() },
            { title: 'Contenido del curso', href: '#' },
        ],
    },
});

const ESTADO_ETIQUETA: Record<string, string> = {
    borrador: 'Borrador',
    publicado: 'Publicado',
    archivado: 'Archivado',
};

const TIPOS_CALIFICABLES = ['cuestionario', 'actividad', 'sesion_en_vivo'];

const { tienePermiso } = usePermisos();
const {
    confirmarEliminacion,
    confirmarPublicacion,
    confirmarAccion,
    mostrarExito,
    mostrarError,
} = useAlertas();

const modulos = computed(() => props.curso.modulos ?? []);
const lecciones = computed(() => modulos.value.flatMap((m) => m.lecciones));
const totalLecciones = computed(() => lecciones.value.length);
const calificables = computed(
    () =>
        lecciones.value.filter((l) => TIPOS_CALIFICABLES.includes(l.tipo))
            .length,
);
const duracionTexto = computed(() => {
    const minutos =
        props.curso.duracion_estimada_minutos ??
        lecciones.value.reduce(
            (suma, l) => suma + (l.duracion_estimada_minutos ?? 0),
            0,
        );

    if (!minutos) {
        return '—';
    }

    return minutos >= 60
        ? `${Math.floor(minutos / 60)} h ${minutos % 60 ? `${minutos % 60} min` : ''}`.trim()
        : `${minutos} min`;
});

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

async function archivarCurso() {
    const confirmado = await confirmarAccion(
        '¿Archivar el curso?',
        `«${props.curso.titulo}» pasa a «Archivado» y deja de mostrarse como publicado. El avance, el historial y las constancias se conservan.`,
        'Sí, archivar',
    );

    if (!confirmado) {
        return;
    }

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

    <div class="pagina-ancha flex flex-col gap-6">
        <EncabezadoCurso
            :curso="{
                id: curso.id,
                titulo: curso.titulo,
                estado: curso.estado,
                estado_etiqueta: ESTADO_ETIQUETA[curso.estado] ?? curso.estado,
                responsable: curso.responsable
                    ? `${curso.responsable.name} ${curso.responsable.apellidos ?? ''}`
                    : null,
                descripcion: curso.objetivo ?? curso.descripcion,
            }"
            actual="contenido"
        >
            <Button
                variant="secondary"
                class="bg-card/80"
                @click="dialogMetaAbierto = true"
            >
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
                    tienePermiso('asignaciones.crear') &&
                    curso.estado === 'publicado'
                "
                as-child
            >
                <Link :href="nuevaAsignacion({ query: { curso: curso.id } })">
                    <UserPlus class="size-4" />
                    Asignar
                </Link>
            </Button>
            <DropdownMenu
                v-if="
                    (tienePermiso('cursos.publicar') &&
                        curso.estado === 'publicado') ||
                    tienePermiso('cursos.eliminar')
                "
            >
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="secondary"
                        size="icon"
                        class="bg-card/80"
                        aria-label="Más acciones"
                    >
                        <MoreHorizontal class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-if="
                            tienePermiso('cursos.publicar') &&
                            curso.estado === 'publicado'
                        "
                        @select="archivarCurso"
                    >
                        <Archive class="size-4" />
                        Archivar curso
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="tienePermiso('cursos.eliminar')"
                        class="text-destructive focus:text-destructive"
                        @select="eliminarCurso"
                    >
                        <Trash2 class="size-4" />
                        Eliminar curso
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </EncabezadoCurso>

        <div class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4">
            <MetricCard
                etiqueta="Módulos"
                :valor="modulos.length"
                :icono="Layers"
                color-clase="bg-salvia text-primary"
            />
            <MetricCard
                etiqueta="Lecciones"
                :valor="totalLecciones"
                :icono="BookOpen"
                color-clase="bg-menta text-esmeralda"
            />
            <MetricCard
                etiqueta="Calificables"
                :valor="calificables"
                :icono="ClipboardCheck"
                color-clase="bg-warning-soft text-bronce"
            />
            <MetricCard
                etiqueta="Duración estimada"
                :valor="duracionTexto"
                :icono="Clock"
                color-clase="bg-oro/20 text-bronce"
            />
        </div>

        <div
            v-if="curso.estado !== 'publicado' && totalLecciones > 0"
            class="flex entrada-suave items-start gap-3 rounded-2xl border border-border/60 bg-warning-soft/60 px-4 py-3 text-sm"
        >
            <Send class="mt-0.5 size-4 shrink-0 text-bronce" />
            <p class="text-pretty">
                El curso está en
                <strong>{{
                    ESTADO_ETIQUETA[curso.estado]?.toLowerCase() ?? curso.estado
                }}</strong
                >: nadie lo ve todavía. Cuando el contenido esté listo,
                publícalo y asígnalo para abrir su aula.
            </p>
        </div>

        <section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold">Módulos y lecciones</h2>
                    <p class="text-sm text-muted-foreground">
                        Ordena el contenido con las flechas; los cuestionarios,
                        actividades y sesiones se configuran desde su lección.
                    </p>
                </div>
                <Button @click="abrirCrearModulo">
                    <Plus class="size-4" />
                    Agregar módulo
                </Button>
            </div>

            <EmptyState
                v-if="modulos.length === 0"
                :icono="Layers"
                titulo="Sin módulos todavía"
                descripcion="Agrega el primer módulo para comenzar a construir el curso."
                class="rounded-2xl border border-dashed border-border bg-card"
            >
                <Button variant="outline" @click="abrirCrearModulo">
                    <Plus class="size-4" />
                    Agregar el primer módulo
                </Button>
            </EmptyState>

            <ListaAnimada
                v-else
                tag="div"
                :cantidad="modulos.length"
                class="grid gap-4 2xl:grid-cols-2"
            >
                <ModuloCard
                    v-for="(modulo, indice) in modulos"
                    :key="modulo.id"
                    :curso-id="curso.id"
                    :modulo="modulo"
                    :numero="indice + 1"
                    :es-primero="indice === 0"
                    :es-ultimo="indice === modulos.length - 1"
                    :recursos-multimedia-disponibles="
                        recursosMultimediaDisponibles
                    "
                    @editar="abrirEditarModulo"
                />
            </ListaAnimada>
        </section>
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
