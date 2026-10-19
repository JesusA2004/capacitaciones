<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    DatabaseZap,
    Download,
    FileSpreadsheet,
    FolderSearch,
    History,
    Info,
    KeyRound,
    LayoutGrid,
    Loader2,
    Network,
    Play,
    TrendingDown,
    Upload,
    UserCheck,
    UserPlus,
    Users,
    Wallet,
    XCircle,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { Component } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { dashboard } from '@/routes';
import { index as importarDatosIndex } from '@/routes/administracion/importar-datos';
import {
    analizar,
    aplicar,
    estado as estadoRuta,
    index,
} from '@/routes/administracion/importar-datos/carga-inicial';
import { credenciales as credencialesRuta } from '@/routes/rh/expedientes/migracion';
import type {
    CargaInicialCorrida,
    CargaInicialPageProps,
    CargaInicialPreview,
} from '@/types';

/**
 * Carga inicial PEOPLE: un solo XLSX con colaboradores, estructura,
 * salarios, vacaciones, headcount, rotación y expedientes históricos.
 * Flujo: Elegir XLSX → Analizar (no modifica nada) → Revisión general →
 * Conflictos → Confirmar → Ejecutar (Job en cola, se sondea el estado) →
 * Resultado.
 */
const props = defineProps<CargaInicialPageProps>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Importar datos', href: importarDatosIndex() },
            { title: 'Carga inicial PEOPLE', href: index() },
        ],
    },
});

const ESTADOS_EN_CURSO = ['en_cola', 'aplicando'];
const ESTADOS_TERMINADOS = ['completado', 'completado_con_errores', 'fallido'];

const etiquetaEstado: Record<string, string> = {
    analizado: 'Analizado',
    en_cola: 'En cola',
    aplicando: 'Aplicando',
    completado: 'Completado',
    completado_con_errores: 'Completado con errores',
    fallido: 'Fallido',
};

function textoEstado(e: string): string {
    return etiquetaEstado[e] ?? e;
}

function colorEstado(e: string): string {
    return (
        {
            analizado: 'bg-info/15 text-info',
            en_cola: 'bg-warning/15 text-warning',
            aplicando: 'bg-warning/15 text-warning',
            completado: 'bg-success/15 text-success',
            completado_con_errores: 'bg-warning/15 text-warning',
            fallido: 'bg-destructive/15 text-destructive',
        }[e] ?? 'bg-muted text-muted-foreground'
    );
}

function fecha(valor: string | null): string {
    return valor ? new Date(valor).toLocaleString('es-MX') : '—';
}

// --- Paso 1-2: elegir y analizar ------------------------------------------
const formArchivo = useForm({ archivo: null as File | null });
const arrastrando = ref(false);

function elegir(archivo: File | undefined | null) {
    formArchivo.archivo = archivo ?? null;
}

function analizarArchivo() {
    formArchivo.post(analizar.url(), {
        forceFormData: true,
        preserveScroll: true,
    });
}

// --- Revisión ----------------------------------------------------------------
const preview = computed<CargaInicialPreview | null>(
    () => props.corrida?.preview ?? null,
);

// --- Ejecutar ----------------------------------------------------------------
const formAplicar = useForm({ confirmacion: false });

function ejecutar() {
    if (!props.corrida) {
        return;
    }

    formAplicar.post(aplicar.url(props.corrida.id), {
        preserveScroll: true,
        onSuccess: () => iniciarSondeo(),
    });
}

const avance = ref({
    estado: props.corrida?.estado ?? '',
    etapa: props.corrida?.etapa ?? null,
    progreso: props.corrida?.progreso ?? 0,
    total: props.corrida?.progreso_total ?? 0,
});
const segundosEnCola = ref(0);
let sondeo: ReturnType<typeof setInterval> | undefined;

function sincronizarAvance(c: CargaInicialCorrida | null) {
    avance.value = {
        estado: c?.estado ?? '',
        etapa: c?.etapa ?? null,
        progreso: c?.progreso ?? 0,
        total: c?.progreso_total ?? 0,
    };
}

function iniciarSondeo() {
    clearInterval(sondeo);
    segundosEnCola.value = 0;

    sondeo = setInterval(async () => {
        if (!props.corrida) {
            clearInterval(sondeo);

            return;
        }

        try {
            const r = await fetch(estadoRuta.url(props.corrida.id), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!r.ok) {
                return;
            }

            const datos = (await r.json()) as { data: CargaInicialCorrida };
            sincronizarAvance(datos.data);
            segundosEnCola.value =
                datos.data.estado === 'en_cola' ? segundosEnCola.value + 3 : 0;

            if (!ESTADOS_EN_CURSO.includes(datos.data.estado)) {
                clearInterval(sondeo);
                router.reload({ only: ['corrida', 'historial'] });
            }
        } catch {
            // Un fallo de red puntual no detiene el sondeo.
        }
    }, 3000);
}

