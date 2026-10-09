<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Banknote,
    BrainCircuit,
    CalendarClock,
    ClipboardCheck,
    Columns3,
    FileSignature,
    Filter,
    House,
    LayoutGrid,
    MessagesSquare,
    Percent,
    Plus,
    Target,
    Trophy,
    UserCheck2,
    UserMinus,
    UserRound,
    UserX,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import CandidatoFormDialog from '@/components/Rh/CandidatoFormDialog.vue';
import CandidatoTarjeta from '@/components/Rh/CandidatoTarjeta.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    estado as estadoUrl,
    exportarExcel,
    exportarPdf,
    index,
    show,
} from '@/routes/rh/candidatos';
import type {
    CandidatoItem,
    CandidatosKpis,
    OpcionesReclutamiento,
} from '@/types';

const props = defineProps<{
    candidatos: CandidatoItem[];
    filtros: {
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_objetivo_id?: string;
        vacante_id?: string;
        responsable_rh_id?: string;
        fuente?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
        mes?: string;
    };
    opciones: OpcionesReclutamiento;
    kpis: CandidatosKpis;
}>();

const MESES_NOMBRE = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

const opcionesMes = computed(() => {
    const hoy = new Date();
    const opciones: { value: string; etiqueta: string }[] = [];

    for (let i = 0; i < 12; i++) {
        const fecha = new Date(hoy.getFullYear(), hoy.getMonth() - i, 1);
        const valor = `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}`;
        opciones.push({
            value: valor,
            etiqueta: `${MESES_NOMBRE[fecha.getMonth()]} ${fecha.getFullYear()}`,
        });
    }

    return opciones;
});

const pipelineKpi = computed(() => [
    {
        etiqueta: 'Recibidos en el periodo',
        valor: props.kpis.recibidos_periodo,
        icono: Users,
    },
    {
        etiqueta: 'En proceso',
        valor: props.kpis.en_proceso,
        icono: UserRound,
        tono: 'info' as const,
    },
    {
        etiqueta: 'Finalistas',
        valor: props.kpis.finalistas,
        icono: Trophy,
        tono: 'warning' as const,
    },
    {
        etiqueta: 'Contratados en el periodo',
        valor: props.kpis.contratados_periodo,
        icono: UserCheck2,
        tono: 'success' as const,
    },
]);

const resultadosKpi = computed(() => [
    {
        etiqueta: 'Tasa de conversión',
        valor: `${Math.round(props.kpis.tasa_conversion * 1000) / 10}%`,
        icono: Percent,
    },
    {
        etiqueta: 'Tiempo promedio de contratación',
        valor:
            props.kpis.tiempo_promedio_contratacion_dias !== null
                ? `${props.kpis.tiempo_promedio_contratacion_dias} días`
                : '—',
        icono: CalendarClock,
    },
]);

const costosKpi = computed(() => {
    if (props.kpis.gasto_reclutamiento_periodo === undefined) {
        return [];
    }

    return [
        {
            etiqueta: 'Gasto de reclutamiento',
            valor: formatoMoneda(props.kpis.gasto_reclutamiento_periodo),
            icono: Banknote,
        },
        {
            etiqueta: 'Costo por candidato',
            valor: formatoMoneda(props.kpis.costo_por_candidato ?? 0),
            icono: Target,
        },
        {
            etiqueta: 'Costo por contratación',
            valor: formatoMoneda(props.kpis.costo_por_contratacion ?? 0),
            icono: Banknote,
            tono: 'warning' as const,
        },
    ];
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Candidatos', href: index.url() },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        departamento_id: props.filtros.departamento_id ?? '',
        puesto_objetivo_id: props.filtros.puesto_objetivo_id ?? '',
        vacante_id: props.filtros.vacante_id ?? '',
        responsable_rh_id: props.filtros.responsable_rh_id ?? '',
        fuente: props.filtros.fuente ?? '',
        busqueda: props.filtros.busqueda ?? '',
        fecha_inicio: props.filtros.fecha_inicio ?? '',
        fecha_fin: props.filtros.fecha_fin ?? '',
        mes: props.filtros.mes ?? opcionesMes.value[0].value,
    },
);
const filtroSheetAbierto = ref(false);

