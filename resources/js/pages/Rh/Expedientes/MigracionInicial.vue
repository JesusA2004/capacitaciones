<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    DatabaseZap,
    Download,
    FileSpreadsheet,
    FolderSearch,
    History,
    Play,
    KeyRound,
    Loader2,
    Upload,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import {
    analizar,
    aplicar,
    decidir,
    estado as estadoRuta,
    index,
    credenciales as credencialesRuta,
    reporte,
} from '@/routes/rh/expedientes/migracion';

/**
 * Migración inicial de colaboradores y expedientes históricos
 * (docs/MIGRACION_INICIAL_EXPEDIENTES.md). Flujo obligatorio:
 * Excel → Analizar (no modifica nada) → Revisar → Resolver conflictos →
 * Confirmar → Ejecutar → Resultado.
 */
type Candidato = {
    ruta: string;
    carpeta: string;
    sucursal: string;
    score: number;
    pdfs: number;
};

type Fila = {
    fila: number;
    clave: string | null;
    nombre_completo: string | null;
    empresa_nombre: string | null;
    sucursal_excel: string | null;
    sucursal_nombre: string | null;
    puesto_excel: string | null;
    puesto_nombre: string | null;
    curp: string | null;
    operacion: 'crear' | 'actualizar' | 'sin_cambios' | 'conflicto' | 'omitir';
    colaborador_id: number | null;
    motivos: string[];
    advertencias: string[];
    cambios: Record<string, { antes: unknown; despues: unknown }>;
    nas: {
        tipo: 'exacto' | 'alto' | 'revision' | 'sin_match';
        score: number;
        carpeta?: string | null;
        ruta?: string;
        sucursal_carpeta?: string;
        pdfs?: { ruta: string; nombre: string; size: number }[];
        candidatos: Candidato[];
    };
};

type CarpetaSinPersona = {
    ruta: string;
    carpeta: string;
    sucursal_carpeta: string;
    sucursal_nombre: string | null;
    nombre_detectado: string | null;
    pdfs: { nombre: string }[];
    accion: 'historico' | 'pendiente' | 'vincular' | 'sin_pdf' | 'omitir';
    colaborador_id: number | null;
    colaborador_nombre: string | null;
};

type Migracion = {
    id: number;
    archivo: string;
    hash: string;
    estado: string;
    modo: string;
    totales: Record<string, number> | null;
    resultado: Record<string, number> | null;
    progreso: number;
    progreso_total: number;
    etapa: string | null;
    error: string | null;
    usuario: string | null;
    creada_en: string | null;
    terminada_en: string | null;
    decisiones: {
        filas?: Record<string, { ruta: string | null }>;
        carpetas?: Record<
            string,
            { accion: string; colaborador_id: number | null }
        >;
    } | null;
    plan: {
        origen: { modo: string; ruta: string; existe: boolean };
        filas: Fila[];
        carpetas_sin_persona: CarpetaSinPersona[];
        sucursales_no_autorizadas_nas: {
            carpeta: string;
            expedientes: number;
        }[];
        excel: { sin_reconocer: string[]; columnas: Record<string, string> };
    } | null;
};

const props = defineProps<{
    migracion: Migracion | null;
    historial: Migracion[];
    colaboradores: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        sucursal: string | null;
    }[];
    config: {
        origen: 'sitio' | 'legacy';
        ruta_origen: string;
        sucursales_permitidas: string[];
        sucursales_excluidas: string[];
    };
    /** Accesos generados al terminar (persona, sucursal, puesto, usuario, contraseña). */
    credenciales: {
        persona: string;
        numero_empleado: string | null;
        sucursal: string | null;
        puesto: string | null;
        usuario: string | null;
        contrasena: string | null;
        estado: string;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Expedientes', href: indexExpedientes() },
            { title: 'Migración inicial', href: index() },
        ],
    },
});

const { confirmarAccion } = useAlertas();

// --- Paso 1: Excel -----------------------------------------------------------
const formExcel = useForm({ archivo: null as File | null });
const arrastrando = ref(false);

function elegir(archivo: File | undefined | null) {
    formExcel.archivo = archivo ?? null;
}

