<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index } from '@/routes/mi-capacitacion';
import { enviar } from '@/routes/mi-capacitacion/intentos';
import { iniciar } from '@/routes/mi-capacitacion/lecciones/cuestionario';
import type {
    LeccionItem,
    PreguntaParaResolver,
    ResultadoIntento,
    RetroalimentacionPregunta,
} from '@/types';

const props = defineProps<{
    leccion: LeccionItem;
    cuestionario: {
        id: number;
        titulo: string;
        instrucciones: string | null;
        tiempo_limite_minutos: number | null;
    };
    preguntas: PreguntaParaResolver[];
    intentoActivoId: number | null;
    intentosRestantes: number | null;
    ultimoResultado: ResultadoIntento | null;
    retroalimentacion: RetroalimentacionPregunta[] | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi capacitación', href: index.url() },
            { title: 'Cuestionario', href: '#' },
        ],
    },
});

const {
    mostrarAdvertencia,
    mostrarExito,
    mostrarError,
    confirmarCierreIntento,
} = useAlertas();

const respuestas = reactive<
    Record<
        number,
        {
            opcion_pregunta_id?: number | null;
            opciones_seleccionadas?: number[];
            respuesta_texto?: string;
        }
    >
>({});

function elegirUnica(preguntaId: number, opcionId: number) {
    respuestas[preguntaId] = { opcion_pregunta_id: opcionId };
}

function alternarMultiple(
    preguntaId: number,
    opcionId: number,
    marcado: boolean,
) {
    const actual = respuestas[preguntaId]?.opciones_seleccionadas ?? [];
    respuestas[preguntaId] = {
        opciones_seleccionadas: marcado
            ? [...new Set([...actual, opcionId])]
            : actual.filter((id) => id !== opcionId),
    };
}

function escribirTexto(preguntaId: number, texto: string) {
    respuestas[preguntaId] = { respuesta_texto: texto };
}

function retroalimentacionDe(preguntaId: number) {
    return props.retroalimentacion?.find((r) => r.pregunta_id === preguntaId);
}

function iniciarCuestionario() {
    router.post(
        iniciar.url(props.leccion.id),
        {},
        {
            preserveScroll: true,
            onError: () =>
                mostrarError('No fue posible iniciar el cuestionario.'),
        },
    );
}