function urlExportar(
    destino: typeof exportarExcel | typeof exportarPdf,
): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}
const { mostrarError } = useAlertas();

// Columnas canónicas del tablero (CLAUDE.md §4): nunca los 16 sub-estados
// técnicos — esos solo viven en el detalle y en el historial.
const COLUMNAS = props.opciones.fases ?? [];
const transicionesPermitidas = props.opciones.transicionesPermitidas ?? {};
const estadoAFase = Object.fromEntries(
    (props.opciones.estados ?? []).map((e) => [e.value, e.fase]),
);

const columnas = computed(() =>
    COLUMNAS.map((columna) => ({
        ...columna,
        candidatos: props.candidatos.filter((c) => c.fase === columna.value),
    })),
);

// Identidad visual de cada fase (paleta People): ícono + color de acento.
const ESTILO_FASE: Record<string, { icono: typeof Users; color: string }> = {
    filtro_rh: { icono: Filter, color: '#2b7a6e' },
    entrevista: { icono: MessagesSquare, color: '#3f7f4b' },
    psicometricos: { icono: BrainCircuit, color: '#af8b51' },
    socioeconomico: { icono: House, color: '#a3702c' },
    contratacion: { icono: FileSignature, color: '#1f6f78' },
    contratado: { icono: ClipboardCheck, color: '#4a8f57' },
    rechazado: { icono: UserX, color: '#c8414d' },
    desistido: { icono: UserMinus, color: '#7a8a86' },
};
const estiloFase = (fase: string) =>
    ESTILO_FASE[fase] ?? { icono: UserRound, color: '#2b7a6e' };

const FASES_SALIDA = ['rechazado', 'desistido'];
const fasesRecorrido = computed(() =>
    columnas.value.filter((c) => !FASES_SALIDA.includes(c.value)),
);
const fasesSalida = computed(() =>
    columnas.value.filter((c) => FASES_SALIDA.includes(c.value)),
);
const maxEnFase = computed(() =>
    Math.max(1, ...fasesRecorrido.value.map((c) => c.candidatos.length)),
);

// Vista: «Por fase» (tarjetas de una fase) o «Tablero» (todas las columnas).
const vista = ref<'fase' | 'tablero'>('fase');
const faseSeleccionada = ref<string>(
    columnas.value.find(
        (c) =>
            ![...FASES_SALIDA, 'contratado'].includes(c.value) &&
            c.candidatos.length > 0,
    )?.value ??
        COLUMNAS[0]?.value ??
        'filtro_rh',
);
const columnaSeleccionada = computed(() =>
    columnas.value.find((c) => c.value === faseSeleccionada.value),
);
const faseActiva = (fase: string) =>
    vista.value === 'fase' && faseSeleccionada.value === fase;

function elegirFase(fase: string) {
    faseSeleccionada.value = fase;
    vista.value = 'fase';
}


/** Destinos (sub-estados) válidos desde el estado actual que caen en esa fase. */
function destinosDeFase(estadoOrigen: string, fase: string): string[] {
    return (transicionesPermitidas[estadoOrigen] ?? []).filter(
        (destino) => estadoAFase[destino] === fase,
    );
}

const dialogoAbierto = ref(false);
const seleccionado = ref<CandidatoItem | null>(null);

function abrirCrear() {
    seleccionado.value = null;
    dialogoAbierto.value = true;
}

const arrastrando = ref<CandidatoItem | null>(null);

function columnaPermitida(valorColumna: string): boolean {
    if (!arrastrando.value) {
        return true;
    }

    if (arrastrando.value.fase === valorColumna) {
        return true;
    }

    return destinosDeFase(arrastrando.value.estado, valorColumna).length > 0;
}

