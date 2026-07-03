<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/cursos';
import type { CursoItem, CursoRequisitoItem, ResponsableOpcion } from '@/types';

const props = defineProps<{
    open: boolean;
    curso: CursoItem;
    cursosDisponibles: CursoRequisitoItem[];
    responsablesDisponibles: ResponsableOpcion[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    titulo: props.curso.titulo,
    descripcion: props.curso.descripcion ?? '',
    objetivo: props.curso.objetivo ?? '',
    duracion_estimada_minutos: props.curso.duracion_estimada_minutos ?? '',
    disponible_desde: props.curso.disponible_desde
        ? props.curso.disponible_desde.slice(0, 10)
        : '',
    disponible_hasta: props.curso.disponible_hasta
        ? props.curso.disponible_hasta.slice(0, 10)
        : '',
    calificacion_minima: props.curso.calificacion_minima ?? '',
    intentos_maximos: props.curso.intentos_maximos ?? '',
    requiere_orden: props.curso.requiere_orden,
    genera_constancia: props.curso.genera_constancia,
    alcance_global: props.curso.alcance_global,
    etiquetas_texto: (props.curso.etiquetas ?? []).join(', '),
    responsable_id: props.curso.responsable_id
        ? String(props.curso.responsable_id)
        : '',
    requisitos_previos: (props.curso.requisitosPrevios ?? []).map((r) =>
        String(r.id),
    ),
});

const opcionesRequisitos = computed(() =>
    props.cursosDisponibles.filter((c) => c.id !== props.curso.id),
);

function alternarRequisito(id: number, marcado: boolean) {
    const valor = String(id);
    form.requisitos_previos = marcado
        ? [...new Set([...form.requisitos_previos, valor])]
        : form.requisitos_previos.filter((r) => r !== valor);
}

function enviar() {
    form.transform((datos) => ({
        ...datos,
        etiquetas: datos.etiquetas_texto
            .split(',')
            .map((etiqueta) => etiqueta.trim())
            .filter(Boolean),
        responsable_id: datos.responsable_id || null,
    })).put(update.url(props.curso.id), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Sheet :open="open" @update:open="(valor) => emit('update:open', valor)">
        <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
            <SheetHeader>
                <SheetTitle>Editar detalles del curso</SheetTitle>
            </SheetHeader>

            <form class="grid gap-4 px-4 pb-6" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="titulo">Título</Label>
                    <Input id="titulo" v-model="form.titulo" />
                    <InputError :message="form.errors.titulo" />
                </div>

                <div class="grid gap-2">
                    <Label for="objetivo">Objetivo</Label>
                    <Textarea id="objetivo" v-model="form.objetivo" rows="2" />
                </div>

                <div class="grid gap-2">
                    <Label for="descripcion">Descripción</Label>
                    <Textarea
                        id="descripcion"
                        v-model="form.descripcion"
                        rows="3"
                    />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="disponible_desde">Disponible desde</Label>
                        <Input
                            id="disponible_desde"
                            v-model="form.disponible_desde"
                            type="date"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="disponible_hasta">Disponible hasta</Label>
                        <Input
                            id="disponible_hasta"
                            v-model="form.disponible_hasta"
                            type="date"
                        />
                        <InputError :message="form.errors.disponible_hasta" />
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="grid gap-2">
                        <Label for="duracion">Duración (min)</Label>
                        <Input
                            id="duracion"
                            v-model.number="form.duracion_estimada_minutos"
                            type="number"
                            min="1"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="calificacion_minima"
                            >Calificación mínima</Label
                        >
                        <Input
                            id="calificacion_minima"
                            v-model.number="form.calificacion_minima"
                            type="number"
                            min="0"
                            max="100"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="intentos_maximos">Intentos máximos</Label>
                        <Input
                            id="intentos_maximos"
                            v-model.number="form.intentos_maximos"
                            type="number"
                            min="1"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="etiquetas"
                        >Etiquetas (separadas por coma)</Label
                    >
                    <Input
                        id="etiquetas"
                        v-model="form.etiquetas_texto"
                        placeholder="inducción, obligatorio"
                    />
                </div>

                <div class="grid gap-2">
                    <Label>Responsable</Label>
                    <Select v-model="form.responsable_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Sin asignar" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in responsablesDisponibles"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                            >
                                {{ opcion.name }} {{ opcion.apellidos }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="opcionesRequisitos.length" class="grid gap-2">
                    <Label>Cursos requeridos antes de este</Label>
                    <div
                        class="grid max-h-32 gap-2 overflow-y-auto rounded-md border p-2"
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

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.requiere_orden"
                        @update:model-value="(v) => (form.requiere_orden = !!v)"
                    />
                    Requiere completar lecciones en orden
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.genera_constancia"
                        @update:model-value="
                            (v) => (form.genera_constancia = !!v)
                        "
                    />
                    Genera constancia al completarse
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.alcance_global"
                        @update:model-value="(v) => (form.alcance_global = !!v)"
                    />
                    Alcance global (visible para asignación general)
                </label>

                <SheetFooter class="px-0">
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
