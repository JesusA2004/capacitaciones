<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BellRing,
    CheckCircle2,
    RotateCcw,
    SlidersHorizontal,
} from '@lucide/vue';
import { computed, reactive } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import ConfiguracionTabs from '@/components/configuracion/ConfiguracionTabs.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index as configuracionIndex } from '@/routes/administracion/configuracion';
import { parametrosRh, restaurar } from '@/routes/administracion/configuracion';
import {
    store as crearMotivoRechazoUrl,
    update as actualizarMotivoRechazo,
} from '@/routes/administracion/configuracion/parametros-rh/motivos-rechazo';
import {
    store as crearPreguntaFiltroUrl,
    update as actualizarPreguntaFiltro,
} from '@/routes/administracion/configuracion/parametros-rh/preguntas-filtro';
import { update as actualizarPuesto } from '@/routes/administracion/configuracion/parametros-rh/puestos';
import { update as actualizarTipoDocumento } from '@/routes/administracion/configuracion/parametros-rh/tipos-documento';
import { cobertura as coberturaDocumental } from '@/routes/rh/documentos-maestros';
import { configuracion as configuracionOnboarding } from '@/routes/rh/onboarding';
import type { ParametroConfiguracion, SeccionConfiguracion } from '@/types';

type PuestoParametros = {
    id: number;
    nombre: string;
    meses_periodo_prueba: number | null;
    grupo_indicador: string | null;
    grupo_documental: string | null;
    historial?: { accion: string; por: string | null; en: string | null }[];
};

type MotivoRechazo = {
    id: number;
    clave: string;
    nombre: string;
    /** requisito | empresa | candidato | sin_contacto */
    categoria: string;
    activo: boolean;
    no_recontratable_por_defecto: boolean;
};

/** Requisito indispensable que se pregunta al registrar un candidato. */
type PreguntaFiltro = {
    id: number;
    pregunta: string;
    /** Vacío = aplica a todos los puestos. */
    puestos: number[];
    respuesta_requerida: boolean;
    eliminatoria: boolean;
    motivo_rechazo_id: number | null;
    activo: boolean;
    orden: number;
};

const props = defineProps<{
    /** Acciones por permiso (el backend lo revalida). */
    puede?: {
        parametros: boolean;
        puestos: boolean;
        tipos_documento: boolean;
        reclutamiento: boolean;
    };
    parametros: ParametroConfiguracion[];
    puestos: PuestoParametros[];
    tiposDocumento: {
        id: number;
        nombre: string;
        vigencia_meses: number | null;
    }[];
    motivosRechazo: MotivoRechazo[];
    categoriasMotivo: { value: string; etiqueta: string }[];
    preguntasFiltro: PreguntaFiltro[];
    grupos: { value: string; etiqueta: string }[];
    gruposDocumentales: { value: string; etiqueta: string }[];
    secciones: SeccionConfiguracion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Configuración', href: configuracionIndex() },
            { title: 'Parámetros de RH', href: '' },
        ],
    },
});

const form = useForm<{ valores: Record<string, string | number | string[]> }>({
    valores: Object.fromEntries(
        props.parametros.map((p) => [
            p.clave,
            Array.isArray(p.valor)
                ? [...p.valor]
                : (p.valor as string | number),
        ]),
    ),
});

function guardar() {
    form.put(parametrosRh.url(), { preserveScroll: true });
}

function restaurarParametro(p: ParametroConfiguracion) {
    router.post(restaurar.url(), { clave: p.clave }, { preserveScroll: true });
}

// Edición en línea: undefined = sin configurar (el puesto no se puede
// contratar hasta tener su duración; no hay respaldo global).
const puestos = reactive(
    props.puestos.map((p) => ({
        ...p,
        meses_periodo_prueba: p.meses_periodo_prueba ?? undefined,
    })),
);
// Sin configurar primero: es lo que bloquea contrataciones.
const puestosOrdenados = computed(() =>
    [...puestos].sort(
        (a, b) =>
            Number(
                Boolean(
                    props.puestos.find((p) => p.id === a.id)
                        ?.meses_periodo_prueba,
                ),
            ) -
            Number(
                Boolean(
                    props.puestos.find((p) => p.id === b.id)
                        ?.meses_periodo_prueba,
                ),
            ),
    ),
);

