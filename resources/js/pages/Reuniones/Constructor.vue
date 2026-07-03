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
import { store } from '@/routes/cursos/lecciones/sesion';
import { update } from '@/routes/sesiones';
import type { CursoItem, LeccionItem, SesionEnVivoItem } from '@/types';

const props = defineProps<{
    curso: CursoItem;
    leccion: LeccionItem;
    sesion: SesionEnVivoItem | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cursos', href: '/cursos' },
            { title: 'Constructor de sesión en vivo', href: '#' },
        ],
    },
});

const { mostrarExito } = useAlertas();

function aFechaLocal(valor: string | undefined) {
    if (!valor) {
        return '';
    }

    return valor.slice(0, 16);
}

const form = useForm({
    titulo: props.sesion?.titulo ?? props.leccion.titulo,
    descripcion: props.sesion?.descripcion ?? '',
    proveedor: props.sesion?.proveedor ?? 'manual',
    fecha_inicio: aFechaLocal(props.sesion?.fecha_inicio),
    duracion_minutos: props.sesion?.duracion_minutos ?? 60,
    enlace_reunion: props.sesion?.enlace_reunion ?? '',
});

const proveedores = [
    { value: 'manual', etiqueta: 'Enlace manual' },
    { value: 'google_meet', etiqueta: 'Google Meet' },
    { value: 'zoom', etiqueta: 'Zoom' },
];

function guardar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => mostrarExito('La sesión se guardó correctamente.'),
    };

    if (props.sesion) {
        form.put(update.url(props.sesion.id), opciones);
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
    <Head title="Constructor de sesión en vivo" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Constructor de sesión en vivo"
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
                    <Label for="descripcion">Descripción</Label>
                    <Textarea
                        id="descripcion"
                        v-model="form.descripcion"
                        rows="3"
                    />
                    <InputError :message="form.errors.descripcion" />
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="grid gap-2">
                        <Label>Proveedor</Label>
                        <Select v-model="form.proveedor">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in proveedores"
                                    :key="opcion.value"
                                    :value="opcion.value"
                                >
                                    {{ opcion.etiqueta }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.proveedor" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="fecha_inicio">Fecha y hora</Label>
                        <Input
                            id="fecha_inicio"
                            v-model="form.fecha_inicio"
                            type="datetime-local"
                        />
                        <InputError :message="form.errors.fecha_inicio" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="duracion_minutos">Duración (min)</Label>
                        <Input
                            id="duracion_minutos"
                            v-model.number="form.duracion_minutos"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.duracion_minutos" />
                    </div>
                </div>

                <div v-if="form.proveedor === 'manual'" class="grid gap-2">
                    <Label for="enlace_reunion">Enlace de la reunión</Label>
                    <Input
                        id="enlace_reunion"
                        v-model="form.enlace_reunion"
                        placeholder="https://"
                    />
                    <InputError :message="form.errors.enlace_reunion" />
                </div>
                <p
                    v-else
                    class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
                >
                    El enlace se genera automáticamente al guardar, si la
                    integración está configurada. Si no lo está, puedes agregar
                    uno manualmente después de guardar.
                </p>

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