function alSoltar(nuevaFase: string) {
    const candidato = arrastrando.value;
    arrastrando.value = null;

    if (!candidato || candidato.fase === nuevaFase) {
        return;
    }

    const destinos = destinosDeFase(candidato.estado, nuevaFase);

    if (destinos.length === 0) {
        mostrarError(
            'Desde el tablero solo puedes cerrar el proceso. Para avanzar, abre la ficha del candidato y usa la acción que corresponde.',
        );

        return;
    }

    // Cerrar el proceso exige motivo (queda en la línea de tiempo). Si la
    // fase agrupa más de un sub-estado de salida (p. ej. «Rechazado» cubre
    // varios motivos técnicos), RH elige cuál antes de confirmar.
    salidaPendiente.value = { candidato, estado: destinos[0], opciones: destinos };
    motivoSalida.value = '';
    motivoRechazoId.value = null;
    recontratable.value = true;
}

const salidaPendiente = ref<{
    candidato: CandidatoItem;
    estado: string;
    opciones: string[];
} | null>(null);
const motivoSalida = ref('');
const motivoRechazoId = ref<number | null>(null);
const recontratable = ref(true);

function alElegirMotivoRechazo(id: number | null) {
    motivoRechazoId.value = id;
    const motivo = props.opciones.motivosRechazo?.find((m) => m.id === id);
    recontratable.value = motivo ? !motivo.no_recontratable_por_defecto : true;
}

function confirmarSalida() {
    const pendiente = salidaPendiente.value;

    if (!pendiente || motivoRechazoId.value === null) {
        return;
    }

    const motivoCatalogo = props.opciones.motivosRechazo?.find(
        (m) => m.id === motivoRechazoId.value,
    );

    router.put(
        estadoUrl.url(pendiente.candidato.id),
        {
            estado: pendiente.estado,
            nota: motivoSalida.value.trim() || motivoCatalogo?.nombre || '',
            motivo_rechazo_id: motivoRechazoId.value,
            recontratable: recontratable.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (salidaPendiente.value = null),
            onError: () =>
                mostrarError(
                    'No tienes permiso para cerrar el proceso de este candidato.',
                ),
        },
    );
}
</script>