const resumenDuracion = computed(() => {
    const configurados = props.puestos.filter(
        (p) => (p.meses_periodo_prueba ?? 0) > 0,
    ).length;
    const aviso = props.parametros.find(
        (p) => p.clave === 'rh.dias_aviso_vencimiento',
    );

    return {
        configurados,
        sinConfigurar: props.puestos.length - configurados,
        diasAviso: aviso ? Number(aviso.valor) : null,
    };
});

const vigencias = reactive(
    props.tiposDocumento.map((t) => ({
        ...t,
        vigencia_meses: t.vigencia_meses ?? undefined,
    })),
);

function guardarPuesto(
    p: Omit<PuestoParametros, 'meses_periodo_prueba'> & {
        meses_periodo_prueba: number | undefined;
    },
) {
    router.put(
        actualizarPuesto.url(p.id),
        {
            meses_periodo_prueba: p.meses_periodo_prueba || null,
            grupo_indicador: p.grupo_indicador || null,
            grupo_documental: p.grupo_documental || null,
        },
        { preserveScroll: true },
    );
}

const motivosRechazo = reactive(props.motivosRechazo.map((m) => ({ ...m })));
const formNuevoMotivo = useForm({
    clave: '',
    nombre: '',
    categoria: 'empresa',
    no_recontratable_por_defecto: false,
});

const opcionesCategoria = computed(() =>
    props.categoriasMotivo.map((c) => ({ value: c.value, label: c.etiqueta })),
);

function guardarMotivoRechazo(m: MotivoRechazo) {
    router.put(
        actualizarMotivoRechazo.url(m.id),
        {
            clave: m.clave,
            nombre: m.nombre,
            categoria: m.categoria,
            activo: m.activo,
            no_recontratable_por_defecto: m.no_recontratable_por_defecto,
        },
        { preserveScroll: true },
    );
}

// Preguntas de filtro (requisitos indispensables al registrar candidatos).
const preguntasFiltro = reactive(
    props.preguntasFiltro.map((p) => ({ ...p, puestos: [...p.puestos] })),
);
const formNuevaPregunta = useForm({
    pregunta: '',
    puestos: [] as number[],
    respuesta_requerida: true,
    eliminatoria: true,
    motivo_rechazo_id: null as number | null,
});
const opcionesMotivo = computed(() =>
    props.motivosRechazo
        .filter((m) => m.activo)
        .map((m) => ({ value: m.id, label: m.nombre })),
);
const opcionesRespuesta = [
    { value: 'si', label: 'Debe responder «Sí»' },
    { value: 'no', label: 'Debe responder «No»' },
];

function alternarPuesto(lista: number[], id: number): number[] {
    return lista.includes(id) ? lista.filter((p) => p !== id) : [...lista, id];
}

function guardarPreguntaFiltro(p: PreguntaFiltro) {
    router.put(
        actualizarPreguntaFiltro.url(p.id),
        {
            pregunta: p.pregunta,
            puestos: p.puestos,
            respuesta_requerida: p.respuesta_requerida,
            eliminatoria: p.eliminatoria,
            motivo_rechazo_id: p.motivo_rechazo_id,
            activo: p.activo,
            orden: p.orden,
        },
        { preserveScroll: true },
    );
}

function crearPreguntaFiltro() {
    formNuevaPregunta.post(crearPreguntaFiltroUrl.url(), {
        preserveScroll: true,
        onSuccess: () => formNuevaPregunta.reset(),
    });
}

function crearMotivoRechazo() {
    formNuevoMotivo.post(crearMotivoRechazoUrl.url(), {
        preserveScroll: true,
        onSuccess: () => formNuevoMotivo.reset(),
    });
}

function guardarVigencia(t: {
    id: number;
    vigencia_meses: number | undefined;
}) {
    router.put(
        actualizarTipoDocumento.url(t.id),
        { vigencia_meses: t.vigencia_meses || null },
        { preserveScroll: true },
    );
}

const error = (clave: string) =>
    (form.errors as Record<string, string>)[`valores.${clave}`];
</script>