function analizarExcel() {
    formExcel.post(analizar.url(), { forceFormData: true });
}

// --- Revisión -----------------------------------------------------------------
const plan = computed(() => props.migracion?.plan ?? null);
const totales = computed(() => props.migracion?.totales ?? {});
const filtro = ref<
    'todos' | 'conflicto' | 'revision' | 'sin_match' | 'omitir' | 'aplicables'
>('todos');
const busqueda = ref('');
const limite = ref(150);

const etiquetaOperacion: Record<Fila['operacion'], string> = {
    crear: 'Crear',
    actualizar: 'Actualizar',
    sin_cambios: 'Sin cambios',
    conflicto: 'Conflicto',
    omitir: 'Omitir',
};

const etiquetaMatch: Record<Fila['nas']['tipo'], string> = {
    exacto: 'Match exacto',
    alto: 'Match alto',
    revision: 'Revisión manual',
    sin_match: 'Sin match',
};

const filasFiltradas = computed(() => {
    const q = busqueda.value.trim().toLowerCase();

    return (plan.value?.filas ?? []).filter((f) => {
        const pasa =
            filtro.value === 'todos' ||
            (filtro.value === 'conflicto' && f.operacion === 'conflicto') ||
            (filtro.value === 'omitir' && f.operacion === 'omitir') ||
            (filtro.value === 'revision' && f.nas.tipo === 'revision') ||
            (filtro.value === 'sin_match' &&
                f.nas.tipo === 'sin_match' &&
                f.operacion !== 'omitir') ||
            (filtro.value === 'aplicables' &&
                ['crear', 'actualizar', 'sin_cambios'].includes(f.operacion));

        return (
            pasa &&
            (q === '' ||
                (f.nombre_completo ?? '').toLowerCase().includes(q) ||
                (f.curp ?? '').toLowerCase().includes(q))
        );
    });
});

function carpetaElegida(f: Fila): string | null {
    const d = props.migracion?.decisiones?.filas?.[String(f.fila)];

    if (d !== undefined) {
        return d.ruta;
    }

    return f.nas.tipo === 'exacto' || f.nas.tipo === 'alto'
        ? (f.nas.ruta ?? null)
        : null;
}

function elegirCarpeta(f: Fila, ruta: string | null) {
    if (!props.migracion) {
        return;
    }

    router.post(
        decidir.url(props.migracion.id),
        { filas: { [String(f.fila)]: { ruta: ruta ?? '' } } },
        { preserveScroll: true, preserveState: true },
    );
}

function accionCarpeta(c: CarpetaSinPersona): string {
    return props.migracion?.decisiones?.carpetas?.[c.ruta]?.accion ?? c.accion;
}

function colaboradorCarpeta(c: CarpetaSinPersona): number | null {
    return (
        props.migracion?.decisiones?.carpetas?.[c.ruta]?.colaborador_id ??
        c.colaborador_id
    );
}

function decidirCarpeta(
    c: CarpetaSinPersona,
    accion: string,
    colaboradorId: number | null = null,
) {
    if (!props.migracion) {
        return;
    }

    if (accion === 'vincular' && colaboradorId === null) {
        colaboradorId = colaboradorCarpeta(c);

        if (colaboradorId === null) {
            return;
        }
    }

    router.post(
        decidir.url(props.migracion.id),
        {
            carpetas: {
                [c.ruta]: { accion, colaborador_id: colaboradorId },
            },
        },
        { preserveScroll: true, preserveState: true },
    );
}

const opcionesColaborador = computed(() =>
    props.colaboradores.map((c) => ({
        value: c.id,
        label: `${c.nombre}${c.numero_empleado ? ` · ${c.numero_empleado}` : ''}${c.sucursal ? ` · ${c.sucursal}` : ''}`,
    })),
);

// --- Ejecutar -------------------------------------------------------------
const formAplicar = useForm({
    modo: 'copiar' as 'copiar' | 'mover',
    confirmacion: false,
});

