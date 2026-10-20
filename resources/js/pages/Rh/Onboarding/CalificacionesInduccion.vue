<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarClock,
    CircleCheck,
    ClipboardCheck,
    Eye,
    FileText,
    MessageSquareText,
    Search,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';
import { retroalimentar as retroalimentarLeccion } from '@/routes/rh/onboarding';
import {
    mostrar as mostrarIntento,
    retroalimentar as retroalimentarInduccion,
    vistaPrevia as vistaPreviaEvaluacion,
} from '@/routes/rh/onboarding/induccion';
import * as pdf from '@/routes/rh/onboarding/induccion/pdf';
import type { EtapaSeguimientoInduccion, SeguimientoInduccion } from '@/types';

/**
 * «Calificaciones y refuerzo» (3 · Onboarding): resultado de cada persona
 * en la evaluación de inducción FO-RH-EI y en sus lecciones; con menos del
 * mínimo, RH da retroalimentación y se habilita la reevaluación (máx. 5 días
 * hábiles). Las reglas viven en EvaluacionInduccionService /
 * OnboardingService; el listado y su alcance, en
 * SesionInduccionService::calificacionesRh().
 */
const props = defineProps<{ filas: SeguimientoInduccion[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Calificaciones y refuerzo', href: '' },
        ],
    },
});

const filtros: { valor: EtapaSeguimientoInduccion | 'todas'; texto: string }[] =
    [
        { valor: 'todas', texto: 'Todas' },
        { valor: 'por_calificar', texto: 'Por calificar' },
        { valor: 'reforzamiento', texto: 'Reforzamiento' },
        {
            valor: 'reevaluacion_por_contestar',
            texto: 'Reevaluación pendiente',
        },
        { valor: 'aprobada', texto: 'Aprobadas' },
        { valor: 'por_contestar', texto: 'Por contestar' },
        { valor: 'pendiente_presentar', texto: 'Sin presentar' },
    ];

const filtro = ref<EtapaSeguimientoInduccion | 'todas'>('todas');
const busqueda = ref('');

function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const visibles = computed(() => {
    const termino = normalizar(busqueda.value.trim());

    return props.filas.filter(
        (f) =>
            (filtro.value === 'todas' || f.etapa === filtro.value) &&
            (termino === '' ||
                normalizar(
                    [
                        f.colaborador.nombre,
                        f.colaborador.numero_empleado,
                        f.colaborador.puesto,
                        f.colaborador.sucursal,
                    ]
                        .filter(Boolean)
                        .join(' '),
                ).includes(termino)),
    );
});

function conteo(valor: EtapaSeguimientoInduccion | 'todas'): number {
    return valor === 'todas'
        ? props.filas.length
        : props.filas.filter((f) => f.etapa === valor).length;
}

const tonos: Record<EtapaSeguimientoInduccion, string> = {
    pendiente_presentar: 'bg-crema',
    por_contestar: 'bg-salvia',
    reevaluacion_por_contestar: 'bg-salvia',
    por_calificar: 'bg-warning-soft',
    reforzamiento: 'bg-danger-soft',
    aprobada: 'bg-success-soft',
};

// Retroalimentación (evaluación FO-RH-EI o lección) — una a la vez.
const retro = ref<{ clave: string; texto: string } | null>(null);
const enviando = ref(false);
const errores = ref<Record<string, string>>({});

function abrirRetro(clave: string) {
    errores.value = {};
    retro.value = { clave, texto: '' };
}

