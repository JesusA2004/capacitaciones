<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { update } from '@/routes/actividades';
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
            { title: 'Cursos', href: '/cursos' },
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
    { value: 'archivo', etiqueta: 'Archivo' },
    { value: 'texto', etiqueta: 'Texto' },
    { value: 'enlace', etiqueta: 'Enlace' },
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

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Constructor de actividad"
            :description="`Lección: ${leccion.titulo}`"
        />

        <div class="rounded-lg border p-4">
            <form class="grid gap-4" @submit.prevent="guardar">
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
                        rows="4"
                    />
                    <InputError :message="form.errors.instrucciones" />
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="grid gap-2">
                        <Label>Tipo de entrega</Label>
                        <Select v-model="form.tipo_entrega">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in tiposEntrega"
                                    :key="opcion.value"
                                    :value="opcion.value"
                                >
                                    {{ opcion.etiqueta }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.tipo_entrega" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="calificacion_minima"
                            >Calificación mínima (%)</Label
                        >
                        <Input
                            id="calificacion_minima"
                            v-model.number="form.calificacion_minima"
                            type="number"
                            min="1"
                            max="100"
                        />
                        <InputError
                            :message="form.errors.calificacion_minima"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="fecha_limite">Fecha límite</Label>
                        <Input
                            id="fecha_limite"
                            v-model="form.fecha_limite"
                            type="datetime-local"
                        />
                        <InputError :message="form.errors.fecha_limite" />
                    </div>
                </div>

                <Button
                    type="submit"
                    class="self-start"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Guardar
                </Button>
            </form>
        </div>
    </div>
</template>