async function enviarCuestionario() {
    if (!props.intentoActivoId) {
        return;
    }

    const preguntasSinResponder = props.preguntas.filter(
        (p) => !respuestas[p.id],
    );

    if (preguntasSinResponder.length > 0) {
        mostrarAdvertencia(
            'Debes responder todas las preguntas antes de enviar.',
        );

        return;
    }

    const confirmado = await confirmarCierreIntento();

    if (!confirmado) {
        return;
    }

    router.post(
        enviar.url(props.intentoActivoId),
        {
            respuestas: props.preguntas.map((p) => ({
                pregunta_id: p.id,
                ...respuestas[p.id],
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito('Cuestionario enviado correctamente.'),
            onError: () =>
                mostrarError('No fue posible enviar el cuestionario.'),
        },
    );
}
</script>

<template>
    <Head :title="cuestionario.titulo" />

    <div class="flex flex-col gap-6 p-4">
        <Heading :title="cuestionario.titulo" :description="leccion.titulo" />

        <p
            v-if="cuestionario.instrucciones"
            class="text-sm text-muted-foreground"
        >
            {{ cuestionario.instrucciones }}
        </p>

        <div
            v-if="ultimoResultado && !intentoActivoId"
            class="rounded-lg border p-4"
        >
            <div class="flex items-center gap-2">
                <Badge
                    :variant="ultimoResultado.aprobado ? 'default' : 'outline'"
                >
                    {{ ultimoResultado.aprobado ? 'Aprobado' : 'No aprobado' }}
                </Badge>
                <span class="text-sm text-muted-foreground">
                    Calificación: {{ ultimoResultado.calificacion }}%
                </span>
            </div>

            <div v-if="retroalimentacion" class="mt-4 space-y-2">
                <div
                    v-for="pregunta in preguntas"
                    :key="pregunta.id"
                    class="rounded-md border p-3 text-sm"
                >
                    <p class="font-medium">{{ pregunta.enunciado }}</p>
                    <p
                        :class="
                            retroalimentacionDe(pregunta.id)?.es_correcta
                                ? 'text-[var(--success)]'
                                : 'text-destructive'
                        "
                    >
                        {{
                            retroalimentacionDe(pregunta.id)?.es_correcta
                                ? 'Correcta'
                                : retroalimentacionDe(pregunta.id)
                                        ?.es_correcta === false
                                  ? 'Incorrecta'
                                  : 'Pendiente de revisión'
                        }}
                    </p>
                    <p
                        v-if="retroalimentacionDe(pregunta.id)?.explicacion"
                        class="mt-1 text-muted-foreground"
                    >
                        {{ retroalimentacionDe(pregunta.id)?.explicacion }}
                    </p>
                </div>
            </div>

            <Button
                v-if="intentosRestantes === null || intentosRestantes > 0"
                class="mt-4"
                @click="iniciarCuestionario"
            >
                Intentar de nuevo
            </Button>
            <p v-else class="mt-4 text-sm text-muted-foreground">
                Ya no te quedan intentos disponibles.
            </p>
        </div>

        <div v-else-if="!intentoActivoId" class="rounded-lg border p-4">
            <p class="mb-4 text-sm text-muted-foreground">
                {{ preguntas.length }} pregunta(s).
                <template v-if="intentosRestantes !== null">
                    Te quedan {{ intentosRestantes }} intento(s).
                </template>
            </p>
            <Button
                :disabled="intentosRestantes !== null && intentosRestantes <= 0"
                @click="iniciarCuestionario"
            >
                Iniciar cuestionario
            </Button>
        </div>

        <div v-else class="flex flex-col gap-4">
            <div
                v-for="pregunta in preguntas"
                :key="pregunta.id"
                class="rounded-lg border p-4"
            >
                <p class="mb-3 text-sm font-medium">{{ pregunta.enunciado }}</p>

                <div
                    v-if="
                        pregunta.tipo === 'opcion_unica' ||
                        pregunta.tipo === 'verdadero_falso'
                    "
                    class="flex flex-col gap-2"
                >
                    <label
                        v-for="opcion in pregunta.opciones"
                        :key="opcion.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="radio"
                            :name="`pregunta-${pregunta.id}`"
                            :checked="
                                respuestas[pregunta.id]?.opcion_pregunta_id ===
                                opcion.id
                            "
                            class="size-4"
                            @change="elegirUnica(pregunta.id, opcion.id)"
                        />
                        {{ opcion.texto }}
                    </label>
                </div>

                <div
                    v-else-if="pregunta.tipo === 'opcion_multiple'"
                    class="flex flex-col gap-2"
                >
                    <label
                        v-for="opcion in pregunta.opciones"
                        :key="opcion.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            :checked="
                                (
                                    respuestas[pregunta.id]
                                        ?.opciones_seleccionadas ?? []
                                ).includes(opcion.id)
                            "
                            class="size-4"
                            @change="
                                (evento) =>
                                    alternarMultiple(
                                        pregunta.id,
                                        opcion.id,
                                        (evento.target as HTMLInputElement)
                                            .checked,
                                    )
                            "
                        />
                        {{ opcion.texto }}
                    </label>
                </div>

                <Textarea
                    v-else
                    :model-value="
                        respuestas[pregunta.id]?.respuesta_texto ?? ''
                    "
                    rows="3"
                    @update:model-value="
                        (valor) => escribirTexto(pregunta.id, String(valor))
                    "
                />
            </div>

            <Button class="self-start" @click="enviarCuestionario">
                Enviar cuestionario
            </Button>
        </div>
    </div>
</template>
