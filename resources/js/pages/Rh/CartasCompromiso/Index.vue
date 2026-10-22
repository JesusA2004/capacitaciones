<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlarmClock,
    CalendarClock,
    ChevronRight,
    CircleCheck,
    FilePenLine,
    FileSignature,
    FileWarning,
    Plus,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/rh/cartas-compromiso';
import type {
    CartaCompromisoFila,
    IndicadoresCartasCompromiso,
    PestanaCartasCompromiso,
    ReglasCartaCompromiso,
} from '@/types';

const props = defineProps<{
    cartas: {
        data: CartaCompromisoFila[];
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    indicadores: IndicadoresCartasCompromiso;
    filtros: { pestana: PestanaCartasCompromiso; busqueda: string };
    puedeGenerar: boolean;
    reglas: ReglasCartaCompromiso;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cartas compromiso', href: '' },
        ],
    },
});

const estadisticas = computed(() => [
    {
        etiqueta: 'Vigentes',
        valor: props.indicadores.vigentes,
        subvalor: `${props.reglas.dias_plazo} días de plazo`,
        icono: CalendarClock,
        tono: 'info' as const,
    },
    {
        etiqueta: 'Por vencer',
        valor: props.indicadores.por_vencer,
        subvalor: `${props.reglas.dias_por_vencer} días o menos`,
        icono: AlarmClock,
        tono: 'warning' as const,
    },
    {
        etiqueta: 'Vencidas',
        valor: props.indicadores.vencidas,
        subvalor: 'App limitada a subir documentos',
        icono: FileWarning,
        tono: 'danger' as const,
    },
    {
        etiqueta: 'Cumplidas',
        valor: props.indicadores.cumplidas,
        subvalor: 'Documentos entregados',
        icono: CircleCheck,
        tono: 'success' as const,
    },
    {
        etiqueta: 'Firmadas',
        valor: props.indicadores.firmadas,
        subvalor: `${props.indicadores.pendientes_firma} pendientes de firma`,
        icono: FileSignature,
        tono: 'default' as const,
    },
]);

const PESTANAS: { valor: PestanaCartasCompromiso; etiqueta: string }[] = [
    { valor: 'todas', etiqueta: 'Todas' },
    { valor: 'pendientes_firma', etiqueta: 'Pendientes de firma' },
    { valor: 'vigentes', etiqueta: 'Vigentes' },
    { valor: 'por_vencer', etiqueta: 'Por vencer' },
    { valor: 'vencidas', etiqueta: 'Vencidas' },
    { valor: 'cumplidas', etiqueta: 'Cumplidas' },
    { valor: 'firmadas', etiqueta: 'Firmadas' },
    { valor: 'sustituidas', etiqueta: 'Historial (sustituidas)' },
];

const pestana = ref<PestanaCartasCompromiso>(props.filtros.pestana);
const termino = ref(props.filtros.busqueda);

function filtrar() {
    router.get(
        index.url(),
        {
            ...parametrosPorPaginaActuales(),
            pestana: pestana.value === 'todas' ? undefined : pestana.value,
            busqueda: termino.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(termino, useDebounceFn(filtrar, 300));

function elegirPestana(valor: PestanaCartasCompromiso) {
    pestana.value = valor;
    filtrar();
}

const VARIANTE_ESTADO = {
    vigente: 'info',
    cumplida: 'success',
    vencida: 'destructive',
    sustituida: 'secondary',
} as const;

function textoPlazo(c: CartaCompromisoFila): string {
    if (c.estado !== 'vigente') {
        return `Límite: ${formatearFecha(c.fecha_limite)}`;
    }

    if (c.dias_restantes === 0) {
        return 'Vence hoy';
    }

    return c.dias_restantes === 1
        ? 'Vence mañana'
        : `Vence en ${c.dias_restantes} días`;
}
</script>

<template>
    <Head title="Cartas compromiso" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Cartas compromiso"
            descripcion="Personas de nuevo ingreso que se comprometen a entregar documentos pendientes: generación con vista previa, firma de quien entra y seguimiento del plazo."
            :icono="FilePenLine"
        >
            <Button v-if="puedeGenerar" as-child data-tour="cartas-generar">
                <Link :href="create.url()">
                    <Plus class="size-4" /> Generar carta compromiso
                </Link>
            </Button>
        </CrudPageHeader>

        <CrudStats :estadisticas="estadisticas" />

        <div
            data-tour="cartas-filtros"
            class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
        >
            <div
                class="flex flex-wrap gap-1.5"
                role="tablist"
                aria-label="Filtrar cartas"
            >
                <button
                    v-for="p in PESTANAS"
                    :key="p.valor"
                    type="button"
                    role="tab"
                    :aria-selected="pestana === p.valor"
                    class="rounded-full border px-3 py-1.5 text-sm transition-colors"
                    :class="
                        pestana === p.valor
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] text-muted-foreground hover:bg-crema/60'
                    "
                    @click="elegirPestana(p.valor)"
                >
                    {{ p.etiqueta }}
                </button>
            </div>
            <CrudSearchInput
                v-model="termino"
                placeholder="Buscar por nombre, número, sucursal o folio…"
            />
        </div>

        <section
            data-tour="cartas-lista"
            class="overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        >
            <ul
                v-if="cartas.data.length"
                class="divide-y divide-[var(--mrl-borde)]"
            >
                <li v-for="c in cartas.data" :key="c.id">
                    <Link
                        :href="show.url(c.id)"
                        class="flex tarjeta-interactiva flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3"
                    >
                        <span
                            class="w-32 shrink-0 font-mono text-xs text-muted-foreground"
                            >{{ c.folio }}</span
                        >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{
                                c.colaborador?.nombre ?? 'Sin persona'
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                            >
                                {{
                                    [
                                        c.colaborador?.puesto,
                                        c.colaborador?.sucursal,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ c.documentos.join(' · ') }}</span
                            >
                        </span>
                        <span
                            class="text-xs"
                            :class="
                                c.por_vencer
                                    ? 'font-medium text-warning'
                                    : 'text-muted-foreground'
                            "
                            >{{ textoPlazo(c) }}</span
                        >
                        <Badge :variant="VARIANTE_ESTADO[c.estado]">{{
                            c.estado_etiqueta
                        }}</Badge>
                        <Badge :variant="c.firmada ? 'success' : 'warning'">{{
                            c.firmada ? 'Firmada' : 'Pendiente de firma'
                        }}</Badge>
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </li>
            </ul>
            <div
                v-else
                class="flex flex-col items-center gap-2 px-4 py-12 text-center text-sm text-muted-foreground"
            >
                <FilePenLine class="size-8 text-muted-foreground/60" />
                <p>No hay cartas compromiso con estos filtros.</p>
                <p v-if="puedeGenerar" class="max-w-md">
                    Una carta cubre como máximo
                    {{ reglas.max_documentos }} documentos y da
                    {{ reglas.dias_plazo }} días para entregarlos.
                </p>
            </div>
        </section>

        <PaginacionTabla :datos="cartas" />
    </div>
</template>
