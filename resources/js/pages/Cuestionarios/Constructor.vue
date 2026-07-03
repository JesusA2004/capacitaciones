<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { update as actualizarCuestionario } from '@/routes/cuestionarios';
import { actualizar as actualizarPreguntas } from '@/routes/cuestionarios/preguntas';
import { store } from '@/routes/cursos/lecciones/cuestionario';
import type {
    BancoPreguntaItem,
    CuestionarioItem,
    CursoItem,
    LeccionItem,
} from '@/types';

const props = defineProps<{
    curso: CursoItem;
    leccion: LeccionItem;
    cuestionario: CuestionarioItem | null;
    bancos: BancoPreguntaItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cursos', href: '/cursos' },
            { title: 'Constructor de cuestionario', href: '#' },
        ],
    },
});

const { mostrarExito, mostrarError } = useAlertas();

const form = useForm({
    titulo: props.cuestionario?.titulo ?? props.leccion.titulo,
    instrucciones: props.cuestionario?.instrucciones ?? '',
    calificacion_minima: props.cuestionario?.calificacion_minima ?? 80,
    intentos_maximos: props.cuestionario?.intentos_maximos ?? '',
    tiempo_limite_minutos: props.cuestionario?.tiempo_limite_minutos ?? '',
    aleatorizar_preguntas: props.cuestionario?.aleatorizar_preguntas ?? false,
    mostrar_retroalimentacion:
        props.cuestionario?.mostrar_retroalimentacion ?? true,
});

