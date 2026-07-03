<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { calificar, index } from '@/routes/calificaciones/cuestionarios';
import { descargar } from '@/routes/calificaciones/cuestionarios/respuestas';
import type { IntentoDetalleCalificacion } from '@/types';

const TIPOS_REVISION_MANUAL = [
    'respuesta_corta',
    'respuesta_larga',
    'escala',
    'carga_archivo',
];

const props = defineProps<{
    intento: IntentoDetalleCalificacion;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
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

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="intento.cuestionario.titulo"
            :description="`${intento.usuario.name} ${intento.usuario.apellidos ?? ''} — Intento ${intento.numero_intento}`"
        />

        <div class="flex flex-col gap-4">
            <div
                v-for="respuesta in intento.respuestas"
                :key="respuesta.id"
                class="rounded-lg border p-4"
            >
                <p class="mb-2 text-sm font-medium">
                    {{ respuesta.pregunta.enunciado }}
                </p>

                <Badge
                    v-if="
                        !TIPOS_REVISION_MANUAL.includes(respuesta.pregunta.tipo)
                    "
                    variant="secondary"
                >
                    Calificada automáticamente
                </Badge>

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
                        class="mb-3 rounded-md bg-muted/50 p-2 text-sm whitespace-pre-wrap"
                    >
                        {{ respuesta.respuesta_texto || '(sin respuesta)' }}
                    </p>

                    <p
                        v-else-if="respuesta.pregunta.tipo === 'escala'"
                        class="mb-3 rounded-md bg-muted/50 p-2 text-sm"
                    >
                        Valor seleccionado:
                        {{ respuesta.valor_numerico ?? '(sin respuesta)' }}
                    </p>

                    <div
                        v-else-if="respuesta.pregunta.tipo === 'carga_archivo'"
                        class="mb-3"
                    >
                        <a
                            v-if="respuesta.recurso_multimedia_id"
                            :href="descargar.url(respuesta.id)"
                            class="text-sm text-primary underline"
                            target="_blank"
                        >
                            Descargar archivo entregado
                        </a>
                        <p v-else class="text-sm text-muted-foreground">
                            (sin archivo)
                        </p>
                    </div>

                    <div
                        v-if="respuesta.es_correcta === null"
                        class="flex items-center gap-2"
                    >
                        <Input
                            v-model.number="puntos[respuesta.id]"
                            type="number"
                            min="0"
                            :placeholder="`Puntos (máx. ${respuesta.pregunta.puntos})`"
                            class="w-40"
                        />
                        <Button
                            size="sm"
                            @click="calificarRespuesta(respuesta.id, true)"
                        >
                            Marcar correcta
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="calificarRespuesta(respuesta.id, false)"
                        >
                            Marcar incorrecta
                        </Button>
                    </div>
                    <Badge
                        v-else
                        :variant="respuesta.es_correcta ? 'default' : 'outline'"
                    >
                        {{ respuesta.es_correcta ? 'Correcta' : 'Incorrecta' }}
                        ({{ respuesta.puntos_obtenidos ?? 0 }}
                        pto(s))
                    </Badge>
                </template>
            </div>
        </div>
    </div>
</template>
