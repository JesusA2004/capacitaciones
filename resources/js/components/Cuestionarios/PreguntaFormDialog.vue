<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { watch } from 'vue';
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
import { store, update } from '@/routes/bancos-preguntas/preguntas';
import type { BancoPreguntaItem, PreguntaItem } from '@/types';

const props = defineProps<{
    open: boolean;
    banco: BancoPreguntaItem;
    pregunta?: PreguntaItem | null;
    tipos: { value: string; etiqueta: string }[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const TIPOS_CON_OPCIONES = [
    'opcion_unica',
    'opcion_multiple',
    'verdadero_falso',
];

function opcionesIniciales() {
    if (props.pregunta) {
        return props.pregunta.opciones.map((opcion) => ({
            texto: opcion.texto,
            es_correcta: opcion.es_correcta,
        }));
    }

    return [
        { texto: '', es_correcta: false },
        { texto: '', es_correcta: false },
    ];
}

const form = useForm({
    enunciado: props.pregunta?.enunciado ?? '',
    tipo: props.pregunta?.tipo ?? 'opcion_unica',
    puntos: props.pregunta?.puntos ?? 1,
    explicacion: props.pregunta?.explicacion ?? '',
    opciones: opcionesIniciales(),
    escala_min: props.pregunta?.escala_min ?? 1,
    escala_max: props.pregunta?.escala_max ?? 5,
    escala_etiqueta_min: props.pregunta?.escala_etiqueta_min ?? '',
    escala_etiqueta_max: props.pregunta?.escala_etiqueta_max ?? '',
    extensiones_permitidas: (props.pregunta?.extensiones_permitidas ?? []).join(
        ', ',
    ),
    tamano_maximo_mb: props.pregunta?.tamano_maximo_mb ?? 10,
});

const requiereOpciones = () => TIPOS_CON_OPCIONES.includes(form.tipo);
const esSeleccionUnica = () =>
    form.tipo === 'opcion_unica' || form.tipo === 'verdadero_falso';
const esEscala = () => form.tipo === 'escala';
const esCargaArchivo = () => form.tipo === 'carga_archivo';

watch(
    () => form.tipo,
    (tipoNuevo, tipoAnterior) => {
        if (tipoNuevo === tipoAnterior) {
            return;
        }

        if (tipoNuevo === 'verdadero_falso') {
            form.opciones = [
                { texto: 'Verdadero', es_correcta: false },
                { texto: 'Falso', es_correcta: false },
            ];
        } else if (!TIPOS_CON_OPCIONES.includes(tipoNuevo)) {
            form.opciones = [];
        } else if (form.opciones.length < 2) {
            form.opciones = [
                { texto: '', es_correcta: false },
                { texto: '', es_correcta: false },
            ];
        }
    },
);

function agregarOpcion() {
    form.opciones.push({ texto: '', es_correcta: false });
}

function quitarOpcion(indice: number) {
    form.opciones.splice(indice, 1);
}

function marcarCorrecta(indice: number) {
    form.opciones = form.opciones.map((opcion, i) => ({
        ...opcion,
        es_correcta: i === indice,
    }));
}

function alternarCorrecta(indice: number, marcado: boolean) {
    form.opciones[indice].es_correcta = marcado;
}

function enviar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    form.transform((datos) => ({
        ...datos,
        extensiones_permitidas: esCargaArchivo()
            ? datos.extensiones_permitidas
                  .split(',')
                  .map((extension) => extension.trim().toLowerCase())
                  .filter(Boolean)
            : null,
        escala_min: esEscala() ? datos.escala_min : null,
        escala_max: esEscala() ? datos.escala_max : null,
        tamano_maximo_mb: esCargaArchivo() ? datos.tamano_maximo_mb : null,
    }));

    if (props.pregunta) {
        form.put(
            update.url({ banco: props.banco.id, pregunta: props.pregunta.id }),
            opciones,
        );
    } else {
        form.post(store.url(props.banco.id), opciones);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    pregunta ? 'Editar pregunta' : 'Nueva pregunta'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="enunciado">Enunciado</Label>
                    <Textarea
                        id="enunciado"
                        v-model="form.enunciado"
                        rows="3"
                        autofocus
                    />
                    <InputError :message="form.errors.enunciado" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Tipo</Label>
                        <Select v-model="form.tipo">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in tipos"
                                    :key="opcion.value"
                                    :value="opcion.value"
                                >
                                    {{ opcion.etiqueta }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.tipo" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="puntos">Puntos</Label>
                        <Input
                            id="puntos"
                            v-model.number="form.puntos"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.puntos" />
                    </div>
                </div>

                <div v-if="requiereOpciones()" class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label>Opciones</Label>
                        <Button
                            v-if="
                                form.tipo === 'opcion_multiple' ||
                                form.tipo === 'opcion_unica'
                            "
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="agregarOpcion"
                        >
                            <Plus class="size-4" />
                            Agregar opción
                        </Button>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div
                            v-for="(opcion, indice) in form.opciones"
                            :key="indice"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-if="esSeleccionUnica()"
                                type="radio"
                                :checked="opcion.es_correcta"
                                :disabled="form.tipo === 'verdadero_falso'"
                                class="size-4"
                                @change="marcarCorrecta(indice)"
                            />
                            <Checkbox
                                v-else
                                :model-value="opcion.es_correcta"
                                @update:model-value="
                                    (v) => alternarCorrecta(indice, !!v)
                                "
                            />
                            <Input
                                v-model="opcion.texto"
                                :disabled="form.tipo === 'verdadero_falso'"
                                placeholder="Texto de la opción"
                                class="flex-1"
                            />
                            <Button
                                v-if="form.tipo !== 'verdadero_falso'"
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="form.opciones.length <= 2"
                                @click="quitarOpcion(indice)"
                            >
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </div>
                    </div>
                    <InputError :message="form.errors.opciones" />
                </div>

                <div v-if="esEscala()" class="grid gap-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="escala_min">Valor mínimo</Label>
                            <Input
                                id="escala_min"
                                v-model.number="form.escala_min"
                                type="number"
                                min="0"
                                max="9"
                            />
                            <InputError :message="form.errors.escala_min" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="escala_max">Valor máximo</Label>
                            <Input
                                id="escala_max"
                                v-model.number="form.escala_max"
                                type="number"
                                min="1"
                                max="10"
                            />
                            <InputError :message="form.errors.escala_max" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="escala_etiqueta_min"
                                >Etiqueta del mínimo</Label
                            >
                            <Input
                                id="escala_etiqueta_min"
                                v-model="form.escala_etiqueta_min"
                                placeholder="Ej. Nada de acuerdo"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="escala_etiqueta_max"
                                >Etiqueta del máximo</Label
                            >
                            <Input
                                id="escala_etiqueta_max"
                                v-model="form.escala_etiqueta_max"
                                placeholder="Ej. Totalmente de acuerdo"
                            />
                        </div>
                    </div>
                </div>

                <div v-if="esCargaArchivo()" class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="extensiones_permitidas"
                            >Extensiones permitidas</Label
                        >
                        <Input
                            id="extensiones_permitidas"
                            v-model="form.extensiones_permitidas"
                            placeholder="pdf, jpg, png"
                        />
                        <InputError
                            :message="form.errors.extensiones_permitidas"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="tamano_maximo_mb">Tamaño máximo (MB)</Label>
                        <Input
                            id="tamano_maximo_mb"
                            v-model.number="form.tamano_maximo_mb"
                            type="number"
                            min="1"
                            max="2048"
                        />
                        <InputError :message="form.errors.tamano_maximo_mb" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="explicacion"
                        >Retroalimentación (opcional)</Label
                    >
                    <Textarea
                        id="explicacion"
                        v-model="form.explicacion"
                        rows="2"
                    />
                    <InputError :message="form.errors.explicacion" />
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
