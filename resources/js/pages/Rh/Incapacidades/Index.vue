<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, HeartPulse, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useFiltros } from '@/composables/useFiltros';
import { formatearFecha } from '@/lib/fechas';
import { opcionesDe } from '@/lib/opcionesSelect';
import {
    create,
    exportarExcel,
    exportarPdf,
    historial,
    index,
    show,
} from '@/routes/rh/incapacidades';
import {
    aplicar as aplicarRectificacion,
    descartar as descartarRectificacion,
} from '@/routes/rh/incapacidades/rectificaciones';
import type {
    ImpactoIncapacidad,
    IncapacidadItem,
    OpcionIncapacidad,
    RectificacionNominaItem,
    ReglaDiasNomina,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    incapacidades: RespuestaPaginada<IncapacidadItem>;
    indicadores: {
        activas: number;
        dias_mes: number;
        riesgos_abiertos: number;
        documentos_pendientes: number;
    };
    filtros: {
        mes: string;
        tipo?: string;
        sucursal_id?: string;
        estatus?: string;
        q?: string;
        periodo: string;
    };
    tipos: OpcionIncapacidad[];
    sucursales: { id: number; nombre: string }[];
    quincenas: { clave: string; etiqueta: string }[];
    impacto: {
        clave: string;
        etiqueta: string;
        filas: ImpactoIncapacidad[];
        regla: ReglaDiasNomina;
    };
    rectificaciones: RectificacionNominaItem[];
    puede: { registrar: boolean; exportar: boolean; rectificar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Permanencia', href: '' },
            { title: 'Incapacidades', href: index.url() },
        ],
    },
});

const {
    filtros: filtrosActivos,
    aplicar,
    aplicarConDebounce,
} = useFiltros(index.url(), {
    mes: props.filtros.mes,
    tipo: props.filtros.tipo ?? '',
    sucursal_id: props.filtros.sucursal_id ?? '',
    estatus: props.filtros.estatus ?? '',
    q: props.filtros.q ?? '',
    periodo: props.filtros.periodo,
});

const pestanas = computed(() => [
    { value: '', label: 'Todas' },
    ...props.tipos,
]);

const opcionesEstatus = [
    { value: 'activa', label: 'Activas' },
    { value: 'concluida', label: 'Concluidas' },
    { value: 'cancelada', label: 'Canceladas' },
];

