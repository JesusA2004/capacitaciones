<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronDown,
    ChevronUp,
    HelpCircle,
    ListChecks,
    Plus,
    Presentation,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { ETIQUETA_TIPO_PREGUNTA as ETIQUETA_TIPO } from '@/lib/capacitacion';
import { dashboard } from '@/routes';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import { update as actualizarCuestionario } from '@/routes/cuestionarios';
import { actualizar as actualizarPreguntas } from '@/routes/cuestionarios/preguntas';
import { aula, edit, index as indexCursos } from '@/routes/cursos';
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
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Cursos', href: indexCursos() },
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
    tolerancia_segundos: props.cuestionario?.tolerancia_segundos ?? 30,
    aleatorizar_preguntas: props.cuestionario?.aleatorizar_preguntas ?? false,
    aleatorizar_opciones: props.cuestionario?.aleatorizar_opciones ?? false,
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

const totalPuntos = computed(() =>
    seleccionadas.value.reduce((suma, p) => suma + (Number(p.puntos) || 0), 0),
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

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="cuestionario ? cuestionario.titulo : 'Nuevo cuestionario'"
            :descripcion="`${curso.titulo} · Lección «${leccion.titulo}»`"
            :icono="HelpCircle"
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

        <div
            class="grid items-start gap-5 xl:grid-cols-[minmax(0,30rem)_minmax(0,1fr)]"
        >
            <section
                class="flex entrada-suave flex-col rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
            >
                <h2 class="mb-4 font-semibold">Configuración</h2>

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
                            <Label for="intentos_maximos"
                                >Intentos máximos</Label
                            >
                            <Input
                                id="intentos_maximos"
                                v-model.number="form.intentos_maximos"
                                type="number"
                                min="1"
                                placeholder="Sin límite"
                            />
                            <InputError
                                :message="form.errors.intentos_maximos"
                            />
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

                    <div v-if="form.tiempo_limite_minutos" class="grid gap-2">
                        <Label for="tolerancia_segundos">
                            Tolerancia por latencia al enviar (segundos)
                        </Label>
                        <Input
                            id="tolerancia_segundos"
                            v-model.number="form.tolerancia_segundos"
                            type="number"
                            min="0"
                            max="300"
                            class="w-40"
                        />
                        <InputError
                            :message="form.errors.tolerancia_segundos"
                        />
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
                            :model-value="form.aleatorizar_opciones"
                            @update:model-value="
                                (v) => (form.aleatorizar_opciones = !!v)
                            "
                        />
                        Aleatorizar el orden de las opciones
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
            </section>

            <section
                v-if="cuestionario"
                class="flex entrada-suave flex-col rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
            >
                <div
                    class="mb-4 flex flex-wrap items-center justify-between gap-2"
                >
                    <h2 class="font-semibold">Preguntas</h2>
                    <p class="text-sm text-muted-foreground tabular-nums">
                        {{ seleccionadas.length }} pregunta(s) ·
                        {{ totalPuntos }} punto(s)
                    </p>
                </div>

                <ListaAnimada
                    tag="div"
                    :cantidad="seleccionadas.length"
                    class="flex flex-col gap-2"
                >
                    <div
                        v-for="(pregunta, indice) in seleccionadas"
                        :key="pregunta.pregunta_id"
                        class="flex items-center gap-3 rounded-xl border border-border/60 bg-card px-3 py-2 transicion-ui hover:border-oro/40"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-crema text-xs font-bold text-bronce tabular-nums"
                            aria-hidden="true"
                            >{{ indice + 1 }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ pregunta.enunciado }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    ETIQUETA_TIPO[pregunta.tipo] ??
                                    pregunta.tipo
                                }}
                            </p>
                        </div>
                        <label
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            Puntos
                            <Input
                                v-model.number="pregunta.puntos"
                                type="number"
                                min="1"
                                class="w-20"
                                :aria-label="`Puntos de la pregunta ${indice + 1}`"
                            />
                        </label>
                        <Button
                            variant="ghost"
                            size="icon"
                            :disabled="indice === 0"
                            aria-label="Subir pregunta"
                            @click="mover(indice, 'arriba')"
                        >
                            <ChevronUp class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            :disabled="indice === seleccionadas.length - 1"
                            aria-label="Bajar pregunta"
                            @click="mover(indice, 'abajo')"
                        >
                            <ChevronDown class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Quitar pregunta"
                            @click="quitarPregunta(indice)"
                        >
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </div>
                </ListaAnimada>

                <EmptyState
                    v-if="seleccionadas.length === 0"
                    :icono="ListChecks"
                    titulo="Sin preguntas todavía"
                    descripcion="Elige un banco y agrega sus preguntas; puedes reordenarlas y cambiar sus puntos."
                    class="py-6"
                />

                <div
                    class="mt-4 flex flex-wrap items-end gap-2 rounded-xl border border-dashed border-border bg-crema/30 p-3"
                >
                    <div class="grid gap-2">
                        <Label>Banco</Label>
                        <SelectSimple
                            v-model="bancoElegido"
                            :opciones="
                                bancos.map((b) => ({
                                    value: String(b.id),
                                    label: b.nombre,
                                }))
                            "
                            placeholder="Selecciona un banco"
                            placeholder-busqueda="Buscar banco…"
                            class="w-56"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label>Pregunta</Label>
                        <SelectSimple
                            v-model="preguntaElegida"
                            :opciones="
                                preguntasDelBanco.map((p) => ({
                                    value: String(p.id),
                                    label: p.enunciado,
                                }))
                            "
                            placeholder="Selecciona una pregunta"
                            placeholder-busqueda="Buscar pregunta…"
                            class="w-72"
                        />
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
            </section>

            <section
                v-else
                class="rounded-2xl border border-dashed border-border bg-card"
            >
                <EmptyState
                    :icono="ListChecks"
                    titulo="Primero guarda la configuración"
                    descripcion="Al guardar el cuestionario podrás elegir sus preguntas desde el banco de preguntas."
                />
            </section>
        </div>
    </div>
</template>
