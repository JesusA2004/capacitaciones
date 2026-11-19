<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    CheckCircle2,
    Download,
    HelpCircle,
    Presentation,
    Sparkles,
    X,
} from '@lucide/vue';
import { computed, reactive } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { useAlertas } from '@/composables/useAlertas';
import { ETIQUETA_TIPO_PREGUNTA } from '@/lib/capacitacion';
import { dashboard } from '@/routes';
import { calificar, index } from '@/routes/calificaciones/cuestionarios';
import { descargar } from '@/routes/calificaciones/cuestionarios/respuestas';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import { aula } from '@/routes/cursos';
import type { IntentoDetalleCalificacion } from '@/types';

const TIPOS_REVISION_MANUAL = [
    'respuesta_corta',
    'respuesta_larga',
    'escala',
    'carga_archivo',
];

const props = defineProps<{
    intento: IntentoDetalleCalificacion;
    curso: { id: number; titulo: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Calificar cuestionarios', href: index.url() },
            { title: 'Intento', href: '#' },
        ],
    },
});

const { mostrarExito, mostrarError } = useAlertas();

const puntos = reactive<Record<number, number | undefined>>(
    Object.fromEntries(
        props.intento.respuestas.map((r) => [
            r.id,
            r.puntos_obtenidos ?? undefined,
        ]),
    ),
);

const nombre = computed(() =>
    `${props.intento.usuario.name} ${props.intento.usuario.apellidos ?? ''}`.trim(),
);

const manuales = computed(() =>
    props.intento.respuestas.filter((r) =>
        TIPOS_REVISION_MANUAL.includes(r.pregunta.tipo),
    ),
);
const pendientes = computed(
    () => manuales.value.filter((r) => r.es_correcta === null).length,
);

function calificarRespuesta(respuestaId: number, esCorrecta: boolean) {
    router.post(
        calificar.url(respuestaId),
        {
            es_correcta: esCorrecta,
            puntos_obtenidos: puntos[respuestaId] ?? undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito('Respuesta calificada correctamente.'),
            onError: () =>
                mostrarError('No fue posible calificar la respuesta.'),
        },
    );
}
</script>

<template>
    <Head title="Calificar intento" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="intento.cuestionario.titulo"
            :descripcion="`${nombre} · Intento ${intento.numero_intento}${curso ? ` · ${curso.titulo}` : ''}`"
            :icono="HelpCircle"
            detalle
        >
            <Button v-if="curso" variant="outline" as-child>
                <Link :href="aula(curso.id)">
                    <Presentation class="size-4" /> Aula
                </Link>
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">
                    <ArrowLeft class="size-4" /> Volver a la bandeja
                </Link>
            </Button>
        </CrudPageHeader>

        <div
            class="flex entrada-suave items-center gap-3 rounded-2xl border border-border/60 px-4 py-3 text-sm"
            :class="
                pendientes > 0 ? 'bg-warning-soft/60' : 'bg-success-soft/70'
            "
        >
            <CheckCircle2
                v-if="pendientes === 0"
                class="size-5 shrink-0 text-primary"
            />
            <HelpCircle v-else class="size-5 shrink-0 text-bronce" />
            <p>
                <template v-if="pendientes > 0">
                    Faltan
                    <strong class="tabular-nums">{{ pendientes }}</strong>
                    de {{ manuales.length }} respuesta(s) por revisar. El
                    intento queda calificado al revisar la última.
                </template>
                <template v-else>
                    Todas las respuestas abiertas ya están revisadas.
                </template>
            </p>
        </div>

        <ListaAnimada
            tag="div"
            :cantidad="intento.respuestas.length"
            class="grid grid-cols-1 gap-3 xl:grid-cols-2"
        >
            <article
                v-for="(respuesta, i) in intento.respuestas"
                :key="respuesta.id"
                class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-sm transicion-ui"
                :class="
                    TIPOS_REVISION_MANUAL.includes(respuesta.pregunta.tipo) &&
                    respuesta.es_correcta === null
                        ? 'border-oro/50'
                        : 'border-border/60'
                "
            >
                <header class="flex items-start gap-3">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-crema text-sm font-bold text-bronce tabular-nums"
                        >{{ i + 1 }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-pretty">
                            {{ respuesta.pregunta.enunciado }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                ETIQUETA_TIPO_PREGUNTA[
                                    respuesta.pregunta.tipo
                                ] ?? respuesta.pregunta.tipo
                            }}
                            · {{ respuesta.pregunta.puntos }} pto(s)
                        </p>
                    </div>
                    <Badge
                        v-if="
                            !TIPOS_REVISION_MANUAL.includes(
                                respuesta.pregunta.tipo,
                            )
                        "
                        variant="secondary"
                        class="shrink-0 bg-menta text-esmeralda"
                    >
                        <Sparkles class="size-3" />
                        Automática
                    </Badge>
                    <Badge
                        v-else-if="respuesta.es_correcta !== null"
                        variant="secondary"
                        class="shrink-0"
                        :class="
                            respuesta.es_correcta
                                ? 'bg-success-soft text-primary'
                                : 'bg-danger-soft text-destructive'
                        "
                    >
                        {{ respuesta.es_correcta ? 'Correcta' : 'Incorrecta' }}
                        · {{ respuesta.puntos_obtenidos ?? 0 }} pto(s)
                    </Badge>
                </header>

                <template
                    v-if="
                        TIPOS_REVISION_MANUAL.includes(respuesta.pregunta.tipo)
                    "
                >
                    <p
                        v-if="
                            respuesta.pregunta.tipo === 'respuesta_corta' ||
                            respuesta.pregunta.tipo === 'respuesta_larga'
                        "
                        class="rounded-xl bg-crema/40 p-3 text-sm whitespace-pre-wrap"
                    >
                        {{ respuesta.respuesta_texto || '(sin respuesta)' }}
                    </p>

                    <p
                        v-else-if="respuesta.pregunta.tipo === 'escala'"
                        class="rounded-xl bg-crema/40 p-3 text-sm"
                    >
                        Valor seleccionado:
                        <strong>{{
                            respuesta.valor_numerico ?? '(sin respuesta)'
                        }}</strong>
                    </p>

                    <div
                        v-else-if="respuesta.pregunta.tipo === 'carga_archivo'"
                    >
                        <Button
                            v-if="respuesta.recurso_multimedia_id"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <a
                                :href="descargar.url(respuesta.id)"
                                target="_blank"
                            >
                                <Download class="size-4" />
                                Descargar archivo entregado
                            </a>
                        </Button>
                        <p v-else class="text-sm text-muted-foreground">
                            (sin archivo)
                        </p>
                    </div>

                    <div
                        v-if="respuesta.es_correcta === null"
                        class="mt-auto flex flex-wrap items-center gap-2 border-t border-border/60 pt-3"
                    >
                        <Input
                            v-model.number="puntos[respuesta.id]"
                            type="number"
                            min="0"
                            :max="respuesta.pregunta.puntos"
                            :placeholder="`Puntos (máx. ${respuesta.pregunta.puntos})`"
                            class="w-44"
                            :aria-label="`Puntos de la respuesta ${i + 1}`"
                        />
                        <Button
                            size="sm"
                            @click="calificarRespuesta(respuesta.id, true)"
                        >
                            <Check class="size-4" />
                            Correcta
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="calificarRespuesta(respuesta.id, false)"
                        >
                            <X class="size-4" />
                            Incorrecta
                        </Button>
                    </div>
                </template>
            </article>
        </ListaAnimada>
    </div>
</template>