function urlExportar(
    destino: typeof exportarExcel | typeof exportarPdf,
): string {
    const parametros = new URLSearchParams(
        Object.entries(filtrosActivos).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}

const CLASE_TIPO: Record<string, string> = {
    enfermedad_general: 'bg-info-soft text-info',
    riesgo_trabajo: 'bg-danger-soft text-destructive',
    maternidad: 'bg-crema text-bronce',
};

const CLASE_ESTATUS: Record<string, string> = {
    activa: 'bg-warning-soft text-warning',
    concluida: 'bg-success-soft text-success',
    cancelada: 'bg-muted text-muted-foreground',
};

const descartando = ref<RectificacionNominaItem | null>(null);
const nota = ref('');
const enviando = ref(false);

function aplicarDiferencia(fila: RectificacionNominaItem) {
    if (enviando.value) {
        return;
    }

    enviando.value = true;
    router.post(
        aplicarRectificacion.url(fila.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => (enviando.value = false),
        },
    );
}

function confirmarDescarte() {
    if (!descartando.value || enviando.value) {
        return;
    }

    enviando.value = true;
    router.post(
        descartarRectificacion.url(descartando.value.id),
        { nota: nota.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                descartando.value = null;
                nota.value = '';
            },
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <Head title="Incapacidades" />

    <div class="pagina-ancha flex flex-col gap-4">
        <CrudPageHeader
            titulo="Incapacidades"
            descripcion="Captura de Recursos Humanos con datos y documentos del IMSS."
            :icono="HeartPulse"
        >
            <div class="flex flex-wrap items-center gap-2">
                <CrudExportButtons
                    v-if="puede.exportar"
                    :url-excel="urlExportar(exportarExcel)"
                    :url-pdf="urlExportar(exportarPdf)"
                />
                <Button v-if="puede.registrar" as-child>
                    <Link :href="create.url()">
                        <Plus class="size-4" />
                        Registrar incapacidad
                    </Link>
                </Button>
            </div>
        </CrudPageHeader>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div
                class="rounded-xl border border-l-4 border-border/60 border-l-oro bg-card p-3.5 shadow-sm"
            >
                <p class="text-sm text-muted-foreground">
                    Incapacidades activas
                </p>
                <p class="text-2xl font-bold tabular-nums">
                    {{ indicadores.activas }}
                </p>
                <p class="text-xs text-muted-foreground">Al día de hoy</p>
            </div>
            <div
                class="rounded-xl border border-l-4 border-border/60 border-l-primary bg-card p-3.5 shadow-sm"
            >
                <p class="text-sm text-muted-foreground">
                    Días de incapacidad del mes
                </p>
                <p class="text-2xl font-bold tabular-nums">
                    {{ indicadores.dias_mes }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Días naturales del periodo
                </p>
            </div>
            <div
                class="rounded-xl border border-l-4 border-border/60 border-l-destructive bg-card p-3.5 shadow-sm"
            >
                <p class="text-sm text-muted-foreground">
                    Riesgos de trabajo abiertos
                </p>
                <p class="text-2xl font-bold tabular-nums">
                    {{ indicadores.riesgos_abiertos }}
                </p>
                <p class="text-xs text-muted-foreground">Sin ST-2 de alta</p>
            </div>
            <div
                class="rounded-xl border border-l-4 border-border/60 border-l-bronce bg-card p-3.5 shadow-sm"
            >
                <p class="text-sm text-muted-foreground">
                    Documentos pendientes
                </p>
                <p class="text-2xl font-bold tabular-nums">
                    {{ indicadores.documentos_pendientes }}
                </p>
                <p class="text-xs text-muted-foreground">
                    ST-7 o ST-2 por cargar
                </p>
            </div>
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_280px]">
            <div class="flex min-w-0 flex-col gap-4">
                <div
                    class="rounded-xl border border-border/60 bg-card p-3 shadow-sm"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <div
                            class="flex flex-wrap gap-1"
                            role="tablist"
                            aria-label="Tipo de incapacidad"
                        >
                            <button
                                v-for="pestana in pestanas"
                                :key="pestana.value"
                                type="button"
                                role="tab"
                                :aria-selected="
                                    filtrosActivos.tipo === pestana.value
                                "
                                class="border-b-2 px-3 py-1.5 text-sm font-medium transition-colors"
                                :class="
                                    filtrosActivos.tipo === pestana.value
                                        ? 'border-oro text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                "
                                @click="
                                    filtrosActivos.tipo = pestana.value;
                                    aplicar();
                                "
                            >
                                {{ pestana.label }}
                            </button>
                        </div>
                        <div class="ml-auto flex flex-wrap items-center gap-2">
                            <Input
                                v-model="filtrosActivos.mes"
                                type="month"
                                aria-label="Mes"
                                class="w-40"
                                @change="aplicar"
                            />
                            <SelectSimple
                                :model-value="filtrosActivos.sucursal_id"
                                :opciones="opcionesDe(sucursales)"
                                placeholder="Todas las sucursales"
                                opcion-vacia="Todas las sucursales"
                                class="w-full sm:w-48"
                                @update:model-value="
                                    (v) => {
                                        filtrosActivos.sucursal_id = String(
                                            v ?? '',
                                        );
                                        aplicar();
                                    }
                                "
                            />
                            <SelectSimple
                                :model-value="filtrosActivos.estatus"
                                :opciones="opcionesEstatus"
                                placeholder="Activas y concluidas"
                                opcion-vacia="Activas y concluidas"
                                class="w-full sm:w-44"
                                @update:model-value="
                                    (v) => {
                                        filtrosActivos.estatus = String(
                                            v ?? '',
                                        );
                                        aplicar();
                                    }
                                "
                            />
                        </div>
                    </div>

                    <CrudSearchInput
                        class="mt-2"
                        :model-value="filtrosActivos.q"
                        placeholder="Buscar colaborador, puesto o sucursal..."
                        @update:model-value="
                            (v) => {
                                filtrosActivos.q = v;
                                aplicarConDebounce();
                            }
                        "
                    />

                    <CrudEmptyState
                        v-if="!incapacidades.data.length"
                        class="mt-3"
                        :icono="HeartPulse"
                        titulo="Sin incapacidades en este periodo"
                        descripcion="Ajusta el mes o los filtros para ver otras."
                    />

                    <div v-else class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[760px] text-[13px]">
                            <thead>
                                <tr
                                    class="bg-crema/50 text-left text-xs tracking-wide text-muted-foreground uppercase"
                                >
                                    <th class="px-2 py-2">Colaborador</th>
                                    <th class="px-2 py-2">Tipo</th>
                                    <th class="px-2 py-2">
                                        Inicial / subsecuente
                                    </th>
                                    <th class="px-2 py-2">Serie y folio</th>
                                    <th class="px-2 py-2">Inicio</th>
                                    <th class="px-2 py-2 text-right">Días</th>
                                    <th class="px-2 py-2">
                                        Término (automático)
                                    </th>
                                    <th class="px-2 py-2">Estatus</th>
                                    <th class="px-2 py-2">Documentos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="fila in incapacidades.data"
                                    :key="fila.id"
                                    class="border-t border-border/60 hover:bg-muted/40"
                                >
                                    <td class="px-2 py-2">
                                        <Link
                                            :href="show.url(fila.id)"
                                            class="font-semibold hover:underline"
                                        >
                                            {{ fila.colaborador }}
                                        </Link>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                fila.sucursal ?? 'Sin sucursal'
                                            }}
                                        </p>
                                    </td>
                                    <td class="px-2 py-2">
                                        <span
                                            class="inline-block rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                            :class="CLASE_TIPO[fila.tipo]"
                                        >
                                            {{ fila.tipo_etiqueta }}
                                        </span>
                                        <p
                                            v-if="fila.subtipo_etiqueta"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            {{ fila.subtipo_etiqueta }}
                                        </p>
                                    </td>
                                    <td class="px-2 py-2">
                                        {{ fila.secuencia_etiqueta }}
                                    </td>
                                    <td class="px-2 py-2 whitespace-nowrap">
                                        {{ fila.serie_folio_imss }}
                                    </td>
                                    <td class="px-2 py-2 whitespace-nowrap">
                                        {{ formatearFecha(fila.fecha_inicio) }}
                                    </td>
                                    <td
                                        class="px-2 py-2 text-right font-semibold tabular-nums"
                                    >
                                        {{ fila.dias }}
                                    </td>
                                    <td
                                        class="px-2 py-2 font-semibold whitespace-nowrap"
                                    >
                                        {{ formatearFecha(fila.fecha_fin) }}
                                    </td>
                                    <td class="px-2 py-2">
                                        <span
                                            class="inline-block rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                            :class="CLASE_ESTATUS[fila.estatus]"
                                        >
                                            {{ fila.estatus_etiqueta }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-2">
                                        <div
                                            class="flex flex-col items-start gap-1"
                                        >
                                            <span
                                                v-for="documento in fila.documentos_resumen"
                                                :key="documento.etiqueta"
                                                class="inline-block rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                                :class="
                                                    documento.cargado
                                                        ? 'bg-success-soft text-success'
                                                        : 'bg-warning-soft text-warning'
                                                "
                                            >
                                                {{ documento.etiqueta }}
                                                {{
                                                    documento.cargado
                                                        ? '✓'
                                                        : 'pendiente'
                                                }}
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-2 text-xs text-muted-foreground">
                        La fecha de término se calcula sola: fecha de inicio +
                        días de incapacidad − 1, en días naturales.
                    </p>
                    <PaginacionTabla :datos="incapacidades" />
                </div>

                <div
                    class="rounded-xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <h2 class="font-semibold">Historial por colaborador</h2>
                    <p class="text-sm text-muted-foreground">
                        Al abrir una persona se ve la cadena completa de cada
                        riesgo de trabajo: ST-7 → inicial → subsecuentes → ST-2
                        (y ST-3 si aplica). Exportable para atender al IMSS.
                    </p>
                    <div
                        v-if="incapacidades.data.length"
                        class="mt-2 flex flex-wrap gap-2"
                    >
                        <Button
                            v-for="persona in [
                                ...new Map(
                                    incapacidades.data.map((i) => [
                                        i.colaborador_id,
                                        i,
                                    ]),
                                ).values(),
                            ].slice(0, 6)"
                            :key="persona.colaborador_id"
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <Link :href="historial.url(persona.colaborador_id)">
                                {{ persona.colaborador }}
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>

            <aside
                class="flex flex-col gap-3 rounded-xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <div>
                    <h2 class="font-semibold">Impacto en nómina</h2>
                    <p class="text-xs text-muted-foreground">
                        Días de incapacidad de cada persona dentro del periodo.
                    </p>
                </div>
                <SelectSimple
                    :model-value="filtrosActivos.periodo"
                    :opciones="
                        quincenas.map((q) => ({
                            value: q.clave,
                            label: q.etiqueta,
                        }))
                    "
                    placeholder="Quincena"
                    @update:model-value="
                        (v) => {
                            filtrosActivos.periodo = String(v ?? '');
                            aplicar();
                        }
                    "
                />
                <p
                    v-if="!impacto.filas.length"
                    class="text-sm text-muted-foreground"
                >
                    Sin incapacidades en este periodo.
                </p>
                <ul v-else class="flex flex-col divide-y divide-border/60">
                    <li
                        v-for="fila in impacto.filas"
                        :key="fila.colaborador_id"
                        class="flex items-start justify-between gap-2 py-2"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">
                                {{ fila.nombre }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ fila.sucursal ?? 'Sin sucursal' }} ·
                                {{ fila.detalle }}
                            </p>
                        </div>
                        <span
                            class="text-sm font-bold whitespace-nowrap text-destructive tabular-nums"
                            >{{ fila.dias }} d</span
                        >
                    </li>
                </ul>
                <p class="rounded-lg bg-salvia/60 p-2.5 text-xs">
                    Estos días llegan en automático al
                    <strong>Recibo simple de pago</strong> y al reporte
                    <strong>Incidencias de nómina</strong>. Sucursales pagan por
                    semana: el recibo aplica los días de cada semana.
                </p>
                <p
                    v-if="impacto.regla.aviso"
                    class="flex gap-1.5 rounded-lg bg-warning-soft p-2.5 text-xs text-warning"
                >
                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        <strong>{{ impacto.regla.etiqueta }}.</strong>
                        {{ impacto.regla.aviso }}
                    </span>
                </p>

                <div v-if="puede.rectificar && rectificaciones.length">
                    <h3 class="text-sm font-semibold">
                        Recibos emitidos por rectificar
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Un recibo emitido no cambia solo: tú decides.
                    </p>
                    <ul class="mt-1 flex flex-col gap-2">
                        <li
                            v-for="fila in rectificaciones"
                            :key="fila.id"
                            class="rounded-lg border border-border/60 p-2.5 text-xs"
                        >
                            <p class="font-semibold">
                                {{ fila.colaborador }} · {{ fila.folio }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ fila.periodo }}:
                                {{ fila.dias_antes ?? '—' }} →
                                {{ fila.dias_despues }} días pagados
                            </p>
                            <p class="text-muted-foreground">
                                {{ fila.motivo }}
                            </p>
                            <div class="mt-1.5 flex gap-1.5">
                                <Button
                                    size="sm"
                                    :disabled="enviando"
                                    @click="aplicarDiferencia(fila)"
                                >
                                    <CheckCircle2 class="size-3.5" />
                                    Aplicar
                                </Button>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    :disabled="enviando"
                                    @click="descartando = fila"
                                >
                                    Descartar
                                </Button>
                            </div>
                        </li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>

    <Dialog
        :open="descartando !== null"
        @update:open="(v) => !v && (descartando = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Descartar rectificación</DialogTitle>
            </DialogHeader>
            <div class="grid gap-2">
                <Label for="nota">¿Por qué no se aplica?</Label>
                <Textarea id="nota" v-model="nota" rows="3" />
            </div>
            <DialogFooter>
                <Button variant="secondary" @click="descartando = null">
                    Cancelar
                </Button>
                <Button
                    :disabled="enviando || nota.trim().length < 5"
                    @click="confirmarDescarte"
                >
                    Descartar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