const ejecutando = computed(() =>
    ['aplicando', 'en_cola'].includes(props.migracion?.estado ?? ''),
);
const terminada = computed(() =>
    ['completado', 'completado_con_errores', 'fallido'].includes(
        props.migracion?.estado ?? '',
    ),
);

async function ejecutar() {
    if (!props.migracion) {
        return;
    }

    const t = totales.value;

    if (
        !(await confirmarAccion(
            'Ejecutar migración',
            `Se crearán ${t.crear ?? 0} y actualizarán ${t.actualizar ?? 0} colaboradores, y se registrarán sus expedientes históricos. Las ${t.conflictos ?? 0} filas en conflicto y los matches en revisión sin decidir NO se aplican. Nunca se borra ni se sobrescribe nada.`,
            'Ejecutar',
        ))
    ) {
        return;
    }

    formAplicar.post(aplicar.url(props.migracion.id), {
        preserveScroll: true,
        onSuccess: () => iniciarSondeo(),
    });
}

// Avance en vivo mientras se ejecuta.
const avance = ref<{
    progreso: number;
    total: number;
    etapa: string | null;
    estado: string;
}>({
    progreso: props.migracion?.progreso ?? 0,
    total: props.migracion?.progreso_total ?? 0,
    etapa: props.migracion?.etapa ?? null,
    estado: props.migracion?.estado ?? '',
});
const segundosEnCola = ref(0);
let sondeo: ReturnType<typeof setInterval> | undefined;

