<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    segundosRestantes: number | null;
    horaServidor: string;
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

type RespuestaLocal = {
    opcion_pregunta_id?: number | null;
    opciones_seleccionadas?: number[];
    respuesta_texto?: string;
    valor_numerico?: number;
    archivo?: File;
};

const respuestas = reactive<Record<number, RespuestaLocal>>({});
const nombreArchivo = reactive<Record<number, string>>({});

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

function elegirEscala(preguntaId: number, valor: number) {
    respuestas[preguntaId] = { valor_numerico: valor };
}

function elegirArchivo(preguntaId: number, evento: Event) {
    const archivo = (evento.target as HTMLInputElement).files?.[0];

    if (archivo) {
        respuestas[preguntaId] = { archivo };
        nombreArchivo[preguntaId] = archivo.name;
    }
}

function opcionesEscala(pregunta: PreguntaParaResolver) {
    const min = pregunta.escala_min ?? 1;
    const max = pregunta.escala_max ?? 5;
    const valores: number[] = [];

    for (let valor = min; valor <= max; valor++) {
        valores.push(valor);
    }

    return valores;
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

// El tiempo restante se calcula contra la hora del servidor (props.horaServidor),
// nunca contra el reloj del navegador: el backend siempre vuelve a validar el
// límite al recibir el envío, este temporizador es solo una ayuda visual.
const desfaseServidorMs = props.horaServidor
    ? new Date(props.horaServidor).getTime() - Date.now()
    : 0;
const segundosRestantes = ref(props.segundosRestantes);
let intervalo: ReturnType<typeof setInterval> | undefined;

const tiempoFormateado = computed(() => {
    if (segundosRestantes.value === null) {
        return null;
    }

    const minutos = Math.floor(segundosRestantes.value / 60);
    const segundos = segundosRestantes.value % 60;

    return `${minutos}:${String(segundos).padStart(2, '0')}`;
});

onMounted(() => {
    if (segundosRestantes.value === null) {
        return;
    }

    intervalo = setInterval(() => {
        const ahoraAjustado = Date.now() + desfaseServidorMs;
        const limite = ahoraAjustado + (segundosRestantes.value ?? 0) * 1000;
        segundosRestantes.value = Math.max(
            0,
            Math.round((limite - ahoraAjustado) / 1000) - 1,
        );

        if (segundosRestantes.value <= 0) {
            clearInterval(intervalo);
            enviarCuestionario(true);
        }
    }, 1000);
});

onBeforeUnmount(() => {
    if (intervalo) {
        clearInterval(intervalo);
    }
});

async function enviarCuestionario(porTiempoAgotado = false) {
    if (!props.intentoActivoId) {
        return;
    }

    if (!porTiempoAgotado) {
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
                mostrarExito(
                    porTiempoAgotado
                        ? 'Se agotó el tiempo; el cuestionario se envió automáticamente.'
                        : 'Cuestionario enviado correctamente.',
                ),
            onError: () =>
                mostrarError('No fue posible enviar el cuestionario.'),
        },
    );
}
</script>

<template>
    <Head :title="cuestionario.titulo" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                :title="cuestionario.titulo"
                :description="leccion.titulo"
            />

            <div
                v-if="intentoActivoId && tiempoFormateado !== null"
                class="shrink-0 rounded-md border px-3 py-2 text-center"
                :class="
                    (segundosRestantes ?? 0) <= 30
                        ? 'border-destructive text-destructive'
                        : ''
                "
            >
                <p class="text-xs text-muted-foreground">Tiempo restante</p>
                <p class="font-mono text-lg font-semibold">
                    {{ tiempoFormateado }}
                </p>
            </div>
        </div>

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
                    :variant="
                        ultimoResultado.estado === 'expirado'
                            ? 'outline'
                            : ultimoResultado.aprobado
                              ? 'default'
                              : 'outline'
                    "
                >
                    {{
                        ultimoResultado.estado === 'expirado'
                            ? 'Tiempo agotado'
                            : ultimoResultado.aprobado
                              ? 'Aprobado'
                              : 'No aprobado'
                    }}
                </Badge>
                <span
                    v-if="ultimoResultado.calificacion !== null"
                    class="text-sm text-muted-foreground"
                >
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
                <template v-if="cuestionario.tiempo_limite_minutos">
                    Tiempo límite: {{ cuestionario.tiempo_limite_minutos }}
                    minuto(s).
                </template>
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

                <div
                    v-else-if="pregunta.tipo === 'escala'"
                    class="flex flex-col gap-2"
                >
                    <div class="flex items-center gap-3">
                        <button
                            v-for="valor in opcionesEscala(pregunta)"
                            :key="valor"
                            type="button"
                            class="flex size-9 items-center justify-center rounded-full border text-sm"
                            :class="
                                respuestas[pregunta.id]?.valor_numerico ===
                                valor
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : ''
                            "
                            @click="elegirEscala(pregunta.id, valor)"
                        >
                            {{ valor }}
                        </button>
                    </div>
                    <div
                        v-if="
                            pregunta.escala_etiqueta_min ||
                            pregunta.escala_etiqueta_max
                        "
                        class="flex justify-between text-xs text-muted-foreground"
                    >
                        <span>{{ pregunta.escala_etiqueta_min }}</span>
                        <span>{{ pregunta.escala_etiqueta_max }}</span>
                    </div>
                </div>

                <div
                    v-else-if="pregunta.tipo === 'carga_archivo'"
                    class="flex flex-col gap-2"
                >
                    <Input
                        type="file"
                        @change="
                            (evento: Event) =>
                                elegirArchivo(pregunta.id, evento)
                        "
                    />
                    <p
                        v-if="nombreArchivo[pregunta.id]"
                        class="text-xs text-muted-foreground"
                    >
                        Archivo seleccionado: {{ nombreArchivo[pregunta.id] }}
                    </p>
                    <p
                        v-if="pregunta.extensiones_permitidas?.length"
                        class="text-xs text-muted-foreground"
                    >
                        Extensiones permitidas:
                        {{ pregunta.extensiones_permitidas.join(', ') }}
                        <template v-if="pregunta.tamano_maximo_mb">
                            · Máximo {{ pregunta.tamano_maximo_mb }} MB
                        </template>
                    </p>
                </div>

                <Textarea
                    v-else
                    :model-value="
                        respuestas[pregunta.id]?.respuesta_texto ?? ''
                    "
                    :rows="pregunta.tipo === 'respuesta_larga' ? 6 : 3"
                    @update:model-value="
                        (valor) => escribirTexto(pregunta.id, String(valor))
                    "
                />
            </div>

            <Button class="self-start" @click="enviarCuestionario(false)">
                Enviar cuestionario
            </Button>
        </div>
    </div>
</template>
