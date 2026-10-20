<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BookOpen,
    ChevronDown,
    CircleCheck,
    ClipboardCheck,
    Clock,
    Eye,
    FileText,
    Hourglass,
    ListFilter,
    MessageSquareText,
    RotateCcw,
    Search,
    Send,
    UserX,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import type { Tono } from '@/lib/tonos';
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

/** Color + ícono de cada etapa (chips y tarjetas del resumen). */
const ETAPAS: Record<
    EtapaSeguimientoInduccion,
    { tono: Tono; icono: Component }
> = {
    por_calificar: { tono: 'alerta', icono: ClipboardCheck },
    reforzamiento: { tono: 'peligro', icono: MessageSquareText },
    reevaluacion_por_contestar: { tono: 'info', icono: RotateCcw },
    aprobada: { tono: 'exito', icono: CircleCheck },
    por_contestar: { tono: 'primario', icono: Hourglass },
    pendiente_presentar: { tono: 'neutro', icono: UserX },
};

const filtros: {
    valor: EtapaSeguimientoInduccion | 'todas';
    texto: string;
    tono: Tono;
    icono: Component;
}[] = [
    { valor: 'todas', texto: 'Todas', tono: 'primario', icono: ListFilter },
    { valor: 'por_calificar', texto: 'Por calificar', ...ETAPAS.por_calificar },
    { valor: 'reforzamiento', texto: 'Reforzamiento', ...ETAPAS.reforzamiento },
    {
        valor: 'reevaluacion_por_contestar',
        texto: 'Reevaluación pendiente',
        ...ETAPAS.reevaluacion_por_contestar,
    },
    { valor: 'aprobada', texto: 'Aprobadas', ...ETAPAS.aprobada },
    { valor: 'por_contestar', texto: 'Por contestar', ...ETAPAS.por_contestar },
    {
        valor: 'pendiente_presentar',
        texto: 'Sin presentar',
        tono: 'neutro',
        icono: Clock,
    },
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

/** Mínimo aprobatorio del FO-RH-EI (de 30). */
const MINIMO = 24;

function puntaje(fila: SeguimientoInduccion): number | null {
    return fila.calificacion ?? fila.mejor_calificacion;
}

function refuerzos(fila: SeguimientoInduccion): number {
    return fila.modulos.filter((m) => m.requiere_refuerzo).length;
}

// PDF del formato en el visor embebido (no en otra pestaña).
const formato = ref<{ url: string; nombre: string } | null>(null);

function verFormato(fila: SeguimientoInduccion) {
    if (!fila.ultimo_intento_id) {
        return;
    }

    formato.value = {
        url: pdf.vista.url(fila.ultimo_intento_id),
        nombre: `Evaluación de inducción · ${fila.colaborador.nombre}.pdf`,
    };
}

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
            :icono="ClipboardCheck"
        >
            <Button as-child variant="outline" class="w-full sm:w-auto">
                <Link :href="vistaPreviaEvaluacion()"
                    ><Eye class="size-4" /> Ver como colaborador</Link
                >
            </Button>
        </CrudPageHeader>

        <section aria-label="Resumen por etapa" class="flex flex-col gap-2">
            <div class="flex items-center gap-1.5">
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Evaluación de inducción por etapa
                </h2>
                <AyudaBoton
                    titulo="Calificaciones y refuerzo"
                    texto="Resultados de la evaluación de inducción (FO-RH-EI, mínimo 24/30) y de las lecciones. Con menos del mínimo, da retroalimentación y se habilita la reevaluación (máximo 5 días hábiles). Toca una tarjeta para filtrar."
                />
            </div>
            <div
                class="grid grid-cols-2 gap-2.5 min-[1700px]:grid-cols-7 sm:grid-cols-4 sm:gap-3"
            >
                <TarjetaMetrica
                    v-for="f in filtros"
                    :key="f.valor"
                    :etiqueta="f.texto"
                    :valor="conteo(f.valor)"
                    :icono="f.icono"
                    :tono="f.tono"
                    clicable
                    :activa="filtro === f.valor"
                    @click="filtro = filtro === f.valor ? 'todas' : f.valor"
                />
            </div>
        </section>

        <div class="relative sm:max-w-sm">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="busqueda"
                class="h-10 pl-9"
                placeholder="Buscar colaborador, puesto o sucursal"
            />
        </div>

        <EmptyState
            v-if="visibles.length === 0"
            :icono="ClipboardCheck"
            titulo="Sin colaboradores"
            descripcion="No hay colaboradores con este filtro dentro de tu alcance."
        />

        <ul
            v-else
            class="rejilla-auto"
            style="--rejilla-min: 22rem"
            aria-label="Calificaciones por colaborador"
        >
            <li
                v-for="fila in visibles"
                :key="fila.colaborador.id"
                class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
            >
                <div class="flex items-start gap-3 p-4">
                    <ColaboradorAvatar :nombre="fila.colaborador.nombre" />
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="cicloColaborador.url(fila.colaborador.id)"
                            class="block truncate text-base font-semibold hover:underline"
                            >{{ fila.colaborador.nombre }}</Link
                        >
                        <p class="truncate text-sm text-muted-foreground">
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
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <ChipEstado
                                :etiqueta="fila.etapa_etiqueta"
                                :tono="ETAPAS[fila.etapa].tono"
                                :icono="ETAPAS[fila.etapa].icono"
                            />
                            <ChipEstado
                                v-if="fila.laborando"
                                etiqueta="Laborando"
                                tono="exito"
                                :icono="CircleCheck"
                            />
                        </div>
                    </div>
                    <div
                        class="flex size-16 shrink-0 flex-col items-center justify-center rounded-2xl"
                        :class="
                            puntaje(fila) === null
                                ? 'bg-muted text-muted-foreground'
                                : (puntaje(fila) ?? 0) >= MINIMO
                                  ? 'bg-success-soft text-success'
                                  : 'bg-danger-soft text-destructive'
                        "
                        :title="
                            fila.calificacion !== null
                                ? 'Calificación actual'
                                : 'Mejor calificación'
                        "
                    >
                        <span
                            class="text-2xl leading-none font-bold tabular-nums"
                            >{{ puntaje(fila) ?? '—' }}</span
                        >
                        <span class="text-[11px] font-medium">de 30</span>
                    </div>
                </div>

                <dl class="grid grid-cols-3 gap-2 px-4 pb-4 text-center">
                    <div class="rounded-xl bg-[var(--mrl-fondo)] p-2">
                        <dd class="text-lg font-bold tabular-nums">
                            {{ fila.intentos_count }}
                        </dd>
                        <dt class="text-[11px] text-muted-foreground">
                            Intentos
                        </dt>
                    </div>
                    <div class="rounded-xl bg-[var(--mrl-fondo)] p-2">
                        <dd class="text-sm font-semibold">
                            {{
                                fila.fecha_limite_reevaluacion
                                    ? formatearFecha(
                                          fila.fecha_limite_reevaluacion,
                                      )
                                    : '—'
                            }}
                        </dd>
                        <dt class="text-[11px] text-muted-foreground">
                            Límite reevaluación
                        </dt>
                    </div>
                    <div class="rounded-xl bg-[var(--mrl-fondo)] p-2">
                        <dd class="text-sm font-semibold">
                            {{
                                fila.retroalimentado_en
                                    ? formatearFecha(fila.retroalimentado_en)
                                    : '—'
                            }}
                        </dd>
                        <dt class="text-[11px] text-muted-foreground">
                            Retroalimentada
                        </dt>
                    </div>
                </dl>

                <p
                    v-if="fila.retroalimentacion"
                    class="mx-4 mb-4 flex items-start gap-2 rounded-xl bg-crema/70 px-3 py-2 text-sm"
                >
                    <MessageSquareText
                        class="mt-0.5 size-4 shrink-0 text-bronce"
                    />
                    <span class="line-clamp-3" :title="fila.retroalimentacion"
                        >«{{ fila.retroalimentacion }}»</span
                    >
                </p>

                <form
                    v-if="
                        fila.evaluacion_id &&
                        retro?.clave === `ev-${fila.evaluacion_id}`
                    "
                    class="mx-4 mb-4 flex flex-col gap-2"
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
                    <div class="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            class="h-10"
                            @click="retro = null"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            class="h-10"
                            :disabled="enviando || !retro.texto.trim()"
                        >
                            <Spinner v-if="enviando" />
                            <Send v-else class="size-4" />
                            Enviar
                        </Button>
                    </div>
                </form>

                <details
                    v-if="fila.modulos.length"
                    class="group mx-4 mb-4 rounded-xl border border-border/60"
                >
                    <summary
                        class="flex cursor-pointer list-none items-center gap-2 px-3 py-2 text-sm font-medium"
                    >
                        <BookOpen class="size-4 text-bronce" />
                        Lecciones
                        <span
                            class="rounded-full bg-muted px-2 text-xs tabular-nums"
                            >{{ fila.modulos.length }}</span
                        >
                        <span
                            v-if="refuerzos(fila) > 0"
                            class="rounded-full bg-danger-soft px-2 text-xs text-destructive"
                            >{{ refuerzos(fila) }} con refuerzo</span
                        >
                        <ChevronDown
                            class="ml-auto size-4 transition-transform group-open:rotate-180"
                        />
                    </summary>
                    <ul
                        class="flex flex-col divide-y divide-border/50 border-t border-border/50"
                    >
                        <li
                            v-for="m in fila.modulos"
                            :key="m.avance_id"
                            class="flex flex-col gap-2 px-3 py-2 text-sm"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="min-w-0 flex-1">{{
                                    m.titulo
                                }}</span>
                                <ChipEstado
                                    :etiqueta="
                                        m.calificacion !== null
                                            ? `${m.estado_etiqueta} · ${m.calificacion}/${m.calificacion_minima}`
                                            : m.estado_etiqueta
                                    "
                                    :tono="
                                        m.requiere_refuerzo
                                            ? 'peligro'
                                            : m.estado === 'aprobado'
                                              ? 'exito'
                                              : 'oro'
                                    "
                                />
                                <Button
                                    v-if="m.requiere_refuerzo"
                                    size="sm"
                                    variant="outline"
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
                                <div class="grid grid-cols-2 gap-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        @click="retro = null"
                                        >Cancelar</Button
                                    >
                                    <Button
                                        type="submit"
                                        :disabled="
                                            enviando || !retro.texto.trim()
                                        "
                                    >
                                        <Spinner v-if="enviando" />
                                        <Send v-else class="size-4" />
                                        Enviar
                                    </Button>
                                </div>
                            </form>
                        </li>
                    </ul>
                </details>

                <footer
                    class="mt-auto flex flex-col gap-2 border-t border-border/50 bg-[var(--mrl-fondo)]/60 p-3 sm:flex-row sm:flex-wrap"
                >
                    <Button
                        v-if="fila.intento_por_calificar_id"
                        as-child
                        class="h-11 flex-1 sm:h-10"
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
                        v-if="
                            fila.etapa === 'reforzamiento' && fila.evaluacion_id
                        "
                        class="h-11 flex-1 sm:h-10"
                        @click="abrirRetro(`ev-${fila.evaluacion_id}`)"
                    >
                        <MessageSquareText class="size-4" /> Retroalimentar
                    </Button>
                    <Button
                        v-if="
                            !fila.intento_por_calificar_id &&
                            fila.ultimo_intento_id
                        "
                        as-child
                        variant="outline"
                        class="h-11 flex-1 sm:h-10"
                    >
                        <Link :href="mostrarIntento(fila.ultimo_intento_id)"
                            ><Eye class="size-4" /> Ver evaluación</Link
                        >
                    </Button>
                    <BotonVistaPrevia
                        v-if="fila.ultimo_intento_id"
                        class="h-11 flex-1 sm:h-10"
                        texto="Ver formato"
                        :icono="FileText"
                        @click="verFormato(fila)"
                    />
                    <p
                        v-if="
                            !fila.ultimo_intento_id &&
                            !fila.intento_por_calificar_id
                        "
                        class="w-full py-2 text-center text-xs text-muted-foreground"
                    >
                        Aún no presenta la evaluación
                    </p>
                </footer>
            </li>
        </ul>
    </div>

    <DocumentPreviewDialog
        :open="formato !== null"
        :preview-url="formato?.url ?? null"
        :nombre="formato?.nombre ?? ''"
        tipo="pdf"
        mime="application/pdf"
        estado="Formato FO-RH-EI"
        @update:open="(v: boolean) => !v && (formato = null)"
    />
</template>
