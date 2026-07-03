<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { store, update } from '@/routes/cursos/lecciones';
import { edit as editActividad } from '@/routes/cursos/lecciones/actividad';
import { edit as editCuestionario } from '@/routes/cursos/lecciones/cuestionario';
import { edit as editSesion } from '@/routes/cursos/lecciones/sesion';
import { index as indexMultimedia } from '@/routes/multimedia';
import type { LeccionItem, RecursoMultimediaOpcion } from '@/types';

const props = defineProps<{
    open: boolean;
    cursoId: number;
    moduloId: number;
    leccion?: LeccionItem | null;
    leccionesDisponibles: LeccionItem[];
    recursosMultimediaDisponibles: RecursoMultimediaOpcion[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const tiposDisponibles = [
    { value: 'video', etiqueta: 'Video' },
    { value: 'documento', etiqueta: 'Documento' },
    { value: 'guia', etiqueta: 'Guía' },
    { value: 'texto', etiqueta: 'Texto' },
    { value: 'enlace', etiqueta: 'Enlace' },
    { value: 'cuestionario', etiqueta: 'Cuestionario' },
    { value: 'actividad', etiqueta: 'Actividad' },
    { value: 'sesion_en_vivo', etiqueta: 'Sesión en vivo' },
    { value: 'confirmacion', etiqueta: 'Confirmación de lectura' },
];

const form = useForm({
    titulo: props.leccion?.titulo ?? '',
    tipo: props.leccion?.tipo ?? 'texto',
    contenido: props.leccion?.contenido ?? '',
    url: props.leccion?.url ?? '',
    recurso_multimedia_id: props.leccion?.recurso_multimedia_id
        ? String(props.leccion.recurso_multimedia_id)
        : '',
    obligatoria: props.leccion?.obligatoria ?? true,
    duracion_estimada_minutos: props.leccion?.duracion_estimada_minutos ?? '',
    requisitos_previos: (props.leccion?.requisitos ?? []).map((r) =>
        String(r.id),
    ),
});

const requiereUrl = computed(() => form.tipo === 'enlace');
const requiereRecurso = computed(
    () => form.tipo === 'video' || form.tipo === 'documento',
);

const opcionesRecurso = computed(() =>
    props.recursosMultimediaDisponibles.filter((r) => r.tipo === form.tipo),
);

const opcionesRequisitos = computed(() =>
    props.leccionesDisponibles.filter((l) => l.id !== props.leccion?.id),
);

function alternarRequisito(id: number, marcado: boolean) {
    const valor = String(id);
    form.requisitos_previos = marcado
        ? [...new Set([...form.requisitos_previos, valor])]
        : form.requisitos_previos.filter((r) => r !== valor);
}

function enviar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    const transformado = form.transform((datos) => ({
        ...datos,
        recurso_multimedia_id: datos.recurso_multimedia_id || null,
    }));

    if (props.leccion) {
        transformado.put(
            update.url({
                curso: props.cursoId,
                modulo: props.moduloId,
                leccion: props.leccion.id,
            }),
            opciones,
        );
    } else {
        transformado.post(
            store.url({ curso: props.cursoId, modulo: props.moduloId }),
            opciones,
        );
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    leccion ? 'Editar lección' : 'Nueva lección'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="titulo">Título</Label>
                    <Input id="titulo" v-model="form.titulo" autofocus />
                    <InputError :message="form.errors.titulo" />
                </div>

                <div class="grid gap-2">
                    <Label>Tipo</Label>
                    <Select v-model="form.tipo">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in tiposDisponibles"
                                :key="opcion.value"
                                :value="opcion.value"
                            >
                                {{ opcion.etiqueta }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.tipo" />
                </div>

                <div v-if="requiereUrl" class="grid gap-2">
                    <Label for="url">Enlace (URL)</Label>
                    <Input id="url" v-model="form.url" placeholder="https://" />
                    <InputError :message="form.errors.url" />
                </div>

                <div
                    v-if="form.tipo === 'cuestionario' && leccion"
                    class="rounded-md border border-dashed p-3 text-sm"
                >
                    <p class="mb-2 text-muted-foreground">
                        Las preguntas y la calificación mínima se configuran en
                        una pantalla aparte.
                    </p>
                    <a
                        :href="
                            editCuestionario.url({
                                curso: cursoId,
                                modulo: moduloId,
                                leccion: leccion.id,
                            })
                        "
                        class="font-medium text-primary underline"
                    >
                        Configurar cuestionario
                    </a>
                </div>

                <div
                    v-if="form.tipo === 'actividad' && leccion"
                    class="rounded-md border border-dashed p-3 text-sm"
                >
                    <p class="mb-2 text-muted-foreground">
                        Las instrucciones y el tipo de entrega se configuran en
                        una pantalla aparte.
                    </p>
                    <a
                        :href="
                            editActividad.url({
                                curso: cursoId,
                                modulo: moduloId,
                                leccion: leccion.id,
                            })
                        "
                        class="font-medium text-primary underline"
                    >
                        Configurar actividad
                    </a>
                </div>

                <div
                    v-if="form.tipo === 'sesion_en_vivo' && leccion"
                    class="rounded-md border border-dashed p-3 text-sm"
                >
                    <p class="mb-2 text-muted-foreground">
                        La fecha, el proveedor y el enlace de la reunión se
                        configuran en una pantalla aparte.
                    </p>
                    <a
                        :href="
                            editSesion.url({
                                curso: cursoId,
                                modulo: moduloId,
                                leccion: leccion.id,
                            })
                        "
                        class="font-medium text-primary underline"
                    >
                        Configurar sesión en vivo
                    </a>
                </div>

                <div v-if="requiereRecurso" class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label>Recurso multimedia</Label>
                        <a
                            :href="indexMultimedia.url()"
                            target="_blank"
                            class="text-xs text-primary underline"
                        >
                            Ir a la biblioteca multimedia
                        </a>
                    </div>
                    <Select v-model="form.recurso_multimedia_id">
                        <SelectTrigger class="w-full">
                            <SelectValue
                                placeholder="Selecciona un archivo ya cargado"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opcionesRecurso"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                            >
                                {{ opcion.nombre_original }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.recurso_multimedia_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="contenido">Contenido</Label>
                    <Textarea
                        id="contenido"
                        v-model="form.contenido"
                        rows="4"
                    />
                    <InputError :message="form.errors.contenido" />
                </div>

                <div class="grid gap-2">
                    <Label for="duracion">Duración estimada (minutos)</Label>
                    <Input
                        id="duracion"
                        v-model.number="form.duracion_estimada_minutos"
                        type="number"
                        min="1"
                    />
                    <InputError
                        :message="form.errors.duracion_estimada_minutos"
                    />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.obligatoria"
                        @update:model-value="(v) => (form.obligatoria = !!v)"
                    />
                    Lección obligatoria
                </label>

                <div v-if="opcionesRequisitos.length" class="grid gap-2">
                    <Label>Requiere haber completado antes</Label>
                    <div
                        class="grid max-h-28 gap-2 overflow-y-auto rounded-md border p-2"
                    >
                        <label
                            v-for="opcion in opcionesRequisitos"
                            :key="opcion.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="
                                    form.requisitos_previos.includes(
                                        String(opcion.id),
                                    )
                                "
                                @update:model-value="
                                    (v) => alternarRequisito(opcion.id, !!v)
                                "
                            />
                            {{ opcion.titulo }}
                        </label>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