function iniciarSondeo() {
    clearInterval(sondeo);
    segundosEnCola.value = 0;
    sondeo = setInterval(async () => {
        if (!props.migracion) {
            return;
        }

        const r = await fetch(estadoRuta.url(props.migracion.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const datos = (await r.json()) as { data: Migracion };
        avance.value = {
            progreso: datos.data.progreso,
            total: datos.data.progreso_total,
            etapa: datos.data.etapa,
            estado: datos.data.estado,
        };
        segundosEnCola.value =
            datos.data.estado === 'en_cola' ? segundosEnCola.value + 2 : 0;

        if (!['aplicando', 'en_cola'].includes(datos.data.estado)) {
            clearInterval(sondeo);
            router.reload({
                only: ['migracion', 'historial', 'credenciales'],
            });
        }
    }, 2000);
}

watch(
    () => props.migracion?.estado,
    (e) => {
        if (e === 'aplicando' || e === 'en_cola') {
            iniciarSondeo();
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(sondeo));

const porcentaje = computed(() =>
    avance.value.total > 0
        ? Math.round((avance.value.progreso / avance.value.total) * 100)
        : 0,
);

const pasoActual = computed(() => {
    if (!props.migracion) {
        return 1;
    }

    if (terminada.value) {
        return 6;
    }

    return ejecutando.value ? 5 : 3;
});

const pasos = [
    'Excel',
    'Análisis',
    'Revisión',
    'Conflictos',
    'Ejecutar',
    'Resultado',
];

function colorOperacion(op: Fila['operacion']): string {
    return {
        crear: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        actualizar: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
        sin_cambios: 'bg-muted text-muted-foreground',
        conflicto: 'bg-red-500/15 text-red-700 dark:text-red-300',
        omitir: 'bg-muted text-muted-foreground',
    }[op];
}

function colorMatch(t: Fila['nas']['tipo']): string {
    return {
        exacto: 'text-emerald-700 dark:text-emerald-300',
        alto: 'text-sky-700 dark:text-sky-300',
        revision: 'text-amber-700 dark:text-amber-300',
        sin_match: 'text-muted-foreground',
    }[t];
}
</script>

<template>
    <Head title="Migración inicial de expedientes" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Migración inicial de expedientes"
            descripcion="Carga la base general de colaboradores y vincula sus expedientes históricos del NAS. Primero se analiza (sin modificar nada); solo después de revisar y confirmar se ejecuta."
            :icono="DatabaseZap"
        />

        <!-- Pasos -->
        <ol class="flex flex-wrap gap-2 text-sm">
            <li
                v-for="(p, i) in pasos"
                :key="p"
                class="flex items-center gap-2 rounded-full border px-3 py-1"
                :class="
                    i + 1 === pasoActual
                        ? 'border-primary bg-primary/10 font-semibold text-primary'
                        : i + 1 < pasoActual
                          ? 'border-emerald-500/40 text-emerald-700 dark:text-emerald-300'
                          : 'border-border text-muted-foreground'
                "
            >
                <span>{{ i + 1 }}</span> {{ p }}
            </li>
        </ol>

        <div
            class="rounded-2xl border border-border/60 bg-muted/30 p-4 text-sm text-muted-foreground"
        >
            Origen de expedientes:
            <span class="font-medium text-foreground">{{
                config.origen === 'sitio'
                    ? 'ya colocados en su ubicación definitiva (solo se registran, no se copian)'
                    : 'árbol histórico (se copian con verificación SHA-256)'
            }}</span>
            — <code class="text-xs">{{ config.ruta_origen }}</code
            >. Sucursales autorizadas:
            {{ config.sucursales_permitidas.join(', ') }}. Excluidas:
            {{ config.sucursales_excluidas.join(', ') }}.
        </div>

        <!-- Paso 1: Excel -->
        <section class="rounded-2xl border border-border/60 bg-card p-5">
            <h2 class="mb-1 text-base font-semibold">
                1. Excel de la base general
            </h2>
            <p class="mb-4 text-sm text-muted-foreground">
                Archivo tipo MR_LANA_PEOPLE_BASE_GENERAL_MIGRACION.xlsx, hoja
                BASE_GENERAL. La hoja CONTACTOS_SIN_MATCH no se importa.
            </p>
            <label
                class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-8 text-center transition"
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
                <span class="text-sm font-medium">{{
                    formExcel.archivo
                        ? formExcel.archivo.name
                        : 'Arrastra el Excel aquí o haz clic para elegirlo'
                }}</span>
                <input
                    type="file"
                    accept=".xlsx,.xls"
                    class="hidden"
                    @change="
                        (e: Event) =>
                            elegir((e.target as HTMLInputElement).files?.[0])
                    "
                />
            </label>
            <InputError :message="formExcel.errors.archivo" class="mt-2" />
            <div class="mt-4 flex items-center gap-3">
                <Button
                    :disabled="!formExcel.archivo || formExcel.processing"
                    @click="analizarExcel"
                >
                    <Upload class="size-4" />
                    Analizar (no modifica nada)
                </Button>
                <div
                    v-if="formExcel.processing"
                    class="flex min-w-48 flex-1 flex-col gap-1"
                >
                    <Progress
                        :model-value="formExcel.progress?.percentage ?? 50"
                    />
                    <span class="text-xs text-muted-foreground"
                        >Leyendo Excel y revisando el NAS…</span
                    >
                </div>
            </div>
        </section>

        <template v-if="migracion && plan">
            <!-- Paso 3: Revisión -->
            <section class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold">
                        3. Revisión — {{ migracion.archivo }}
                    </h2>
                    <Button variant="outline" as-child>
                        <a :href="reporte.url(migracion.id)">
                            <Download class="size-4" />
                            Descargar reporte (CSV)
                        </a>
                    </Button>
                </div>
                <p
                    v-if="!plan.origen.existe"
                    class="rounded-xl bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200"
                >
                    No se encontró la carpeta de origen en el NAS ({{
                        plan.origen.ruta
                    }}): solo se importarán los colaboradores, sin expedientes.
                </p>
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <MetricCard
                        etiqueta="Colaboradores en Excel"
                        :valor="totales.filas ?? 0"
                        :icono="Users"
                    />
                    <MetricCard
                        etiqueta="Nuevos"
                        :valor="totales.crear ?? 0"
                        :icono="UserPlus"
                        color-clase="text-emerald-600"
                    />
                    <MetricCard
                        etiqueta="Actualizaciones"
                        :valor="totales.actualizar ?? 0"
                        :icono="History"
                    />
                    <MetricCard
                        etiqueta="Matches NAS"
                        :valor="
                            (totales.match_exacto ?? 0) +
                            (totales.match_alto ?? 0)
                        "
                        :icono="FolderSearch"
                    />
                    <MetricCard
                        etiqueta="Históricos / bajas"
                        :valor="
                            (totales.historicos ?? 0) +
                            (totales.pendientes_vincular ?? 0)
                        "
                        :icono="History"
                    />
                    <MetricCard
                        etiqueta="Conflictos"
                        :valor="
                            (totales.conflictos ?? 0) +
                            (totales.revision_manual ?? 0)
                        "
                        :icono="AlertTriangle"
                        color-clase="text-red-600"
                    />
                </div>
                <ul
                    v-if="
                        plan.sucursales_no_autorizadas_nas.length ||
                        plan.excel.sin_reconocer.length
                    "
                    class="flex flex-col gap-1 rounded-xl bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200"
                >
                    <li
                        v-for="s in plan.sucursales_no_autorizadas_nas"
                        :key="s.carpeta"
                    >
                        Carpeta «{{ s.carpeta }}» en el NAS no es una sucursal
                        autorizada ({{ s.expedientes }} carpetas): no se
                        vincula; revísala a mano.
                    </li>
                    <li v-if="plan.excel.sin_reconocer.length">
                        Columnas del Excel no reconocidas (se ignoran):
                        {{ plan.excel.sin_reconocer.join(', ') }}
                    </li>
                </ul>
            </section>

            <!-- Paso 4: tabla y conflictos -->
            <section class="rounded-2xl border border-border/60 bg-card">
                <div
                    class="flex flex-col gap-3 border-b border-border/60 p-4 lg:flex-row lg:items-center"
                >
                    <h2 class="text-base font-semibold">
                        4. Colaboradores y conflictos
                    </h2>
                    <div class="flex flex-wrap gap-2 lg:ml-auto">
                        <Input
                            v-model="busqueda"
                            placeholder="Buscar nombre o CURP"
                            class="w-56"
                        />
                        <SelectSimple
                            v-model="filtro"
                            :opciones="[
                                { value: 'todos', label: 'Todos' },
                                { value: 'aplicables', label: 'Se aplican' },
                                { value: 'conflicto', label: 'Conflictos' },
                                {
                                    value: 'revision',
                                    label: 'Revisión manual NAS',
                                },
                                { value: 'sin_match', label: 'Sin match NAS' },
                                { value: 'omitir', label: 'Omitidos' },
                            ]"
                            class="w-52"
                        />
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="p-3">Nombre</th>
                                <th class="p-3">Empresa</th>
                                <th class="p-3">Sucursal</th>
                                <th class="p-3">Puesto</th>
                                <th class="p-3">Estado importación</th>
                                <th class="p-3">Carpeta NAS</th>
                                <th class="p-3">PDF</th>
                                <th class="p-3">Tipo de match</th>
                                <th class="p-3">Conflictos / advertencias</th>
                                <th class="p-3">Operación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="f in filasFiltradas.slice(0, limite)"
                                :key="f.fila"
                                class="border-t border-border/60 align-top"
                            >
                                <td class="p-3">
                                    <p class="font-medium">
                                        {{ f.nombre_completo ?? '—' }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Fila {{ f.fila }} · Clave
                                        {{ f.clave ?? '—' }} ·
                                        {{ f.curp ?? 'sin CURP' }}
                                    </p>
                                </td>
                                <td class="p-3">
                                    {{ f.empresa_nombre ?? '—' }}
                                </td>
                                <td class="p-3">
                                    {{
                                        f.sucursal_nombre ??
                                        f.sucursal_excel ??
                                        '—'
                                    }}
                                </td>
                                <td class="p-3">
                                    {{
                                        f.puesto_nombre ?? f.puesto_excel ?? '—'
                                    }}
                                </td>
                                <td class="p-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="colorOperacion(f.operacion)"
                                        >{{
                                            etiquetaOperacion[f.operacion]
                                        }}</span
                                    >
                                </td>
                                <td class="p-3">
                                    <SelectSimple
                                        v-if="
                                            f.nas.candidatos.length &&
                                            f.operacion !== 'omitir'
                                        "
                                        :model-value="carpetaElegida(f)"
                                        opcion-vacia="Ninguna"
                                        :opciones="
                                            f.nas.candidatos.map((c) => ({
                                                value: c.ruta,
                                                label: `${c.sucursal} / ${c.carpeta} (${Math.round(c.score * 100)}%)`,
                                            }))
                                        "
                                        size="sm"
                                        class="min-w-56"
                                        @update:model-value="
                                            (v) =>
                                                elegirCarpeta(
                                                    f,
                                                    (v as string | null) ??
                                                        null,
                                                )
                                        "
                                    />
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="p-3">
                                    {{ f.nas.pdfs?.length ?? 0 }}
                                </td>
                                <td class="p-3">
                                    <span
                                        class="font-medium"
                                        :class="colorMatch(f.nas.tipo)"
                                        >{{ etiquetaMatch[f.nas.tipo] }}</span
                                    >
                                    <span
                                        v-if="f.nas.score"
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            Math.round(f.nas.score * 100)
                                        }}%</span
                                    >
                                </td>
                                <td class="max-w-sm p-3 text-xs">
                                    <p
                                        v-for="(m, i) in f.motivos"
                                        :key="`m${i}`"
                                        class="text-red-700 dark:text-red-300"
                                    >
                                        {{ m }}
                                    </p>
                                    <p
                                        v-for="(a, i) in f.advertencias"
                                        :key="`a${i}`"
                                        class="text-amber-700 dark:text-amber-300"
                                    >
                                        {{ a }}
                                    </p>
                                </td>
                                <td class="p-3 text-xs">
                                    <template
                                        v-if="f.operacion === 'actualizar'"
                                    >
                                        Actualiza:
                                        {{ Object.keys(f.cambios).join(', ') }}
                                    </template>
                                    <template
                                        v-else-if="f.operacion === 'crear'"
                                        >Alta nueva</template
                                    >
                                    <template
                                        v-else-if="f.operacion === 'conflicto'"
                                        >No se aplica</template
                                    >
                                    <template
                                        v-else-if="f.operacion === 'omitir'"
                                        >Se omite</template
                                    >
                                    <template v-else>Ya está igual</template>
                                    <span
                                        v-if="
                                            carpetaElegida(f) &&
                                            f.operacion !== 'conflicto'
                                        "
                                        class="block text-muted-foreground"
                                        >+ vincula expediente histórico</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="filasFiltradas.length > limite"
                    class="border-t border-border/60 p-3 text-center"
                >
                    <Button variant="ghost" size="sm" @click="limite += 150">
                        Mostrar más ({{ filasFiltradas.length - limite }})
                    </Button>
                </div>
            </section>

            <!-- Carpetas del NAS sin persona en el Excel -->
            <section
                v-if="plan.carpetas_sin_persona.length"
                class="rounded-2xl border border-border/60 bg-card"
            >
                <div class="border-b border-border/60 p-4">
                    <h2 class="text-base font-semibold">
                        Expedientes del NAS que no vienen en el Excel
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Normalmente son bajas. Nunca se borran: se conservan
                        como histórico (colaborador de baja solo con nombre y
                        sucursal) o pendientes de vincular (p. ej. para un
                        reingreso).
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="p-3">Sucursal</th>
                                <th class="p-3">Carpeta</th>
                                <th class="p-3">Nombre detectado</th>
                                <th class="p-3">PDF</th>
                                <th class="p-3">Qué hacer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="c in plan.carpetas_sin_persona"
                                :key="c.ruta"
                                class="border-t border-border/60"
                            >
                                <td class="p-3">
                                    {{
                                        c.sucursal_nombre ?? c.sucursal_carpeta
                                    }}
                                </td>
                                <td class="p-3">{{ c.carpeta }}</td>
                                <td class="p-3">
                                    {{ c.nombre_detectado ?? '—' }}
                                </td>
                                <td class="p-3">{{ c.pdfs.length }}</td>
                                <td class="flex flex-wrap gap-2 p-3">
                                    <SelectSimple
                                        :model-value="accionCarpeta(c)"
                                        :opciones="[
                                            {
                                                value: 'historico',
                                                label: 'Histórico (baja)',
                                            },
                                            {
                                                value: 'pendiente',
                                                label: 'Pendiente de vincular',
                                            },
                                            {
                                                value: 'vincular',
                                                label: 'Vincular a colaborador',
                                            },
                                            {
                                                value: 'omitir',
                                                label: 'Dejar sin registrar',
                                            },
                                        ]"
                                        size="sm"
                                        class="w-48"
                                        :disabled="c.accion === 'sin_pdf'"
                                        @update:model-value="
                                            (v) =>
                                                v !== 'vincular'
                                                    ? decidirCarpeta(
                                                          c,
                                                          String(v),
                                                      )
                                                    : undefined
                                        "
                                    />
                                    <SelectSimple
                                        v-if="
                                            accionCarpeta(c) === 'vincular' ||
                                            c.colaborador_id
                                        "
                                        :model-value="colaboradorCarpeta(c)"
                                        :opciones="opcionesColaborador"
                                        placeholder="Elige colaborador"
                                        size="sm"
                                        class="w-72"
                                        @update:model-value="
                                            (v) =>
                                                decidirCarpeta(
                                                    c,
                                                    'vincular',
                                                    Number(v),
                                                )
                                        "
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Paso 5: Ejecutar -->
            <section
                v-if="!terminada"
                class="rounded-2xl border border-primary/40 bg-card p-5"
            >
                <h2 class="mb-1 text-base font-semibold">5. Ejecutar</h2>
                <p class="mb-4 text-sm text-muted-foreground">
                    Solo se aplican filas sin conflicto y carpetas con match
                    exacto/alto o elegidas por ti. Nunca se borra ni se
                    sobrescribe nada; volver a ejecutar no duplica.
                </p>
                <div v-if="ejecutando" class="flex flex-col gap-2">
                    <Progress :model-value="porcentaje" />
                    <span class="text-sm text-muted-foreground"
                        >Ejecutando… {{ avance.progreso }} /
                        {{ avance.total }}</span
                    >
                </div>
                <div v-else class="flex flex-col gap-3">
                    <SelectSimple
                        v-if="plan.origen.modo === 'legacy'"
                        v-model="formAplicar.modo"
                        :opciones="[
                            {
                                value: 'copiar',
                                label: 'Copiar y conservar origen (recomendado)',
                            },
                            {
                                value: 'mover',
                                label: 'Mover (borra el origen tras verificar)',
                            },
                        ]"
                        class="max-w-md"
                    />
                    <label class="flex items-center gap-2 text-sm">
                        <Casilla v-model="formAplicar.confirmacion" />
                        Revisé el análisis y los conflictos; confirmo la
                        ejecución.
                    </label>
                    <InputError :message="formAplicar.errors.confirmacion" />
                    <Button
                        class="self-start"
                        :disabled="
                            !formAplicar.confirmacion || formAplicar.processing
                        "
                        @click="ejecutar"
                    >
                        <Play class="size-4" />
                        Ejecutar migración
                    </Button>
                </div>
            </section>

            <!-- Paso 6: Resultado -->
            <section
                v-if="terminada && migracion.resultado"
                class="rounded-2xl border border-emerald-500/40 bg-card p-5"
            >
                <h2
                    class="mb-3 flex items-center gap-2 text-base font-semibold"
                >
                    <CheckCircle2 class="size-5 text-emerald-600" />
                    6. Resultado ({{ migracion.estado }})
                </h2>
                <dl class="grid gap-2 text-sm sm:grid-cols-3 lg:grid-cols-4">
                    <div
                        v-for="(v, k) in migracion.resultado"
                        :key="k"
                        class="rounded-lg bg-muted/40 p-2"
                    >
                        <dt class="text-xs text-muted-foreground">
                            {{ String(k).replace(/_/g, ' ') }}
                        </dt>
                        <dd class="text-lg font-semibold">{{ v }}</dd>
                    </div>
                </dl>
                <p v-if="migracion.error" class="mt-3 text-sm text-red-600">
                    {{ migracion.error }}
                </p>
                <Button variant="outline" as-child class="mt-4">
                    <a :href="reporte.url(migracion.id)">
                        <Download class="size-4" />
                        Descargar reporte de la migración
                    </a>
                </Button>
            </section>

            <!-- Accesos generados para compartir -->
            <section
                v-if="terminada && credenciales.length"
                class="rounded-2xl border border-border/60 bg-card"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 p-4"
                >
                    <div>
                        <h2
                            class="flex items-center gap-2 text-base font-semibold"
                        >
                            <KeyRound class="size-5" />
                            Accesos de colaboradores
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Usuario = su correo. La contraseña es temporal:
                            compártela de forma privada. Quien no tiene correo
                            en el Excel queda sin cuenta (no se inventa uno).
                        </p>
                    </div>
                    <Button as-child>
                        <a :href="credencialesRuta.url(migracion.id)">
                            <Download class="size-4" />
                            Descargar lista (CSV)
                        </a>
                    </Button>
                </div>
                <div class="max-h-[32rem] overflow-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="sticky top-0 bg-muted text-left text-xs text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="p-3">Persona</th>
                                <th class="p-3">Sucursal</th>
                                <th class="p-3">Puesto</th>
                                <th class="p-3">Usuario</th>
                                <th class="p-3">Contraseña</th>
                                <th class="p-3">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(c, i) in credenciales"
                                :key="i"
                                class="border-t border-border/60"
                            >
                                <td class="p-3">
                                    {{ c.persona }}
                                    <span
                                        v-if="c.numero_empleado"
                                        class="block text-xs text-muted-foreground"
                                        >{{ c.numero_empleado }}</span
                                    >
                                </td>
                                <td class="p-3">{{ c.sucursal ?? '—' }}</td>
                                <td class="p-3">{{ c.puesto ?? '—' }}</td>
                                <td class="p-3 break-all">
                                    {{ c.usuario ?? '—' }}
                                </td>
                                <td class="p-3 font-mono">
                                    {{ c.contrasena ?? '—' }}
                                </td>
                                <td class="p-3 text-xs">{{ c.estado }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <!-- Pantalla de carga mientras el Job trabaja -->
        <div
            v-if="ejecutando"
            class="fixed inset-0 z-50 flex items-center justify-center bg-background/80 p-4 backdrop-blur-sm"
            role="status"
            aria-live="polite"
        >
            <div
                class="flex w-full max-w-md flex-col items-center gap-4 rounded-2xl border border-border/60 bg-card p-8 text-center shadow-xl"
            >
                <Loader2 class="size-10 animate-spin text-primary" />
                <div>
                    <p class="text-lg font-semibold">
                        Migrando colaboradores y expedientes…
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            avance.estado === 'en_cola'
                                ? 'En cola, iniciando…'
                                : (avance.etapa ?? 'Procesando')
                        }}
                    </p>
                </div>
                <Progress :model-value="porcentaje" class="w-full" />
                <p class="text-sm text-muted-foreground">
                    {{ avance.progreso }} / {{ avance.total }} ({{
                        porcentaje
                    }}%)
                </p>
                <p class="text-xs text-muted-foreground">
                    Se ejecuta en segundo plano: puedes cerrar esta pestaña y
                    volver después; el avance se conserva.
                </p>
                <p
                    v-if="segundosEnCola > 30"
                    class="text-xs text-amber-700 dark:text-amber-300"
                >
                    Sigue en cola. Verifica que el servidor tenga corriendo
                    <code>php artisan queue:work</code>.
                </p>
            </div>
        </div>

        <!-- Historial -->
        <section
            v-if="historial.length"
            class="rounded-2xl border border-border/60 bg-card p-5"
        >
            <h2 class="mb-3 text-base font-semibold">Ejecuciones anteriores</h2>
            <ul class="flex flex-col gap-2 text-sm">
                <li
                    v-for="h in historial"
                    :key="h.id"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span>
                        #{{ h.id }} · {{ h.archivo }} · {{ h.estado }} ·
                        {{ h.usuario ?? '—' }} ·
                        {{
                            h.creada_en
                                ? new Date(h.creada_en).toLocaleString('es-MX')
                                : ''
                        }}
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="router.get(index.url(), { migracion: h.id })"
                        >Abrir</Button
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