<template>
    <Head title="Candidatos" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Candidatos"
            descripcion="Seguimiento de prospectos y candidatos en proceso de reclutamiento."
            :icono="UserRound"
        >
            <CrudExportButtons
                :url-excel="urlExportar(exportarExcel)"
                :url-pdf="urlExportar(exportarPdf)"
            />
            <Button data-tour="candidatos-nuevo" @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo candidato
            </Button>
        </CrudPageHeader>

        <div data-tour="candidatos-kpis" class="flex flex-col gap-4">
            <CrudStats :estadisticas="pipelineKpi" />
            <CrudStats :estadisticas="[...resultadosKpi, ...costosKpi]" />
        </div>

        <div
            data-tour="candidatos-filtros"
            class="flex flex-wrap items-center gap-2"
        >
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre o correo..."
                @update:model-value="
                    (v) => {
                        filtros.busqueda = v;
                        aplicarConDebounce();
                    }
                "
            />

            <Select
                :model-value="filtros.mes"
                @update:model-value="
                    (v) => {
                        filtros.mes = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44"
                    ><SelectValue placeholder="Mes"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opcionesMes"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.fuente"
                @update:model-value="
                    (v) => {
                        filtros.fuente = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44"
                    ><SelectValue placeholder="Todas las fuentes"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.fuentes"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.empresa_id"
                @update:model-value="
                    (v) => {
                        filtros.empresa_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todas las empresas"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.empresas"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.sucursal_id"
                @update:model-value="
                    (v) => {
                        filtros.sucursal_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todas las sucursales"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.sucursales"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.puesto_objetivo_id"
                @update:model-value="
                    (v) => {
                        filtros.puesto_objetivo_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todos los puestos"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.puestos"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Departamento, vacante, responsable y fecha de registro."
                :contador-activos="
                    [
                        filtros.departamento_id,
                        filtros.vacante_id,
                        filtros.responsable_rh_id,
                        filtros.fecha_inicio,
                        filtros.fecha_fin,
                    ].filter(Boolean).length
                "
                :open="filtroSheetAbierto"
                @update:open="(v) => (filtroSheetAbierto = v)"
                @aplicar="aplicar"
                @limpiar="limpiar"
            >
                <div class="grid gap-2">
                    <Label>Departamento</Label>
                    <Select
                        :model-value="filtros.departamento_id"
                        @update:model-value="
                            (v) => (filtros.departamento_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.departamentos"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Responsable RH</Label>
                    <Select
                        :model-value="filtros.responsable_rh_id"
                        @update:model-value="
                            (v) => (filtros.responsable_rh_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.responsables"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.name }}
                                {{ opcion.apellidos }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label>Registrado desde</Label>
                        <DatePicker v-model="filtros.fecha_inicio" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Registrado hasta</Label>
                        <DatePicker v-model="filtros.fecha_fin" />
                    </div>
                </div>
            </CrudFilterSheet>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <!-- Recorrido por fases: el embudo de un vistazo y acceso a cada fase. -->
        <section
            data-tour="candidatos-tablero"
            class="overflow-hidden rounded-3xl border border-border/60 bg-card shadow-sm"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 bg-gradient-to-r from-crema/70 to-salvia/70 px-5 py-4 text-foreground"
            >
                <div>
                    <p
                        class="text-xs font-medium tracking-wider text-bronce uppercase"
                    >
                        Recorrido del candidato
                    </p>
                    <p class="text-lg font-semibold">
                        {{ kpis.en_proceso }} en proceso ·
                        {{ kpis.contratados_periodo }} contratados en el periodo
                    </p>
                </div>
                <div class="hidden rounded-xl bg-card/70 p-1 md:flex">
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            vista === 'fase'
                                ? 'bg-card text-primary shadow'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="vista = 'fase'"
                    >
                        <LayoutGrid class="size-4" /> Por fase
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            vista === 'tablero'
                                ? 'bg-card text-primary shadow'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="vista = 'tablero'"
                    >
                        <Columns3 class="size-4" /> Tablero
                    </button>
                </div>
            </div>

            <div class="flex items-stretch gap-4 overflow-x-auto px-5 py-5">
                <ol class="flex min-w-max flex-1 items-start">
                    <li
                        v-for="(fase, i) in fasesRecorrido"
                        :key="fase.value"
                        class="relative flex min-w-[7.5rem] flex-1 flex-col items-center"
                    >
                        <span
                            v-if="i < fasesRecorrido.length - 1"
                            class="absolute top-6 left-1/2 h-0.5 w-full bg-border"
                            aria-hidden="true"
                        />
                        <button
                            type="button"
                            class="group relative z-10 flex flex-col items-center gap-1.5 rounded-2xl px-2 pb-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-pressed="faseActiva(fase.value)"
                            @click="elegirFase(fase.value)"
                        >
                            <span
                                class="relative flex size-12 items-center justify-center rounded-full border-2 bg-card transition group-hover:scale-105"
                                :style="{
                                    borderColor: estiloFase(fase.value).color,
                                    backgroundColor: faseActiva(fase.value)
                                        ? estiloFase(fase.value).color
                                        : undefined,
                                    color: faseActiva(fase.value)
                                        ? '#ffffff'
                                        : estiloFase(fase.value).color,
                                }"
                            >
                                <component
                                    :is="estiloFase(fase.value).icono"
                                    class="size-5"
                                />
                                <span
                                    class="absolute -top-1.5 -right-1.5 flex min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] leading-5 font-bold text-white shadow"
                                    :style="{
                                        backgroundColor:
                                            estiloFase(fase.value).color,
                                    }"
                                    >{{ fase.candidatos.length }}</span
                                >
                            </span>
                            <span
                                class="text-[11px] font-medium text-muted-foreground"
                                >Fase {{ i + 1 }}</span
                            >
                            <span class="text-sm font-semibold">{{
                                fase.etiqueta
                            }}</span>
                            <span
                                class="h-1.5 w-16 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full rounded-full transition-all"
                                    :style="{
                                        width: `${(fase.candidatos.length / maxEnFase) * 100}%`,
                                        backgroundColor:
                                            estiloFase(fase.value).color,
                                    }"
                                />
                            </span>
                        </button>
                    </li>
                </ol>

                <div
                    class="flex shrink-0 flex-col justify-center gap-2 border-l border-border/60 pl-4"
                >
                    <span
                        class="text-[11px] font-medium tracking-wider text-muted-foreground uppercase"
                        >Cerrados</span
                    >
                    <button
                        v-for="fase in fasesSalida"
                        :key="fase.value"
                        type="button"
                        class="flex items-center gap-2 rounded-xl border px-3 py-1.5 text-sm transition hover:bg-muted"
                        :class="
                            faseActiva(fase.value)
                                ? 'border-foreground/40 bg-muted'
                                : 'border-border/60'
                        "
                        @click="elegirFase(fase.value)"
                    >
                        <component
                            :is="estiloFase(fase.value).icono"
                            class="size-4"
                            :style="{ color: estiloFase(fase.value).color }"
                        />
                        <span class="font-medium">{{ fase.etiqueta }}</span>
                        <span
                            class="ml-auto rounded-full bg-muted px-2 text-xs font-semibold"
                            >{{ fase.candidatos.length }}</span
                        >
                    </button>
                </div>
            </div>
        </section>

        <!-- «Por fase»: tarjetas de la fase elegida (siempre así en móvil). -->
        <section
            v-if="columnaSeleccionada"
            class="flex flex-col gap-4"
            :class="vista === 'tablero' && 'md:hidden'"
        >
            <div class="flex items-center gap-3">
                <span
                    class="flex size-10 items-center justify-center rounded-xl text-white"
                    :style="{
                        backgroundColor: estiloFase(columnaSeleccionada.value)
                            .color,
                    }"
                >
                    <component
                        :is="estiloFase(columnaSeleccionada.value).icono"
                        class="size-5"
                    />
                </span>
                <div>
                    <h2 class="text-lg leading-tight font-semibold">
                        {{ columnaSeleccionada.etiqueta }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ columnaSeleccionada.candidatos.length }}
                        {{
                            columnaSeleccionada.candidatos.length === 1
                                ? 'candidato'
                                : 'candidatos'
                        }}
                        en esta fase
                    </p>
                </div>
            </div>

            <div
                v-if="columnaSeleccionada.candidatos.length"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
            >
                <div
                    v-for="candidato in columnaSeleccionada.candidatos"
                    :key="candidato.id"
                    class="relative cursor-pointer overflow-hidden rounded-2xl border border-border/60 bg-card p-4 pt-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                    @click="router.visit(show.url(candidato.id))"
                >
                    <span
                        class="absolute inset-x-0 top-0 h-1"
                        :style="{
                            backgroundColor: estiloFase(candidato.fase).color,
                        }"
                        aria-hidden="true"
                    />
                    <CandidatoTarjeta
                        :candidato="candidato"
                        :fuentes="opciones.fuentes"
                    />
                </div>
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-border p-10 text-center"
            >
                <component
                    :is="estiloFase(columnaSeleccionada.value).icono"
                    class="size-8 text-muted-foreground/60"
                />
                <p class="text-sm text-muted-foreground">
                    Nadie en esta fase por ahora.
                </p>
            </div>
        </section>

        <!-- «Tablero»: todas las fases; arrastra a Rechazado/Desistió para cerrar. -->
        <div
            v-if="vista === 'tablero'"
            class="hidden items-start gap-4 overflow-x-auto pb-4 md:flex"
        >
            <div
                v-for="columna in columnas"
                :key="columna.value"
                class="flex w-72 shrink-0 flex-col overflow-hidden rounded-2xl border border-border/60 bg-muted/30 transition-opacity"
                :class="
                    !columnaPermitida(columna.value) &&
                    'pointer-events-none opacity-30'
                "
                @dragover.prevent
                @drop="alSoltar(columna.value)"
            >
                <div
                    class="flex items-center gap-2 px-3 py-2.5 text-white"
                    :style="{
                        backgroundColor: estiloFase(columna.value).color,
                    }"
                >
                    <component
                        :is="estiloFase(columna.value).icono"
                        class="size-4"
                    />
                    <h3 class="flex-1 text-sm font-semibold">
                        {{ columna.etiqueta }}
                    </h3>
                    <span
                        class="rounded-full bg-white/20 px-2 text-xs font-bold"
                        >{{ columna.candidatos.length }}</span
                    >
                </div>

                <div class="flex flex-col gap-2 p-3">
                    <div
                        v-for="candidato in columna.candidatos"
                        :key="candidato.id"
                        draggable="true"
                        class="cursor-pointer rounded-xl border border-border/60 bg-card p-3 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                        @click="router.visit(show.url(candidato.id))"
                        @dragstart="arrastrando = candidato"
                        @dragend="arrastrando = null"
                    >
                        <CandidatoTarjeta
                            :candidato="candidato"
                            :fuentes="opciones.fuentes"
                        />
                    </div>

                    <p
                        v-if="!columna.candidatos.length"
                        class="rounded-xl border border-dashed p-4 text-center text-xs text-muted-foreground"
                    >
                        Sin candidatos
                    </p>
                </div>
            </div>
        </div>
    </div>

    <CandidatoFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :candidato="seleccionado"
        :opciones="opciones"
        :key="seleccionado?.id ?? 'nuevo'"
    />

    <Dialog
        :open="salidaPendiente !== null"
        @update:open="
            (abierto: boolean) => !abierto && (salidaPendiente = null)
        "
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Cerrar proceso del candidato</DialogTitle>
                <DialogDescription>
                    {{ salidaPendiente?.candidato.nombre }} ·
                    {{
                        opciones.estados.find(
                            (e) => e.value === salidaPendiente?.estado,
                        )?.etiqueta
                    }}
                </DialogDescription>
            </DialogHeader>
            <div v-if="(salidaPendiente?.opciones.length ?? 0) > 1" class="grid gap-1.5">
                <Label>Motivo específico</Label>
                <Select
                    :model-value="salidaPendiente?.estado"
                    @update:model-value="
                        (v) => {
                            if (salidaPendiente) salidaPendiente.estado = String(v ?? '');
                        }
                    "
                >
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="valor in salidaPendiente?.opciones ?? []"
                            :key="valor"
                            :value="valor"
                            >{{
                                opciones.estados.find((e) => e.value === valor)
                                    ?.etiqueta
                            }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label>Motivo *</Label>
                <Select
                    :model-value="motivoRechazoId ? String(motivoRechazoId) : undefined"
                    @update:model-value="
                        (v) => alElegirMotivoRechazo(v ? Number(v) : null)
                    "
                >
                    <SelectTrigger
                        ><SelectValue placeholder="Elige un motivo"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="motivo in opciones.motivosRechazo ?? []"
                            :key="motivo.id"
                            :value="String(motivo.id)"
                            >{{ motivo.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label for="motivo-salida">Comentario (opcional)</Label>
                <Textarea id="motivo-salida" v-model="motivoSalida" rows="3" />
            </div>
            <label class="flex items-center gap-2 text-sm">
                <Casilla v-model="recontratable" />
                ¿Puede ser considerado de nuevo?
            </label>
            <DialogFooter>
                <Button variant="ghost" @click="salidaPendiente = null"
                    >Cancelar</Button
                >
                <Button
                    variant="destructive"
                    :disabled="motivoRechazoId === null"
                    @click="confirmarSalida"
                    >Cerrar proceso</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
