<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarRange,
    CheckCircle2,
    CircleX,
    FileSpreadsheet,
    Layers,
    Plus,
    ReceiptText,
    Send,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { dashboard } from '@/routes';
import { index as indexRecibos } from '@/routes/rh/nomina';
import { index, show, store } from '@/routes/rh/nomina/lotes';
import type { LoteNomina, RespuestaPaginada } from '@/types';

/**
 * Lotes de recibos de nómina: PREPARAR → REVISAR → EMITIR. Crear un lote
 * NUNCA publica: los recibos quedan en borrador hasta que RH los revisa y
 * pulsa «Emitir» dentro del lote.
 */
type PeriodoSugerido = {
    inicio: string;
    fin: string;
    pago: string;
    numero: number;
    etiqueta: string;
};

const props = defineProps<{
    lotes: RespuestaPaginada<LoteNomina>;
    periodos: { semanal: PeriodoSugerido; quincenal: PeriodoSugerido };
    puedeCrear: boolean;
    puedeImportar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Recibos de nómina', href: index() },
        ],
    },
});

const dinero = (valor: number | string | null) =>
    Number(valor ?? 0).toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
    });

const fechaCorta = (valor: string | null) =>
    valor
        ? new Date(valor).toLocaleDateString('es-MX', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const enRevision = computed(
    () => props.lotes.data.filter((l) => l.estado === 'preparado').length,
);
const emitidos = computed(
    () => props.lotes.data.filter((l) => l.estado === 'emitido').length,
);
const netoEmitido = computed(() =>
    props.lotes.data
        .filter((l) => l.estado === 'emitido')
        .reduce((total, l) => total + Number(l.total_neto), 0),
);

const ESTADOS: Record<
    string,
    { clase: string; icono: typeof CheckCircle2 }
> = {
    preparado: {
        clase: 'bg-warning-soft text-warning',
        icono: Layers,
    },
    emitido: {
        clase: 'bg-success-soft/60 text-success',
        icono: CheckCircle2,
    },
    cancelado: {
        clase: 'bg-destructive/10 text-destructive',
        icono: CircleX,
    },
    borrador: { clase: 'bg-muted text-muted-foreground', icono: Layers },
};

// --- Nuevo lote ------------------------------------------------------------
const abierto = ref(false);
const form = useForm<{
    periodicidad: 'semanal' | 'quincenal';
    fecha: string;
    origen: 'sueldos' | 'importacion';
    archivo: File | null;
}>({
    periodicidad: 'semanal',
    fecha: props.periodos.semanal.inicio,
    origen: 'sueldos',
    archivo: null,
});

function elegirPeriodicidad(valor: 'semanal' | 'quincenal') {
    form.periodicidad = valor;
    form.fecha = props.periodos[valor].inicio;
}

const periodoElegido = computed(() => {
    const base = new Date(`${form.fecha}T12:00:00`);

    if (Number.isNaN(base.getTime())) {
        return '';
    }

    if (form.periodicidad === 'semanal') {
        const lunes = new Date(base);
        lunes.setDate(base.getDate() - ((base.getDay() + 6) % 7));
        const domingo = new Date(lunes);
        domingo.setDate(lunes.getDate() + 6);

        return `Lunes ${fechaCorta(lunes.toISOString())} → domingo ${fechaCorta(domingo.toISOString())}`;
    }

    const primera = base.getDate() <= 15;
    const inicio = new Date(base.getFullYear(), base.getMonth(), primera ? 1 : 16, 12);
    // Día 0 del mes siguiente = último día REAL del mes.
    const fin = primera
        ? new Date(base.getFullYear(), base.getMonth(), 15, 12)
        : new Date(base.getFullYear(), base.getMonth() + 1, 0, 12);

    return `${fechaCorta(inicio.toISOString())} → ${fechaCorta(fin.toISOString())}`;
});

function crear() {
    form.post(store.url(), {
        forceFormData: true,
        onSuccess: () => {
            abierto.value = false;
            form.reset('archivo');
        },
    });
}

function cambiarPagina(pagina: number) {
    router.get(index.url(), { page: pagina }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Recibos de nómina" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Recibos de nómina"
            descripcion="Prepara el lote, revisa cada recibo con el formato oficial y emítelo cuando esté correcto. Preparar nunca publica."
            :icono="ReceiptText"
        >
            <template #default>
                <Button variant="outline" as-child>
                    <Link :href="indexRecibos()">
                        <FileSpreadsheet class="size-4" />
                        Recibos y descargas
                    </Link>
                </Button>
                <Button v-if="puedeCrear" @click="abierto = true">
                    <Plus class="size-4" />
                    Nuevo lote
                </Button>
            </template>
        </CrudPageHeader>

        <!-- Flujo en tres pasos -->
        <ol class="grid gap-3 sm:grid-cols-3">
            <li
                v-for="(paso, i) in [
                    { t: 'Preparar', d: 'Desde el sueldo de cada persona o importando el archivo de RH.', i: Layers },
                    { t: 'Revisar', d: 'PDF real de cada recibo, errores y advertencias antes de publicar.', i: CalendarRange },
                    { t: 'Emitir', d: 'Solo entonces se publica en web/app y se avisa a cada colaborador.', i: Send },
                ]"
                :key="paso.t"
                class="flex items-start gap-3 rounded-2xl border border-border/60 bg-card p-4"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                >
                    <component :is="paso.i" class="size-4" />
                </span>
                <div>
                    <p class="text-sm font-semibold">
                        {{ i + 1 }}. {{ paso.t }}
                    </p>
                    <p class="text-xs text-muted-foreground">{{ paso.d }}</p>
                </div>
            </li>
        </ol>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <MetricCard
                etiqueta="Lotes"
                :valor="lotes.total"
                :icono="Layers"
            />
            <MetricCard
                etiqueta="En revisión"
                :valor="enRevision"
                :icono="AlertTriangle"
                color-clase="bg-warning-soft text-warning"
            />
            <MetricCard
                etiqueta="Emitidos"
                :valor="emitidos"
                :icono="CheckCircle2"
                color-clase="bg-success-soft/60 text-success"
            />
            <MetricCard
                etiqueta="Neto emitido (esta página)"
                :valor="dinero(netoEmitido)"
                :icono="Wallet"
            />
        </div>

        <EmptyState
            v-if="lotes.data.length === 0"
            :icono="ReceiptText"
            titulo="Todavía no hay lotes"
            descripcion="Crea el primero con «Nuevo lote»: los recibos quedarán en borrador para que los revises."
        />

        <div v-else class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
            <Link
                v-for="lote in lotes.data"
                :key="lote.id"
                :href="show(lote.id)"
                class="group flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold">
                            {{ lote.etiqueta }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ lote.folio }} ·
                            {{
                                lote.origen === 'importacion'
                                    ? 'Importado'
                                    : 'Desde sueldos'
                            }}
                        </p>
                    </div>
                    <span
                        class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium"
                        :class="ESTADOS[lote.estado]?.clase"
                    >
                        <component
                            :is="ESTADOS[lote.estado]?.icono"
                            class="size-3.5"
                        />
                        {{ lote.estado_etiqueta }}
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-xl bg-muted/60 p-2">
                        <p class="text-lg font-bold tabular-nums">
                            {{ lote.preparados }}
                            <span class="text-xs font-normal text-muted-foreground"
                                >/ {{ lote.esperados }}</span
                            >
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            Preparados
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/60 p-2">
                        <p
                            class="text-lg font-bold tabular-nums"
                            :class="lote.total_errores ? 'text-destructive' : ''"
                        >
                            {{ lote.total_errores }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">Errores</p>
                    </div>
                    <div class="rounded-xl bg-muted/60 p-2">
                        <p
                            class="text-lg font-bold tabular-nums"
                            :class="
                                lote.total_advertencias ? 'text-warning' : ''
                            "
                        >
                            {{ lote.total_advertencias }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            Advertencias
                        </p>
                    </div>
                </div>

                <div
                    class="flex items-end justify-between border-t border-border/60 pt-3"
                >
                    <div>
                        <p class="text-[11px] text-muted-foreground">
                            Neto del lote
                        </p>
                        <p class="text-lg font-semibold tabular-nums">
                            {{ dinero(lote.total_neto) }}
                        </p>
                    </div>
                    <p class="text-right text-[11px] text-muted-foreground">
                        <template v-if="lote.emitido_at">
                            Emitido {{ fechaCorta(lote.emitido_at) }}<br />
                            por {{ lote.emitido_por }}
                        </template>
                        <template v-else>
                            Creado {{ fechaCorta(lote.creado_en) }}<br />
                            por {{ lote.creado_por ?? '—' }}
                        </template>
                    </p>
                </div>
            </Link>
        </div>

        <div
            v-if="lotes.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="lotes.current_page <= 1"
                @click="cambiarPagina(lotes.current_page - 1)"
                >Anterior</Button
            >
            <span class="text-muted-foreground"
                >Página {{ lotes.current_page }} de {{ lotes.last_page }}</span
            >
            <Button
                variant="outline"
                size="sm"
                :disabled="lotes.current_page >= lotes.last_page"
                @click="cambiarPagina(lotes.current_page + 1)"
                >Siguiente</Button
            >
        </div>
    </div>

    <Sheet v-model:open="abierto">
        <SheetContent class="flex w-full flex-col gap-0 sm:max-w-md">
            <SheetHeader>
                <SheetTitle>Nuevo lote de nómina</SheetTitle>
                <SheetDescription>
                    Los recibos quedan en borrador: nadie los ve ni recibe aviso
                    hasta que emitas el lote.
                </SheetDescription>
            </SheetHeader>

            <form
                class="flex flex-1 flex-col gap-5 overflow-y-auto px-4 pb-4"
                @submit.prevent="crear"
            >
                <div class="grid gap-2">
                    <Label>Periodicidad</Label>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="opcion in [
                                { v: 'semanal', t: 'Semanal', d: 'Lunes a domingo' },
                                { v: 'quincenal', t: 'Quincenal', d: '1–15 y 16–fin de mes' },
                            ] as const"
                            :key="opcion.v"
                            type="button"
                            class="rounded-xl border p-3 text-left transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="
                                form.periodicidad === opcion.v
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'border-border hover:bg-muted/60'
                            "
                            @click="elegirPeriodicidad(opcion.v)"
                        >
                            <p class="text-sm font-semibold">{{ opcion.t }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ opcion.d }}
                            </p>
                        </button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="fecha-lote">Cualquier día del periodo</Label>
                    <DatePicker id="fecha-lote" v-model="form.fecha" />
                    <p
                        class="rounded-lg bg-muted/60 px-3 py-2 text-xs text-muted-foreground"
                    >
                        Periodo: <strong class="text-foreground">{{ periodoElegido }}</strong>
                    </p>
                    <InputError :message="form.errors.fecha ?? (form.errors as Record<string, string>).periodo" />
                </div>

                <div class="grid gap-2">
                    <Label>¿De dónde salen los montos?</Label>
                    <div class="grid gap-2">
                        <button
                            type="button"
                            class="rounded-xl border p-3 text-left transition"
                            :class="
                                form.origen === 'sueldos'
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'border-border hover:bg-muted/60'
                            "
                            @click="form.origen = 'sueldos'"
                        >
                            <p class="text-sm font-semibold">
                                Sueldo de cada colaborador
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Salario diario × días del periodo (proporcional
                                si ingresó a mitad).
                            </p>
                        </button>
                        <button
                            v-if="puedeImportar"
                            type="button"
                            class="rounded-xl border p-3 text-left transition"
                            :class="
                                form.origen === 'importacion'
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'border-border hover:bg-muted/60'
                            "
                            @click="form.origen = 'importacion'"
                        >
                            <p class="text-sm font-semibold">Importar archivo</p>
                            <p class="text-xs text-muted-foreground">
                                CSV/Excel: numero_empleado, tipo, concepto,
                                importe (clave y días opcionales).
                            </p>
                        </button>
                    </div>
                </div>

                <div v-if="form.origen === 'importacion'" class="grid gap-2">
                    <Label for="archivo-lote">Archivo</Label>
                    <input
                        id="archivo-lote"
                        type="file"
                        accept=".csv,.xlsx,.xls"
                        class="block w-full rounded-lg border border-input bg-background px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1 file:text-xs file:font-medium file:text-primary-foreground"
                        @change="
                            form.archivo =
                                ($event.target as HTMLInputElement).files?.[0] ??
                                null
                        "
                    />
                    <InputError :message="form.errors.archivo" />
                </div>

                <Badge
                    variant="secondary"
                    class="w-fit gap-1.5 whitespace-normal"
                >
                    <AlertTriangle class="size-3.5 shrink-0" />
                    Preparar no publica, no notifica y no envía push.
                </Badge>

                <SheetFooter class="mt-auto px-0">
                    <Button type="submit" class="w-full" :disabled="form.processing">
                        <Layers class="size-4" />
                        {{ form.processing ? 'Preparando…' : 'Preparar lote' }}
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