function guardarConfiguracion() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () =>
            mostrarExito('La configuración se guardó correctamente.'),
    };

    if (props.cuestionario) {
        form.put(actualizarCuestionario.url(props.cuestionario.id), opciones);
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

type PreguntaSeleccionada = {
    pregunta_id: number;
    enunciado: string;
    tipo: string;
    puntos: number;
};

const seleccionadas = ref<PreguntaSeleccionada[]>(
    (props.cuestionario?.preguntas ?? [])
        .slice()
        .sort((a, b) => (a.pivot?.orden ?? 0) - (b.pivot?.orden ?? 0))
        .map((pregunta) => ({
            pregunta_id: pregunta.id,
            enunciado: pregunta.enunciado,
            tipo: pregunta.tipo,
            puntos: pregunta.pivot?.puntos ?? pregunta.puntos,
        })),
);

const bancoElegido = ref<string>('');
const preguntaElegida = ref<string>('');

const preguntasDelBanco = computed(() => {
    const banco = props.bancos.find((b) => String(b.id) === bancoElegido.value);
    const idsYaElegidos = new Set(
        seleccionadas.value.map((p) => p.pregunta_id),
    );

    return (banco?.preguntas ?? []).filter((p) => !idsYaElegidos.has(p.id));
});

function agregarPregunta() {
    const banco = props.bancos.find((b) => String(b.id) === bancoElegido.value);
    const pregunta = banco?.preguntas?.find(
        (p) => String(p.id) === preguntaElegida.value,
    );

    if (!pregunta) {
        return;
    }

    seleccionadas.value.push({
        pregunta_id: pregunta.id,
        enunciado: pregunta.enunciado,
        tipo: pregunta.tipo,
        puntos: pregunta.puntos,
    });
    preguntaElegida.value = '';
}

function quitarPregunta(indice: number) {
    seleccionadas.value.splice(indice, 1);
}

function mover(indice: number, direccion: 'arriba' | 'abajo') {
    const destino = direccion === 'arriba' ? indice - 1 : indice + 1;

    if (destino < 0 || destino >= seleccionadas.value.length) {
        return;
    }

    const lista = seleccionadas.value;
    [lista[indice], lista[destino]] = [lista[destino], lista[indice]];
}

const guardandoPreguntas = ref(false);

function guardarPreguntas() {
    if (!props.cuestionario) {
        return;
    }

    guardandoPreguntas.value = true;

    router.put(
        actualizarPreguntas.url(props.cuestionario.id),
        {
            preguntas: seleccionadas.value.map((p) => ({
                pregunta_id: p.pregunta_id,
                puntos: p.puntos,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito('Las preguntas se guardaron correctamente.'),
            onError: () =>
                mostrarError('No fue posible guardar las preguntas.'),
            onFinish: () => (guardandoPreguntas.value = false),
        },
    );
}
</script>

<template>
    <Head title="Constructor de cuestionario" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Constructor de cuestionario"
            :description="`Lección: ${leccion.titulo}`"
        />

        <div class="rounded-lg border p-4">
            <h2 class="mb-4 text-sm font-semibold">Configuración</h2>

            <form class="grid gap-4" @submit.prevent="guardarConfiguracion">
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
                        rows="3"
                    />
                    <InputError :message="form.errors.instrucciones" />
                </div>

                <div class="grid grid-cols-3 gap-4">
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
                        <Label for="intentos_maximos">Intentos máximos</Label>
                        <Input
                            id="intentos_maximos"
                            v-model.number="form.intentos_maximos"
                            type="number"
                            min="1"
                            placeholder="Sin límite"
                        />
                        <InputError :message="form.errors.intentos_maximos" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="tiempo_limite_minutos"
                            >Tiempo límite (min)</Label
                        >
                        <Input
                            id="tiempo_limite_minutos"
                            v-model.number="form.tiempo_limite_minutos"
                            type="number"
                            min="1"
                            placeholder="Sin límite"
                        />
                        <InputError
                            :message="form.errors.tiempo_limite_minutos"
                        />
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.aleatorizar_preguntas"
                        @update:model-value="
                            (v) => (form.aleatorizar_preguntas = !!v)
                        "
                    />
                    Aleatorizar el orden de las preguntas
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.mostrar_retroalimentacion"
                        @update:model-value="
                            (v) => (form.mostrar_retroalimentacion = !!v)
                        "
                    />
                    Mostrar retroalimentación al finalizar el intento
                </label>

                <Button
                    type="submit"
                    class="self-start"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Guardar configuración
                </Button>
            </form>
        </div>

        <div v-if="cuestionario" class="rounded-lg border p-4">
            <h2 class="mb-4 text-sm font-semibold">Preguntas</h2>

            <div class="flex flex-col gap-2">
                <div
                    v-for="(pregunta, indice) in seleccionadas"
                    :key="pregunta.pregunta_id"
                    class="flex items-center gap-2 rounded-md border p-2"
                >
                    <span class="flex-1 truncate text-sm">{{
                        pregunta.enunciado
                    }}</span>
                    <Input
                        v-model.number="pregunta.puntos"
                        type="number"
                        min="1"
                        class="w-20"
                    />
                    <Button
                        variant="ghost"
                        size="icon"
                        :disabled="indice === 0"
                        @click="mover(indice, 'arriba')"
                    >
                        <ChevronUp class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :disabled="indice === seleccionadas.length - 1"
                        @click="mover(indice, 'abajo')"
                    >
                        <ChevronDown class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        @click="quitarPregunta(indice)"
                    >
                        <Trash2 class="size-4 text-destructive" />
                    </Button>
                </div>

                <p
                    v-if="seleccionadas.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Todavía no se ha agregado ninguna pregunta.
                </p>
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-2">
                <div class="grid gap-2">
                    <Label>Banco</Label>
                    <Select v-model="bancoElegido">
                        <SelectTrigger class="w-56">
                            <SelectValue placeholder="Selecciona un banco" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="banco in bancos"
                                :key="banco.id"
                                :value="String(banco.id)"
                            >
                                {{ banco.nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Pregunta</Label>
                    <Select v-model="preguntaElegida">
                        <SelectTrigger class="w-72">
                            <SelectValue
                                placeholder="Selecciona una pregunta"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="pregunta in preguntasDelBanco"
                                :key="pregunta.id"
                                :value="String(pregunta.id)"
                            >
                                {{ pregunta.enunciado }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    :disabled="!preguntaElegida"
                    @click="agregarPregunta"
                >
                    <Plus class="size-4" />
                    Agregar
                </Button>
            </div>

            <Button
                class="mt-4"
                :disabled="guardandoPreguntas"
                @click="guardarPreguntas"
            >
                <Spinner v-if="guardandoPreguntas" />
                Guardar preguntas
            </Button>
        </div>

        <p v-else class="text-sm text-muted-foreground">
            Guarda la configuración para poder agregar preguntas.
        </p>
    </div>
</template>