watch(
    () => props.corrida,
    (c) => {
        sincronizarAvance(c);

        if (c && ESTADOS_EN_CURSO.includes(c.estado)) {
            iniciarSondeo();
        } else {
            clearInterval(sondeo);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(sondeo));

const enCurso = computed(() => ESTADOS_EN_CURSO.includes(avance.value.estado));
const terminada = computed(() =>
    ESTADOS_TERMINADOS.includes(props.corrida?.estado ?? ''),
);
const analizada = computed(
    () => props.corrida?.estado === 'analizado' && !enCurso.value,
);

const porcentaje = computed(() =>
    avance.value.total > 0
        ? Math.min(
              100,
              Math.round((avance.value.progreso / avance.value.total) * 100),
          )
        : 0,
);

// --- Stepper -----------------------------------------------------------------
const pasos = [
    'Elegir XLSX',
    'Analizar',
    'Revisión general',
    'Conflictos',
    'Confirmar',
    'Ejecutar',
    'Resultado',
];

const pasoActual = computed(() => {
    if (!props.corrida) {
        if (formArchivo.processing) {
            return 2;
        }

        return 1;
    }

    if (terminada.value) {
        return 7;
    }

    if (enCurso.value) {
        return 6;
    }

    return formAplicar.confirmacion ? 5 : 3;
});

// --- Conflictos --------------------------------------------------------------
const totalConflictos = computed(() => {
    const p = preview.value;

    if (!p) {
        return 0;
    }

    return (
        p.conflictos_filas.length +
        p.pendientes_rh.length +
        p.salarios.pendientes.length +
        p.informativos.length
    );
});

function colorInformativo(tipo: string): string {
    if (tipo === 'PENDIENTE_ACTUAL') {
        return 'bg-warning/15 text-warning';
    }

    if (tipo === 'EXCLUIDO_HEADCOUNT') {
        return 'bg-info/15 text-info';
    }

    if (tipo.startsWith('EXCLUIDO')) {
        return 'bg-muted text-muted-foreground';
    }

    return 'bg-primary/10 text-primary';
}

// --- Resultado ---------------------------------------------------------------
type Bloque = {
    titulo: string;
    icono: Component;
    items: { etiqueta: string; valor: number }[];
};

const bloquesResultado = computed<Bloque[]>(() => {
    const r = props.corrida?.resultado;

    if (!r) {
        return [];
    }

    return [
        {
            titulo: 'Colaboradores',
            icono: Users,
            items: [
                { etiqueta: 'Creados', valor: r.colaboradores.creados },
                {
                    etiqueta: 'Actualizados',
                    valor: r.colaboradores.actualizados,
                },
                { etiqueta: 'Sin cambios', valor: r.colaboradores.sin_cambios },
                { etiqueta: 'En revisión', valor: r.colaboradores.en_revision },
                { etiqueta: 'Conflictos', valor: r.colaboradores.conflictos },
                { etiqueta: 'Errores', valor: r.colaboradores.errores },
                { etiqueta: 'Cuentas creadas', valor: r.cuentas_creadas },
            ],
        },
        {
            titulo: 'Catálogos',
            icono: Network,
            items: [
                {
                    etiqueta: 'Departamentos creados',
                    valor: r.catalogos.departamentos_creados,
                },
                {
                    etiqueta: 'Puestos creados',
                    valor: r.catalogos.puestos_creados,
                },
            ],
        },
        {
            titulo: 'Salarios',
            icono: Wallet,
            items: [
                { etiqueta: 'Aplicados', valor: r.salarios.aplicados },
                { etiqueta: 'Sin cambios', valor: r.salarios.sin_cambios },
                { etiqueta: 'Pendientes', valor: r.salarios.pendientes },
            ],
        },
        {
            titulo: 'Vacaciones',
            icono: UserCheck,
            items: [
                { etiqueta: 'Aplicados', valor: r.vacaciones.aplicados },
                { etiqueta: 'Sin cambios', valor: r.vacaciones.sin_cambios },
            ],
        },
        {
            titulo: 'Headcount',
            icono: LayoutGrid,
            items: [
                { etiqueta: 'Creados', valor: r.headcount.creados },
                { etiqueta: 'Actualizados', valor: r.headcount.actualizados },
                { etiqueta: 'Sin cambios', valor: r.headcount.sin_cambios },
            ],
        },
        {
            titulo: 'Vacantes',
            icono: UserPlus,
            items: [
                { etiqueta: 'Abiertas', valor: r.vacantes.abiertas },
                { etiqueta: 'Cerradas', valor: r.vacantes.cerradas },
                { etiqueta: 'Actualizadas', valor: r.vacantes.actualizadas },
            ],
        },
        {
            titulo: 'Rotación',
            icono: TrendingDown,
            items: [
                { etiqueta: 'Nuevos', valor: r.rotacion.nuevos },
                { etiqueta: 'Reemplazados', valor: r.rotacion.reemplazados },
                { etiqueta: 'Sin cambios', valor: r.rotacion.sin_cambios },
            ],
        },
        {
            titulo: 'Expedientes NAS',
            icono: FolderSearch,
            items: [
                {
                    etiqueta: 'PDF registrados',
                    valor: r.nas.pdfs_registrados,
                },
                {
                    etiqueta: 'Pendientes de vincular',
                    valor: r.nas.pendientes_vincular,
                },
                {
                    etiqueta: 'Revisión pendiente',
                    valor: r.nas.revision_pendiente,
                },
            ],
        },
    ];
});
</script>

<template>
    <Head title="Carga inicial PEOPLE" />

    <div class="pagina-ancha flex min-w-0 flex-col gap-6">
        <CrudPageHeader
            titulo="Carga inicial PEOPLE"
            descripcion="Importa en una sola operación colaboradores, estructura, salarios, vacaciones, headcount, rotación y expedientes históricos."
            :icono="DatabaseZap"
            detalle
        />

        <!-- Stepper -->
        <ol class="flex flex-wrap gap-2 text-sm" aria-label="Pasos">
            <li
                v-for="(p, i) in pasos"
                :key="p"
                class="flex items-center gap-2 rounded-full border px-3 py-1"
                :class="
                    i + 1 === pasoActual
                        ? 'border-primary bg-primary/10 font-semibold text-primary'
                        : i + 1 < pasoActual
                          ? 'border-success/40 text-success'
                          : 'border-border text-muted-foreground'
                "
                :aria-current="i + 1 === pasoActual ? 'step' : undefined"
            >
                <span
                    class="flex size-5 items-center justify-center rounded-full bg-[var(--mrl-fondo)] text-xs"
                    >{{ i + 1 }}</span
                >
                {{ p }}
            </li>
        </ol>

        <!-- Paso 1-2: Elegir y analizar -->
        <section
            v-if="!enCurso"
            class="rounded-2xl border border-border/60 bg-card p-4 sm:p-5"
        >
            <h2 class="mb-1 text-base font-semibold">
                1. Elegir el archivo de carga inicial (XLSX)
            </h2>
            <p class="mb-4 text-sm text-muted-foreground">
                Analizar solo lee el archivo y revisa el NAS: no modifica nada.
                Al terminar verás la revisión general y los conflictos antes de
                confirmar.
            </p>
            <label
                class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-6 text-center transition sm:p-8"
                :class="
                    arrastrando
                        ? 'border-primary bg-primary/5'
                        : 'border-border hover:border-primary/50'
                "
                @dragover.prevent="arrastrando = true"
                @dragleave.prevent="arrastrando = false"
                @drop.prevent="
                    (e: DragEvent) => {
                        arrastrando = false;
                        elegir(e.dataTransfer?.files?.[0]);
                    }
                "
            >
                <FileSpreadsheet class="size-8 text-muted-foreground" />
                <span class="text-sm font-medium break-all">{{
                    formArchivo.archivo
                        ? formArchivo.archivo.name
                        : 'Arrastra el XLSX aquí o haz clic para elegirlo'
                }}</span>
                <input
                    type="file"
                    accept=".xlsx"
                    class="hidden"
                    @change="
                        (e: Event) =>
                            elegir((e.target as HTMLInputElement).files?.[0])
                    "
                />
            </label>
            <InputError :message="formArchivo.errors.archivo" class="mt-2" />
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <Button
                    :disabled="!formArchivo.archivo || formArchivo.processing"
                    @click="analizarArchivo"
                >
                    <Loader2
                        v-if="formArchivo.processing"
                        class="size-4 animate-spin"
                    />
                    <Upload v-else class="size-4" />
                    2. Analizar (no modifica nada)
                </Button>
                <div
                    v-if="formArchivo.processing"
                    class="flex min-w-0 flex-1 basis-48 flex-col gap-1"
                >
                    <Progress
                        :model-value="formArchivo.progress?.percentage ?? 50"
                    />
                    <span class="text-xs text-muted-foreground"
                        >Leyendo el archivo y revisando el NAS…</span
                    >
                </div>
            </div>
        </section>

        <template v-if="corrida">
            <div
                class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
            >
                <span class="font-medium break-all text-foreground"
                    >#{{ corrida.id }} · {{ corrida.archivo }}</span
                >
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                    :class="colorEstado(corrida.estado)"
                    >{{ textoEstado(corrida.estado) }}</span
                >
                <span
                    >{{ corrida.usuario ?? '—' }} ·
                    {{ fecha(corrida.creada_en) }}</span
                >
            </div>

            <div
                v-if="corrida.preview_error"
                class="flex gap-2 rounded-xl bg-warning/10 p-3 text-sm text-warning"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <p>
                    {{ corrida.preview_error }} Vuelve a elegir el archivo en el
                    paso 1 para analizarlo de nuevo.
                </p>
            </div>

            <!-- Paso 3: Revisión general -->
            <template v-if="preview && !terminada">
                <section class="flex flex-col gap-4">
                    <h2 class="text-base font-semibold">3. Revisión general</h2>

                    <ul
                        v-if="preview.advertencias.length"
                        class="flex flex-col gap-1 rounded-xl bg-warning/10 p-3 text-sm text-warning"
                    >
                        <li
                            v-for="(a, i) in preview.advertencias"
                            :key="i"
                            class="flex gap-2"
                        >
                            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                            <span>{{ a }}</span>
                        </li>
                    </ul>

                    <!-- Colaboradores -->
                    <div
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
                    >
                        <MetricCard
                            etiqueta="Colaboradores"
                            :valor="preview.colaboradores.total"
                            :icono="Users"
                        />
                        <MetricCard
                            etiqueta="Crear"
                            :valor="preview.colaboradores.crear"
                            :icono="UserPlus"
                            color-clase="bg-success/15 text-success"
                        />
                        <MetricCard
                            etiqueta="Actualizar"
                            :valor="preview.colaboradores.actualizar"
                            :icono="History"
                            color-clase="bg-info/15 text-info"
                        />
                        <MetricCard
                            etiqueta="Sin cambios"
                            :valor="preview.colaboradores.sin_cambios"
                            :icono="CheckCircle2"
                        />
                        <MetricCard
                            etiqueta="Pendientes"
                            :valor="preview.colaboradores.pendientes"
                            :icono="AlertTriangle"
                            color-clase="bg-warning/15 text-warning"
                        />
                        <MetricCard
                            etiqueta="Conflictos"
                            :valor="preview.colaboradores.conflictos"
                            :icono="XCircle"
                            color-clase="bg-destructive/15 text-destructive"
                        />
                    </div>

                    <div class="rejilla-auto" style="--rejilla-min: 18rem">
                        <!-- Catálogos -->
                        <article
                            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold"
                            >
                                <Network class="size-4 text-primary" />
                                Catálogos
                            </h3>
                            <dl class="grid grid-cols-2 gap-2 text-sm">
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Departamentos reconocidos
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{
                                            preview.catalogos
                                                .departamentos_reconocidos
                                                .length
                                        }}
                                    </dd>
                                </div>
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Departamentos a crear
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{
                                            preview.catalogos
                                                .departamentos_crear.length
                                        }}
                                    </dd>
                                </div>
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Puestos reconocidos
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{
                                            preview.catalogos
                                                .puestos_reconocidos.length
                                        }}
                                    </dd>
                                </div>
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Puestos a crear
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{
                                            preview.catalogos.puestos_crear
                                                .length
                                        }}
                                    </dd>
                                </div>
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Puestos por contexto
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{
                                            preview.catalogos.puestos_contexto
                                                .length
                                        }}
                                    </dd>
                                </div>
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Aliases aplicados
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{ preview.catalogos.aliases.length }}
                                    </dd>
                                </div>
                            </dl>
                            <details
                                v-if="
                                    preview.catalogos.departamentos_crear.length
                                "
                                class="text-sm"
                            >
                                <summary class="cursor-pointer font-medium">
                                    Departamentos a crear desde la fuente
                                </summary>
                                <p class="mt-1 text-muted-foreground">
                                    {{
                                        preview.catalogos.departamentos_crear.join(
                                            ', ',
                                        )
                                    }}
                                </p>
                            </details>
                            <details
                                v-if="preview.catalogos.puestos_crear.length"
                                class="text-sm"
                            >
                                <summary class="cursor-pointer font-medium">
                                    Puestos a crear
                                </summary>
                                <ul class="mt-1 text-muted-foreground">
                                    <li
                                        v-for="p in preview.catalogos
                                            .puestos_crear"
                                        :key="`${p.puesto}-${p.departamento}`"
                                    >
                                        {{ p.puesto
                                        }}<template v-if="p.departamento">
                                            · {{ p.departamento }}</template
                                        >
                                    </li>
                                </ul>
                            </details>
                            <details
                                v-if="preview.catalogos.puestos_contexto.length"
                                class="text-sm"
                            >
                                <summary class="cursor-pointer font-medium">
                                    Puestos que se resuelven por sucursal/región
                                </summary>
                                <p class="mt-1 text-muted-foreground">
                                    {{
                                        preview.catalogos.puestos_contexto.join(
                                            ', ',
                                        )
                                    }}
                                </p>
                            </details>
                            <details
                                v-if="preview.catalogos.aliases.length"
                                class="text-sm"
                            >
                                <summary class="cursor-pointer font-medium">
                                    Aliases aplicados
                                </summary>
                                <ul class="mt-1 text-muted-foreground">
                                    <li
                                        v-for="a in preview.catalogos.aliases"
                                        :key="`${a.origen}-${a.destino}`"
                                        class="break-words"
                                    >
                                        «{{ a.origen }}» → «{{ a.destino }}»
                                    </li>
                                </ul>
                            </details>
                            <ul
                                v-if="preview.catalogos.conflictos.length"
                                class="flex flex-col gap-1 rounded-lg bg-destructive/10 p-2 text-xs text-destructive"
                            >
                                <li
                                    v-for="(c, i) in preview.catalogos
                                        .conflictos"
                                    :key="i"
                                >
                                    {{ c }}
                                </li>
                            </ul>
                        </article>

                        <!-- Salarios y vacaciones -->
                        <article
                            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold"
                            >
                                <Wallet class="size-4 text-primary" />
                                Salarios y vacaciones
                            </h3>
                            <dl class="grid grid-cols-3 gap-2 text-sm">
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Vinculados
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{ preview.salarios.vinculados }}
                                    </dd>
                                </div>
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Pendientes
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{ preview.salarios.pendientes.length }}
                                    </dd>
                                </div>
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Cambios
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{ preview.salarios.cambios }}
                                    </dd>
                                </div>
                            </dl>
                        </article>

                        <!-- Headcount -->
                        <article
                            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold"
                            >
                                <LayoutGrid class="size-4 text-primary" />
                                Headcount autorizado
                            </h3>
                            <dl class="grid grid-cols-2 gap-2 text-sm">
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Registros
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{ preview.headcount.registros }}
                                    </dd>
                                </div>
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Total autorizado
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{ preview.headcount.total_autorizado }}
                                    </dd>
                                </div>
                            </dl>
                            <p
                                v-if="preview.headcount.fecha_corte"
                                class="text-xs text-muted-foreground"
                            >
                                Fecha de corte:
                                {{ preview.headcount.fecha_corte }}
                            </p>
                            <ul
                                v-if="preview.headcount.conflictos.length"
                                class="flex flex-col gap-1 rounded-lg bg-destructive/10 p-2 text-xs text-destructive"
                            >
                                <li
                                    v-for="(c, i) in preview.headcount
                                        .conflictos"
                                    :key="i"
                                >
                                    {{ c }}
                                </li>
                            </ul>
                        </article>

                        <!-- Rotación -->
                        <article
                            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold"
                            >
                                <TrendingDown class="size-4 text-primary" />
                                Rotación de personal
                            </h3>
                            <dl class="grid grid-cols-2 gap-2 text-sm">
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Periodos
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{ preview.rotacion.periodos }}
                                    </dd>
                                </div>
                                <div class="rounded-lg bg-muted/40 p-2">
                                    <dt class="text-xs text-muted-foreground">
                                        Sucursales
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{ preview.rotacion.sucursales }}
                                    </dd>
                                </div>
                            </dl>
                            <ul
                                v-if="preview.rotacion.conflictos.length"
                                class="flex flex-col gap-1 rounded-lg bg-destructive/10 p-2 text-xs text-destructive"
                            >
                                <li
                                    v-for="(c, i) in preview.rotacion
                                        .conflictos"
                                    :key="i"
                                >
                                    {{ c }}
                                </li>
                            </ul>
                        </article>

                        <!-- NAS -->
                        <article
                            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold"
                            >
                                <FolderSearch class="size-4 text-primary" />
                                Expedientes históricos (NAS)
                            </h3>
                            <p class="text-xs break-all text-muted-foreground">
                                Origen: <code>{{ preview.nas.ruta }}</code>
                            </p>
                            <p
                                v-if="!preview.nas.origen_existe"
                                class="flex gap-2 rounded-lg bg-warning/10 p-2 text-xs text-warning"
                            >
                                <AlertTriangle class="size-4 shrink-0" />
                                El servidor no ve la carpeta de origen en el
                                NAS: solo se importarán los datos, sin
                                expedientes.
                            </p>
                            <dl class="grid grid-cols-2 gap-2 text-sm">
                                <div
                                    v-for="d in [
                                        {
                                            k: 'Carpetas',
                                            v: preview.nas.carpetas,
                                        },
                                        {
                                            k: 'Match exacto',
                                            v: preview.nas.match_exacto,
                                        },
                                        {
                                            k: 'Match alto',
                                            v: preview.nas.match_alto,
                                        },
                                        {
                                            k: 'Revisión manual',
                                            v: preview.nas.revision_manual,
                                        },
                                        {
                                            k: 'Sin match',
                                            v: preview.nas.sin_match,
                                        },
                                        {
                                            k: 'Históricos',
                                            v: preview.nas.historicos,
                                        },
                                        {
                                            k: 'Pendientes de vincular',
                                            v: preview.nas.pendientes_vincular,
                                        },
                                    ]"
                                    :key="d.k"
                                    class="rounded-lg bg-muted/40 p-2"
                                >
                                    <dt class="text-xs text-muted-foreground">
                                        {{ d.k }}
                                    </dt>
                                    <dd class="text-lg font-semibold">
                                        {{ d.v }}
                                    </dd>
                                </div>
                            </dl>
                        </article>
                    </div>
                </section>

                <!-- Paso 4: Conflictos -->
                <section
                    class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-4 sm:p-5"
                >
                    <div>
                        <h2 class="text-base font-semibold">
                            4. Conflictos y pendientes
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Nada de esta sección se aplica: queda registrado
                            para que RH lo resuelva después.
                        </p>
                    </div>

                    <p
                        v-if="totalConflictos === 0"
                        class="flex items-center gap-2 rounded-lg bg-success/10 p-3 text-sm text-success"
                    >
                        <CheckCircle2 class="size-4" />
                        Sin conflictos ni pendientes.
                    </p>

                    <!-- Conflictos por fila -->
                    <div v-if="preview.conflictos_filas.length">
                        <h3
                            class="mb-2 flex items-center gap-2 text-sm font-semibold text-destructive"
                        >
                            <XCircle class="size-4" />
                            Filas en conflicto ({{
                                preview.conflictos_filas.length
                            }}) — no se aplican
                        </h3>
                        <div
                            class="overflow-x-auto rounded-xl border border-border/60"
                        >
                            <table class="w-full min-w-[32rem] text-sm">
                                <thead
                                    class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                                >
                                    <tr>
                                        <th class="p-3">Fila</th>
                                        <th class="p-3">Clave</th>
                                        <th class="p-3">Nombre</th>
                                        <th class="p-3">Motivos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="f in preview.conflictos_filas"
                                        :key="f.fila"
                                        class="border-t border-border/60 align-top"
                                    >
                                        <td class="p-3 tabular-nums">
                                            {{ f.fila }}
                                        </td>
                                        <td class="p-3">
                                            {{ f.clave ?? '—' }}
                                        </td>
                                        <td class="p-3">
                                            {{ f.nombre ?? '—' }}
                                        </td>
                                        <td
                                            class="p-3 text-xs text-destructive"
                                        >
                                            <p
                                                v-for="(m, i) in f.motivos"
                                                :key="i"
                                            >
                                                {{ m }}
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pendientes RH -->
                    <div v-if="preview.pendientes_rh.length">
                        <h3
                            class="mb-2 flex items-center gap-2 text-sm font-semibold text-warning"
                        >
                            <AlertTriangle class="size-4" />
                            Pendientes de RH ({{
                                preview.pendientes_rh.length
                            }}) — quedan en revisión, no se aplican
                        </h3>
                        <div
                            class="overflow-x-auto rounded-xl border border-border/60"
                        >
                            <table class="w-full min-w-[32rem] text-sm">
                                <thead
                                    class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                                >
                                    <tr>
                                        <th class="p-3">Fila</th>
                                        <th class="p-3">Clave</th>
                                        <th class="p-3">Nombre</th>
                                        <th class="p-3">Motivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="f in preview.pendientes_rh"
                                        :key="f.fila"
                                        class="border-t border-border/60 align-top"
                                    >
                                        <td class="p-3 tabular-nums">
                                            {{ f.fila }}
                                        </td>
                                        <td class="p-3">
                                            {{ f.clave ?? '—' }}
                                        </td>
                                        <td class="p-3">
                                            {{ f.nombre ?? '—' }}
                                        </td>
                                        <td class="p-3 text-xs">
                                            {{ f.motivo }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Salarios pendientes -->
                    <div v-if="preview.salarios.pendientes.length">
                        <h3
                            class="mb-2 flex items-center gap-2 text-sm font-semibold text-warning"
                        >
                            <Wallet class="size-4" />
                            Salarios sin vincular ({{
                                preview.salarios.pendientes.length
                            }})
                        </h3>
                        <div
                            class="overflow-x-auto rounded-xl border border-border/60"
                        >
                            <table class="w-full min-w-[28rem] text-sm">
                                <thead
                                    class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                                >
                                    <tr>
                                        <th class="p-3">Clave</th>
                                        <th class="p-3">Nombre</th>
                                        <th class="p-3">Motivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(s, i) in preview.salarios
                                            .pendientes"
                                        :key="i"
                                        class="border-t border-border/60 align-top"
                                    >
                                        <td class="p-3">
                                            {{ s.clave ?? '—' }}
                                        </td>
                                        <td class="p-3">
                                            {{ s.nombre ?? '—' }}
                                        </td>
                                        <td class="p-3 text-xs">
                                            {{ s.motivo }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Informativos (hoja PENDIENTES) -->
                    <div v-if="preview.informativos.length">
                        <h3
                            class="mb-2 flex items-center gap-2 text-sm font-semibold"
                        >
                            <Info class="size-4 text-info" />
                            Hoja PENDIENTES del archivo ({{
                                preview.informativos.length
                            }})
                        </h3>
                        <p class="mb-2 text-xs text-muted-foreground">
                            Informativo. Los registros EXCLUIDO no se importan.
                        </p>
                        <div
                            class="overflow-x-auto rounded-xl border border-border/60"
                        >
                            <table class="w-full min-w-[32rem] text-sm">
                                <thead
                                    class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                                >
                                    <tr>
                                        <th class="p-3">Tipo</th>
                                        <th class="p-3">Clave</th>
                                        <th class="p-3">Nombre</th>
                                        <th class="p-3">Detalle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(f, i) in preview.informativos"
                                        :key="i"
                                        class="border-t border-border/60 align-top"
                                    >
                                        <td class="p-3">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap"
                                                :class="
                                                    colorInformativo(f.tipo)
                                                "
                                                >{{ f.tipo }}</span
                                            >
                                            <span
                                                v-if="f.tipo === 'EXCLUIDO'"
                                                class="mt-1 block text-xs text-muted-foreground"
                                                >No se importa</span
                                            >
                                        </td>
                                        <td class="p-3">
                                            {{ f.clave ?? '—' }}
                                        </td>
                                        <td class="p-3">
                                            {{ f.nombre ?? '—' }}
                                        </td>
                                        <td class="p-3 text-xs">
                                            {{ f.detalle ?? '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Paso 5: Confirmar -->
                <section
                    v-if="analizada"
                    class="flex flex-col gap-3 rounded-2xl border border-primary/40 bg-card p-4 sm:p-5"
                >
                    <h2 class="text-base font-semibold">5. Confirmar</h2>
                    <ul
                        class="flex list-disc flex-col gap-1 pl-5 text-sm text-muted-foreground"
                    >
                        <li>
                            Analizar no modificó nada: hasta este punto el
                            sistema está igual que antes.
                        </li>
                        <li>
                            El NAS no se mueve, borra ni renombra: los
                            expedientes solo se registran donde ya están.
                        </li>
                        <li>
                            Las filas en conflicto, los pendientes de RH y los
                            registros excluidos no se aplican.
                        </li>
                    </ul>
                    <label class="flex items-start gap-2 text-sm">
                        <Casilla
                            v-model="formAplicar.confirmacion"
                            class="mt-0.5"
                        />
                        <span>
                            Entiendo que se aplicará la carga a
                            {{ preview.colaboradores.crear }} colaboradores
                            nuevos y {{ preview.colaboradores.actualizar }}
                            actualizaciones, junto con su estructura, salarios,
                            vacaciones, headcount, rotación y expedientes
                            históricos.
                        </span>
                    </label>
                    <InputError :message="formAplicar.errors.confirmacion" />

                    <!-- Paso 6: Ejecutar -->
                    <Button
                        class="self-start"
                        :disabled="
                            !formAplicar.confirmacion || formAplicar.processing
                        "
                        @click="ejecutar"
                    >
                        <Loader2
                            v-if="formAplicar.processing"
                            class="size-4 animate-spin"
                        />
                        <Play v-else class="size-4" />
                        6. Ejecutar carga inicial
                    </Button>
                </section>
            </template>

            <!-- Paso 6: Ejecutando -->
            <section
                v-if="enCurso"
                class="flex flex-col gap-3 rounded-2xl border border-primary/40 bg-card p-4 sm:p-5"
                role="status"
                aria-live="polite"
            >
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <Loader2 class="size-5 animate-spin text-primary" />
                    6. Ejecutando carga inicial
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        avance.estado === 'en_cola'
                            ? 'En cola, iniciando…'
                            : (avance.etapa ?? 'Procesando…')
                    }}
                </p>
                <Progress :model-value="porcentaje" />
                <p class="text-sm text-muted-foreground tabular-nums">
                    {{ avance.progreso }} / {{ avance.total }} ({{
                        porcentaje
                    }}%)
                </p>
                <p class="text-xs text-muted-foreground">
                    Se ejecuta en segundo plano: puedes cerrar esta pestaña y
                    volver después; el avance se conserva.
                </p>
                <p v-if="segundosEnCola > 30" class="text-xs text-warning">
                    Sigue en cola. Verifica que el servidor tenga corriendo
                    <code>php artisan queue:work</code>.
                </p>
            </section>

            <!-- Paso 7: Resultado -->
            <section
                v-if="terminada"
                class="flex flex-col gap-4 rounded-2xl border bg-card p-4 sm:p-5"
                :class="
                    corrida.estado === 'fallido'
                        ? 'border-destructive/40'
                        : 'border-success/40'
                "
            >
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <XCircle
                        v-if="corrida.estado === 'fallido'"
                        class="size-5 text-destructive"
                    />
                    <CheckCircle2 v-else class="size-5 text-success" />
                    7. Resultado — {{ textoEstado(corrida.estado) }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    Terminó: {{ fecha(corrida.terminada_en) }}
                </p>
                <p
                    v-if="corrida.error"
                    class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
                >
                    {{ corrida.error }}
                </p>

                <div
                    v-if="bloquesResultado.length"
                    class="rejilla-auto"
                    style="--rejilla-min: 16rem"
                >
                    <article
                        v-for="b in bloquesResultado"
                        :key="b.titulo"
                        class="flex flex-col gap-2 rounded-xl border border-border/60 p-3"
                    >
                        <h3
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <component
                                :is="b.icono"
                                class="size-4 text-primary"
                            />
                            {{ b.titulo }}
                        </h3>
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <div
                                v-for="it in b.items"
                                :key="it.etiqueta"
                                class="rounded-lg bg-muted/40 p-2"
                            >
                                <dt class="text-xs text-muted-foreground">
                                    {{ it.etiqueta }}
                                </dt>
                                <dd class="text-lg font-semibold tabular-nums">
                                    {{ it.valor }}
                                </dd>
                            </div>
                        </dl>
                    </article>
                </div>

                <div v-if="corrida.resultado?.errores.length">
                    <h3
                        class="mb-2 flex items-center gap-2 text-sm font-semibold text-destructive"
                    >
                        <AlertTriangle class="size-4" />
                        Errores ({{ corrida.resultado.errores.length }})
                    </h3>
                    <ul
                        class="flex max-h-80 flex-col gap-1 overflow-y-auto rounded-lg bg-destructive/10 p-3 text-xs break-words text-destructive"
                    >
                        <li
                            v-for="(e, i) in corrida.resultado.errores"
                            :key="i"
                        >
                            {{ e }}
                        </li>
                    </ul>
                </div>

                <div
                    class="flex flex-col gap-3 rounded-xl border border-border/60 p-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="flex items-start gap-2 text-sm">
                        <KeyRound class="mt-0.5 size-4 shrink-0 text-primary" />
                        <span>
                            Accesos generados para los colaboradores. La
                            contraseña es temporal: entrégala de forma privada.
                        </span>
                    </p>
                    <Button as-child class="self-start sm:self-auto">
                        <a :href="credencialesRuta.url(corrida.id)">
                            <Download class="size-4" />
                            Descargar credenciales (CSV)
                        </a>
                    </Button>
                </div>
            </section>
        </template>

        <!-- Historial -->
        <section
            v-if="historial.length"
            class="rounded-2xl border border-border/60 bg-card p-4 sm:p-5"
        >
            <h2 class="mb-3 flex items-center gap-2 text-base font-semibold">
                <History class="size-4" />
                Corridas anteriores
            </h2>
            <ul class="flex flex-col divide-y divide-border/60 text-sm">
                <li v-for="h in historial" :key="h.id">
                    <Link
                        :href="index.url({ query: { corrida: h.id } })"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg px-2 py-2 hover:bg-muted/40"
                        :class="
                            corrida?.id === h.id
                                ? 'bg-primary/5 font-medium'
                                : ''
                        "
                    >
                        <span class="min-w-0 break-all"
                            >#{{ h.id }} · {{ h.archivo }}</span
                        >
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="colorEstado(h.estado)"
                            >{{ textoEstado(h.estado) }}</span
                        >
                        <span class="text-xs text-muted-foreground"
                            >{{ h.usuario ?? '—' }} ·
                            {{ fecha(h.creada_en) }}</span
                        >
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