function enviarRetro(url: string) {
    if (!retro.value) {
        return;
    }

    enviando.value = true;
    router.post(
        url,
        { retroalimentacion: retro.value.texto },
        {
            preserveScroll: true,
            onSuccess: () => (retro.value = null),
            onError: (e) => (errores.value = e),
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <Head title="Calificaciones y refuerzo" />

    <div class="pagina-ancha flex flex-col gap-5 pb-10">
        <CrudPageHeader
            titulo="Calificaciones y refuerzo"
            descripcion="Resultados de la evaluación de inducción (FO-RH-EI, mínimo 24/30) y de las lecciones de cada colaborador. Con menos del mínimo, da retroalimentación y se habilita la reevaluación (máximo 5 días hábiles)."
            :icono="ClipboardCheck"
        >
            <Button as-child variant="outline">
                <Link :href="vistaPreviaEvaluacion()"
                    ><Eye class="size-4" /> Ver evaluación como
                    colaborador</Link
                >
            </Button>
        </CrudPageHeader>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="f in filtros"
                    :key="f.valor"
                    type="button"
                    class="tarjeta-interactiva rounded-full border px-3 py-1 text-xs font-medium"
                    :class="
                        filtro === f.valor
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'bg-card'
                    "
                    @click="filtro = f.valor"
                >
                    {{ f.texto }} · {{ conteo(f.valor) }}
                </button>
            </div>
            <div class="relative lg:ml-auto lg:w-72">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="busqueda"
                    class="pl-9"
                    placeholder="Buscar colaborador, puesto o sucursal"
                />
            </div>
        </div>

        <p
            v-if="visibles.length === 0"
            class="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            No hay colaboradores con este filtro dentro de tu alcance.
        </p>

        <ul class="flex flex-col gap-3">
            <li
                v-for="fila in visibles"
                :key="fila.colaborador.id"
                class="flex flex-col gap-3 rounded-2xl border bg-card p-4"
            >
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="cicloColaborador.url(fila.colaborador.id)"
                            class="text-sm font-medium hover:underline"
                            >{{ fila.colaborador.nombre }}</Link
                        >
                        <p class="text-xs text-muted-foreground">
                            {{
                                [
                                    fila.colaborador.numero_empleado,
                                    fila.colaborador.puesto,
                                    fila.colaborador.sucursal,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                    </div>
                    <span
                        class="w-fit rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="tonos[fila.etapa]"
                    >
                        {{ fila.etapa_etiqueta }}
                        <template v-if="fila.calificacion !== null">
                            · {{ fila.calificacion }}/30</template
                        >
                    </span>
                    <span
                        v-if="fila.laborando"
                        class="flex w-fit items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-medium"
                        ><CircleCheck class="size-3.5" /> Laborando</span
                    >
                </div>

                <dl
                    class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4"
                >
                    <div>
                        <dt class="text-muted-foreground">Intentos</dt>
                        <dd>{{ fila.intentos_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Mejor calificación
                        </dt>
                        <dd>
                            {{
                                fila.mejor_calificacion !== null
                                    ? `${fila.mejor_calificacion}/30`
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Límite de reevaluación
                        </dt>
                        <dd class="flex items-center gap-1">
                            <CalendarClock
                                v-if="fila.fecha_limite_reevaluacion"
                                class="size-3.5"
                            />
                            {{
                                fila.fecha_limite_reevaluacion
                                    ? formatearFecha(
                                          fila.fecha_limite_reevaluacion,
                                      )
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Retroalimentada</dt>
                        <dd>
                            {{
                                fila.retroalimentado_en
                                    ? formatearFecha(fila.retroalimentado_en)
                                    : '—'
                            }}
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="fila.retroalimentacion"
                    class="flex items-start gap-2 rounded-xl border bg-crema px-3 py-2 text-sm"
                >
                    <MessageSquareText
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <span>«{{ fila.retroalimentacion }}»</span>
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="fila.intento_por_calificar_id"
                        as-child
                        size="sm"
                    >
                        <Link
                            :href="
                                mostrarIntento(fila.intento_por_calificar_id)
                            "
                            ><ClipboardCheck class="size-4" /> Revisar y
                            calificar</Link
                        >
                    </Button>
                    <Button
                        v-else-if="fila.ultimo_intento_id"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <Link :href="mostrarIntento(fila.ultimo_intento_id)"
                            ><Eye class="size-4" /> Ver evaluación</Link
                        >
                    </Button>
                    <Button
                        v-if="fila.ultimo_intento_id"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <a
                            :href="pdf.vista.url(fila.ultimo_intento_id)"
                            target="_blank"
                            rel="noopener"
                            ><FileText class="size-4" /> PDF del formato</a
                        >
                    </Button>
                    <Button
                        v-if="
                            fila.etapa === 'reforzamiento' && fila.evaluacion_id
                        "
                        size="sm"
                        variant="outline"
                        @click="abrirRetro(`ev-${fila.evaluacion_id}`)"
                    >
                        <MessageSquareText class="size-4" /> Retroalimentar y
                        habilitar reevaluación
                    </Button>
                </div>

                <form
                    v-if="
                        fila.evaluacion_id &&
                        retro?.clave === `ev-${fila.evaluacion_id}`
                    "
                    class="flex flex-col gap-2"
                    @submit.prevent="
                        enviarRetro(
                            retroalimentarInduccion.url(fila.evaluacion_id),
                        )
                    "
                >
                    <Textarea
                        v-model="retro.texto"
                        rows="3"
                        placeholder="Qué debe reforzar antes de volver a presentar…"
                    />
                    <p
                        v-if="errores.retroalimentacion"
                        class="text-sm text-destructive"
                    >
                        {{ errores.retroalimentacion }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="enviando || !retro.texto.trim()"
                            >Enviar retroalimentación</Button
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="retro = null"
                            >Cancelar</Button
                        >
                    </div>
                </form>

                <div v-if="fila.modulos.length" class="border-t pt-3">
                    <p class="mb-2 text-xs font-semibold text-muted-foreground">
                        Lecciones
                    </p>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="m in fila.modulos"
                            :key="m.avance_id"
                            class="flex flex-col gap-2 text-sm"
                        >
                            <div
                                class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3"
                            >
                                <span class="min-w-0 flex-1">{{
                                    m.titulo
                                }}</span>
                                <span
                                    class="w-fit rounded-full px-2.5 py-0.5 text-xs"
                                    :class="
                                        m.requiere_refuerzo
                                            ? 'bg-danger-soft'
                                            : m.estado === 'aprobado'
                                              ? 'bg-success-soft'
                                              : 'bg-crema'
                                    "
                                >
                                    {{ m.estado_etiqueta }}
                                    <template v-if="m.calificacion !== null">
                                        · {{ m.calificacion }} (mín.
                                        {{ m.calificacion_minima }})</template
                                    >
                                </span>
                                <Button
                                    v-if="m.requiere_refuerzo"
                                    size="sm"
                                    variant="ghost"
                                    @click="abrirRetro(`av-${m.avance_id}`)"
                                >
                                    <MessageSquareText class="size-4" />
                                    Retroalimentar
                                </Button>
                            </div>
                            <form
                                v-if="retro?.clave === `av-${m.avance_id}`"
                                class="flex flex-col gap-2"
                                @submit.prevent="
                                    enviarRetro(
                                        retroalimentarLeccion.url(m.avance_id),
                                    )
                                "
                            >
                                <Textarea
                                    v-model="retro.texto"
                                    rows="2"
                                    placeholder="Qué debe reforzar de esta lección…"
                                />
                                <p
                                    v-if="errores.retroalimentacion"
                                    class="text-sm text-destructive"
                                >
                                    {{ errores.retroalimentacion }}
                                </p>
                                <div class="flex gap-2">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="
                                            enviando || !retro.texto.trim()
                                        "
                                        >Enviar retroalimentación</Button
                                    >
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="retro = null"
                                        >Cancelar</Button
                                    >
                                </div>
                            </form>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>
    </div>
</template>
