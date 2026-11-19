<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ClipboardCheck,
    FileUp,
    Link2,
    Presentation,
    Save,
    Type,
} from '@lucide/vue';
import DateTimePicker from '@/components/Common/DateTimePicker.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { update } from '@/routes/actividades';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import { aula, edit, index as indexCursos } from '@/routes/cursos';
import { store } from '@/routes/cursos/lecciones/actividad';
import type { ActividadItem, CursoItem, LeccionItem } from '@/types';

const props = defineProps<{
    curso: CursoItem;
    leccion: LeccionItem;
    actividad: ActividadItem | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Cursos', href: indexCursos() },
            { title: 'Constructor de actividad', href: '#' },
        ],
    },
});

const { mostrarExito } = useAlertas();

const form = useForm({
    titulo: props.actividad?.titulo ?? props.leccion.titulo,
    instrucciones: props.actividad?.instrucciones ?? '',
    tipo_entrega: props.actividad?.tipo_entrega ?? 'archivo',
    calificacion_minima: props.actividad?.calificacion_minima ?? 80,
    fecha_limite: props.actividad?.fecha_limite ?? '',
});

const tiposEntrega = [
    {
        value: 'archivo',
        etiqueta: 'Archivo',
        ayuda: 'PDF, imagen, documento…',
        icono: FileUp,
    },
    {
        value: 'texto',
        etiqueta: 'Texto',
        ayuda: 'Escribe su respuesta',
        icono: Type,
    },
    {
        value: 'enlace',
        etiqueta: 'Enlace',
        ayuda: 'Pega una liga',
        icono: Link2,
    },
];

function guardar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () =>
            mostrarExito('La configuración se guardó correctamente.'),
    };

    if (props.actividad) {
        form.put(update.url(props.actividad.id), opciones);
    } else {
        form.post(
            store.url({
                curso: props.curso.id,
                modulo: props.leccion.curso_modulo_id,
                leccion: props.leccion.id,
            }),
            opciones,
        );
    }
}
</script>

<template>
    <Head title="Constructor de actividad" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="actividad ? 'Actividad' : 'Nueva actividad'"
            :descripcion="`${curso.titulo} · Lección «${leccion.titulo}»`"
            :icono="ClipboardCheck"
            detalle
        >
            <Button variant="outline" as-child>
                <Link :href="edit(curso.id)">
                    <ArrowLeft class="size-4" /> Volver al contenido
                </Link>
            </Button>
            <Button variant="outline" as-child>
                <Link :href="aula(curso.id)">
                    <Presentation class="size-4" /> Aula
                </Link>
            </Button>
        </CrudPageHeader>

        <form
            class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)]"
            @submit.prevent="guardar"
        >
            <section
                class="flex entrada-suave flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
            >
                <div class="grid gap-2">
                    <Label for="titulo">Título</Label>
                    <Input id="titulo" v-model="form.titulo" />
                    <InputError :message="form.errors.titulo" />
                </div>

                <div class="grid gap-2">
                    <Label for="instrucciones">Instrucciones</Label>
                    <Textarea
                        id="instrucciones"
                        v-model="form.instrucciones"
                        rows="8"
                        placeholder="Explica qué debe entregar la persona y cómo se evaluará."
                    />
                    <InputError :message="form.errors.instrucciones" />
                </div>

                <div class="grid gap-2">
                    <Label>Tipo de entrega</Label>
                    <div
                        class="grid grid-cols-1 gap-2 sm:grid-cols-3"
                        role="radiogroup"
                        aria-label="Tipo de entrega"
                    >
                        <button
                            v-for="opcion in tiposEntrega"
                            :key="opcion.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.tipo_entrega === opcion.value"
                            class="flex presionable items-center gap-3 rounded-xl border px-3 py-3 text-left text-sm transicion-ui"
                            :class="
                                form.tipo_entrega === opcion.value
                                    ? 'border-primary/40 bg-salvia/60 shadow-sm'
                                    : 'border-border/60 hover:bg-crema/40'
                            "
                            @click="form.tipo_entrega = opcion.value"
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-card text-primary"
                            >
                                <component :is="opcion.icono" class="size-4" />
                            </span>
                            <span>
                                <span class="block font-medium">{{
                                    opcion.etiqueta
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    opcion.ayuda
                                }}</span>
                            </span>
                        </button>
                    </div>
                    <InputError :message="form.errors.tipo_entrega" />
                </div>
            </section>

            <aside
                class="flex entrada-suave flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm lg:sticky lg:top-20"
            >
                <h2 class="font-semibold">Evaluación y plazo</h2>
                <div class="grid gap-2">
                    <Label for="calificacion_minima"
                        >Calificación mínima para aprobar (%)</Label
                    >
                    <Input
                        id="calificacion_minima"
                        v-model.number="form.calificacion_minima"
                        type="number"
                        min="1"
                        max="100"
                    />
                    <InputError :message="form.errors.calificacion_minima" />
                </div>

                <div class="grid gap-2">
                    <Label for="fecha_limite">Fecha límite</Label>
                    <DateTimePicker
                        v-model="form.fecha_limite"
                        id="fecha_limite"
                    />
                    <InputError :message="form.errors.fecha_limite" />
                </div>

                <p
                    class="rounded-xl bg-crema/50 px-3 py-2 text-xs text-pretty text-muted-foreground"
                >
                    Cada entrega llega a «Calificar actividades»; la lección se
                    completa cuando la entrega se aprueba.
                </p>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    Guardar actividad
                </Button>
            </aside>
        </form>
    </div>
</template>