<template>
    <Head title="Parámetros de RH" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Parámetros de RH"
            descripcion="Valores del ciclo laboral que RH ajusta"
            :icono="SlidersHorizontal"
        />
        <ConfiguracionTabs :secciones="secciones" actual="parametros-rh" />

        <p class="text-sm text-[var(--mrl-texto-suave)]">
            Lo esencial no se configura: RH siempre da la autorización final y
            quien preautoriza no puede autorizar. Los módulos de inducción y los
            tipos de activo se administran en
            <Link
                :href="configuracionOnboarding().url"
                class="text-[var(--mrl-primary)] hover:underline"
                >Onboarding</Link
            >.
        </p>

        <section
            class="flex flex-col gap-4"
            aria-label="Duración de capacitación inicial por puesto"
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="grid gap-1">
                    <h2 class="text-base font-semibold">
                        Duración de capacitación inicial por puesto
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Cada puesto define cuántos meses dura su contrato de
                        capacitación inicial. No hay duración general: un puesto
                        sin configurar no se puede contratar.
                    </p>
                </div>
                <Link
                    :href="coberturaDocumental.url()"
                    class="text-sm font-medium text-primary hover:underline"
                >
                    Ver cobertura documental por puesto →
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div
                    class="flex items-center gap-3 rounded-2xl border border-border/70 bg-card p-4"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-success-soft text-success"
                        ><CheckCircle2 class="size-5"
                    /></span>
                    <div>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ resumenDuracion.configurados }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Puestos configurados
                        </p>
                    </div>
                </div>
                <div
                    class="flex items-center gap-3 rounded-2xl border bg-card p-4"
                    :class="
                        resumenDuracion.sinConfigurar
                            ? 'border-oro/50'
                            : 'border-border/70'
                    "
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-warning-soft text-warning"
                        ><AlertTriangle class="size-5"
                    /></span>
                    <div>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ resumenDuracion.sinConfigurar }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Sin configurar
                        </p>
                    </div>
                </div>
                <div
                    class="flex items-center gap-3 rounded-2xl border border-border/70 bg-card p-4"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-info-soft text-info"
                        ><BellRing class="size-5"
                    /></span>
                    <div>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ resumenDuracion.diasAviso ?? '—' }} días
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Aviso de evaluación antes del vencimiento
                        </p>
                    </div>
                </div>
            </div>

            <ul
                class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,20rem),1fr))] gap-3"
            >
                <li
                    v-for="p in puestosOrdenados"
                    :key="p.id"
                    class="flex flex-col gap-3 rounded-2xl border bg-card p-4 transition-colors"
                    :class="
                        p.meses_periodo_prueba
                            ? 'border-border/70'
                            : 'border-oro/50 bg-warning-soft/20'
                    "
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="leading-snug font-semibold break-words">
                                {{ p.nombre }}
                            </p>
                            <p
                                v-if="p.historial?.length"
                                class="text-xs text-muted-foreground"
                            >
                                Último cambio:
                                {{ p.historial[0].por ?? 'Sistema' }} ·
                                {{
                                    p.historial[0].en
                                        ? formatearFecha(p.historial[0].en)
                                        : ''
                                }}
                            </p>
                        </div>
                        <span
                            v-if="p.meses_periodo_prueba"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success-soft px-2.5 py-1 text-xs font-semibold text-success"
                        >
                            <CheckCircle2 class="size-3.5" />
                            {{ p.meses_periodo_prueba }}
                            {{ p.meses_periodo_prueba === 1 ? 'mes' : 'meses' }}
                        </span>
                        <span
                            v-else
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-warning-soft px-2.5 py-1 text-xs font-semibold text-warning"
                        >
                            <AlertTriangle class="size-3.5" /> Sin configurar
                        </span>
                    </div>

                    <div
                        class="grid grid-cols-[auto_1fr] items-center gap-2 text-sm"
                    >
                        <label
                            :for="`meses-${p.id}`"
                            class="text-xs text-muted-foreground"
                            >Meses</label
                        >
                        <Input
                            :id="`meses-${p.id}`"
                            v-model.number="p.meses_periodo_prueba"
                            type="number"
                            min="1"
                            max="12"
                            placeholder="Ej. 2"
                            class="h-11 w-28 sm:h-9"
                        />
                        <span class="text-xs text-muted-foreground"
                            >Indicadores</span
                        >
                        <SelectSimple
                            v-model="p.grupo_indicador"
                            :opciones="
                                grupos.map((g) => ({
                                    value: g.value,
                                    label: g.etiqueta,
                                }))
                            "
                            opcion-vacia="Sin grupo"
                            size="sm"
                            class="w-full"
                            :aria-label="`Grupo de ${p.nombre}`"
                        />
                        <span class="text-xs text-muted-foreground"
                            >Contratos</span
                        >
                        <SelectSimple
                            v-model="p.grupo_documental"
                            :opciones="
                                gruposDocumentales.map((g) => ({
                                    value: g.value,
                                    label: g.etiqueta,
                                }))
                            "
                            opcion-vacia="Sin grupo"
                            size="sm"
                            class="w-full"
                            :aria-label="`Grupo documental de ${p.nombre}`"
                        />
                    </div>

                    <Button
                        v-if="puede?.puestos ?? true"
                        size="sm"
                        variant="outline"
                        class="min-h-11 self-end sm:min-h-8"
                        @click="guardarPuesto(p)"
                        >Guardar</Button
                    >
                </li>
            </ul>
        </section>

        <form class="flex flex-col gap-3" @submit.prevent="guardar">
            <div
                v-for="p in parametros"
                :key="p.clave"
                class="rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">
                            {{ p.etiqueta }}
                            <span
                                v-if="p.personalizado"
                                class="ml-1 rounded-full bg-[var(--mrl-gold)]/15 px-2 py-0.5 text-xs text-[var(--mrl-gold-dark)]"
                                >personalizado</span
                            >
                        </p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            {{ p.descripcion }}
                        </p>
                    </div>

                    <Input
                        v-if="p.tipo === 'entero' || p.tipo === 'decimal'"
                        v-model.number="form.valores[p.clave] as number"
                        type="number"
                        :step="p.tipo === 'decimal' ? '0.5' : '1'"
                        class="w-28"
                        :aria-label="p.etiqueta"
                    />
                    <SelectSimple
                        v-else-if="p.tipo === 'opcion' && p.opciones"
                        v-model="form.valores[p.clave] as string"
                        :opciones="
                            p.opciones.map((o) => ({
                                value: o.value,
                                label: o.etiqueta,
                            }))
                        "
                        class="w-full sm:w-80"
                        :aria-label="p.etiqueta"
                    />
                    <Button
                        v-if="p.personalizado && (puede?.parametros ?? true)"
                        type="button"
                        size="sm"
                        variant="ghost"
                        :title="`Restaurar ${p.defecto}`"
                        @click="restaurarParametro(p)"
                    >
                        <RotateCcw class="size-4" />
                    </Button>
                </div>

                <fieldset
                    v-if="p.tipo === 'lista' && p.opciones"
                    class="mt-3 grid gap-1 text-sm sm:grid-cols-2"
                >
                    <label
                        v-for="o in p.opciones"
                        :key="o.value"
                        class="flex items-center gap-2"
                    >
                        <Casilla
                            v-model="form.valores[p.clave] as string[]"
                            :value="o.value"
                        />
                        {{ o.etiqueta }}
                    </label>
                </fieldset>
                <InputError :message="error(p.clave)" />
            </div>

            <Button
                v-if="puede?.parametros ?? true"
                type="submit"
                class="w-fit"
                :disabled="form.processing || !form.isDirty"
                >Guardar parámetros</Button
            >
        </form>

        <section class="flex flex-col gap-2" aria-label="Vigencia documental">
            <h2 class="text-sm font-semibold">
                Vigencia de documentos del expediente
            </h2>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                Meses tras los cuales un documento se considera vencido y se
                vuelve a pedir (p. ej. en un reingreso). Vacío = no vence.
            </p>
            <ul class="grid gap-2 sm:grid-cols-2">
                <li
                    v-for="t in vigencias"
                    :key="t.id"
                    class="flex items-center gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-2 text-sm"
                >
                    <span class="min-w-0 flex-1 truncate">{{ t.nombre }}</span>
                    <Input
                        v-model.number="t.vigencia_meses"
                        type="number"
                        min="1"
                        max="120"
                        class="h-8 w-20"
                        :aria-label="`Vigencia de ${t.nombre}`"
                    />
                    <Button
                        v-if="puede?.tipos_documento ?? true"
                        size="sm"
                        variant="ghost"
                        @click="guardarVigencia(t)"
                        >Guardar</Button
                    >
                </li>
            </ul>
        </section>

        <section
            class="flex flex-col gap-2"
            aria-label="Motivos de rechazo de candidatos"
        >
            <h2 class="text-sm font-semibold">
                Motivos de rechazo de candidatos
            </h2>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                Catálogo que usa Reclutamiento al cerrar un proceso o rechazar a
                un candidato. Un motivo ya usado nunca se borra: solo se
                desactiva.
            </p>
            <ul class="grid gap-2 sm:grid-cols-2">
                <li
                    v-for="m in motivosRechazo"
                    :key="m.id"
                    class="flex flex-col gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3 text-sm"
                >
                    <Input v-model="m.nombre" class="h-8" />
                    <SelectSimple
                        v-model="m.categoria"
                        :opciones="opcionesCategoria"
                        size="sm"
                        :aria-label="`Categoría de ${m.nombre}`"
                    />
                    <div
                        class="flex items-center justify-between gap-3 text-xs"
                    >
                        <label class="flex items-center gap-1.5">
                            <Casilla v-model="m.activo" /> Activo
                        </label>
                        <label class="flex items-center gap-1.5">
                            <Casilla v-model="m.no_recontratable_por_defecto" />
                            No recontratable por defecto
                        </label>
                        <Button
                            v-if="puede?.reclutamiento ?? true"
                            size="sm"
                            variant="ghost"
                            @click="guardarMotivoRechazo(m)"
                            >Guardar</Button
                        >
                    </div>
                </li>
            </ul>

            <div
                class="flex flex-wrap items-end gap-2 rounded-xl border border-dashed border-[var(--mrl-borde)] p-3"
            >
                <div class="grid gap-1">
                    <label class="text-xs text-[var(--mrl-texto-suave)]"
                        >Clave única</label
                    >
                    <Input
                        v-model="formNuevoMotivo.clave"
                        placeholder="otro_motivo"
                        class="h-8 w-40"
                    />
                    <InputError :message="formNuevoMotivo.errors.clave" />
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-[var(--mrl-texto-suave)]"
                        >Nombre</label
                    >
                    <Input
                        v-model="formNuevoMotivo.nombre"
                        placeholder="Otro motivo"
                        class="h-8 w-48"
                    />
                    <InputError :message="formNuevoMotivo.errors.nombre" />
                </div>
                <div class="grid gap-1">
                    <label class="text-xs text-[var(--mrl-texto-suave)]"
                        >Categoría</label
                    >
                    <SelectSimple
                        v-model="formNuevoMotivo.categoria"
                        :opciones="opcionesCategoria"
                        size="sm"
                        class="w-64"
                    />
                </div>
                <label class="mb-1.5 flex items-center gap-1.5 text-xs">
                    <Casilla
                        v-model="formNuevoMotivo.no_recontratable_por_defecto"
                    />
                    No recontratable por defecto
                </label>
                <Button
                    v-if="puede?.reclutamiento ?? true"
                    size="sm"
                    @click="crearMotivoRechazo"
                    >Agregar motivo</Button
                >
            </div>
        </section>

        <section
            class="flex flex-col gap-2"
            aria-label="Preguntas de filtro al registrar candidatos"
        >
            <h2 class="text-sm font-semibold">
                Requisitos indispensables al registrar un candidato
            </h2>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                Preguntas rápidas de Sí/No que se hacen al dar de alta a un
                candidato (p. ej. «¿Sabe manejar moto?» para Gestor). Si es
                eliminatoria y no cumple, el candidato se registra igual (cuenta
                para su campaña) pero queda «No viable» con el motivo elegido.
                Una pregunta ya usada no se borra: se desactiva.
            </p>
            <ul class="grid gap-2 lg:grid-cols-2">
                <li
                    v-for="p in preguntasFiltro"
                    :key="p.id"
                    class="flex flex-col gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3 text-sm"
                >
                    <Input
                        v-model="p.pregunta"
                        class="h-8"
                        :aria-label="`Pregunta ${p.id}`"
                    />
                    <div class="grid gap-2 sm:grid-cols-2">
                        <SelectSimple
                            :model-value="p.respuesta_requerida ? 'si' : 'no'"
                            :opciones="opcionesRespuesta"
                            size="sm"
                            aria-label="Respuesta que exige el puesto"
                            @update:model-value="
                                (v) => (p.respuesta_requerida = v === 'si')
                            "
                        />
                        <SelectSimple
                            v-model="p.motivo_rechazo_id"
                            :opciones="opcionesMotivo"
                            numerico
                            size="sm"
                            opcion-vacia="Motivo: no cumple un requisito"
                            aria-label="Motivo con que se descarta"
                        />
                    </div>
                    <div
                        class="flex flex-wrap gap-1.5"
                        aria-label="Puestos a los que aplica"
                    >
                        <button
                            v-for="puesto in puestos"
                            :key="puesto.id"
                            type="button"
                            class="rounded-full border px-2 py-0.5 text-xs"
                            :class="
                                p.puestos.includes(puesto.id)
                                    ? 'border-transparent bg-salvia font-medium'
                                    : 'border-[var(--mrl-borde)] text-[var(--mrl-texto-suave)]'
                            "
                            @click="
                                p.puestos = alternarPuesto(p.puestos, puesto.id)
                            "
                        >
                            {{ puesto.nombre }}
                        </button>
                    </div>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        {{
                            p.puestos.length
                                ? `Aplica a ${p.puestos.length} puesto(s).`
                                : 'Sin puestos marcados: aplica a todos los puestos.'
                        }}
                    </p>
                    <div
                        class="flex items-center justify-between gap-3 text-xs"
                    >
                        <label class="flex items-center gap-1.5">
                            <Casilla v-model="p.activo" /> Activa
                        </label>
                        <label class="flex items-center gap-1.5">
                            <Casilla v-model="p.eliminatoria" />
                            Eliminatoria
                        </label>
                        <Button
                            v-if="puede?.reclutamiento ?? true"
                            size="sm"
                            variant="ghost"
                            @click="guardarPreguntaFiltro(p)"
                            >Guardar</Button
                        >
                    </div>
                </li>
            </ul>

            <div
                class="flex flex-col gap-2 rounded-xl border border-dashed border-[var(--mrl-borde)] p-3"
            >
                <div class="flex flex-wrap items-end gap-2">
                    <div class="grid min-w-64 flex-1 gap-1">
                        <label class="text-xs text-[var(--mrl-texto-suave)]"
                            >Pregunta</label
                        >
                        <Input
                            v-model="formNuevaPregunta.pregunta"
                            placeholder="¿Sabe manejar moto?"
                            class="h-8"
                        />
                        <InputError
                            :message="formNuevaPregunta.errors.pregunta"
                        />
                    </div>
                    <div class="grid gap-1">
                        <label class="text-xs text-[var(--mrl-texto-suave)]"
                            >Respuesta que exige el puesto</label
                        >
                        <SelectSimple
                            :model-value="
                                formNuevaPregunta.respuesta_requerida
                                    ? 'si'
                                    : 'no'
                            "
                            :opciones="opcionesRespuesta"
                            size="sm"
                            class="w-52"
                            @update:model-value="
                                (v) =>
                                    (formNuevaPregunta.respuesta_requerida =
                                        v === 'si')
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <label class="text-xs text-[var(--mrl-texto-suave)]"
                            >Motivo al descartar (opcional)</label
                        >
                        <SelectSimple
                            v-model="formNuevaPregunta.motivo_rechazo_id"
                            :opciones="opcionesMotivo"
                            numerico
                            size="sm"
                            class="w-64"
                            opcion-vacia="No cumple un requisito"
                        />
                    </div>
                    <label class="mb-1.5 flex items-center gap-1.5 text-xs">
                        <Casilla v-model="formNuevaPregunta.eliminatoria" />
                        Eliminatoria
                    </label>
                </div>
                <div
                    class="flex flex-wrap gap-1.5"
                    aria-label="Puestos a los que aplica la nueva pregunta"
                >
                    <button
                        v-for="puesto in puestos"
                        :key="puesto.id"
                        type="button"
                        class="rounded-full border px-2 py-0.5 text-xs"
                        :class="
                            formNuevaPregunta.puestos.includes(puesto.id)
                                ? 'border-transparent bg-salvia font-medium'
                                : 'border-[var(--mrl-borde)] text-[var(--mrl-texto-suave)]'
                        "
                        @click="
                            formNuevaPregunta.puestos = alternarPuesto(
                                formNuevaPregunta.puestos,
                                puesto.id,
                            )
                        "
                    >
                        {{ puesto.nombre }}
                    </button>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Puestos (opcional): sin marcar, aplica a todos.
                    </p>
                    <Button
                        v-if="puede?.reclutamiento ?? true"
                        size="sm"
                        @click="crearPreguntaFiltro"
                        >Agregar pregunta</Button
                    >
                </div>
            </div>
        </section>
    </div>
</template>
