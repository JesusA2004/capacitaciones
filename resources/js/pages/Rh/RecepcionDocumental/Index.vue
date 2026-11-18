<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarRange,
    ChevronRight,
    CircleCheck,
    Clock,
    PackageCheck,
    Percent,
    RefreshCw,
    Settings2,
} from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import DashboardChartCard from '@/components/Dashboard/DashboardChartCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import KpiRecepcion from '@/components/recepcion-documental/KpiRecepcion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';
import { dashboard } from '@/routes';
import { generar, show } from '@/routes/rh/recepcion-documental';
import { index as rutaCatalogo } from '@/routes/rh/recepcion-documental/catalogo';
import type {
    CorteRecepcion,
    EntradaCatalogoRecepcion,
    EstadoCorteRecepcion,
} from '@/types';

const props = defineProps<{
    cortes: {
        data: CorteRecepcion[];
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    periodoActual: { clave: string; etiqueta: string };
    puedeGenerar: boolean;
    puedeConfigurar: boolean;
    reglas: EntradaCatalogoRecepcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Recepción documental', href: '' },
        ],
    },
});

const form = useForm({ periodo: props.periodoActual.clave });

function calcular() {
    form.post(generar.url(), { preserveScroll: true });
}

const VARIANTE_ESTADO: Record<
    EstadoCorteRecepcion,
    'secondary' | 'info' | 'warning' | 'success' | 'outline'
> = {
    en_preparacion: 'secondary',
    abierto: 'info',
    con_pendientes: 'warning',
    completo: 'success',
    cerrado: 'outline',
};

/** Corte del mes en curso (si ya existe) para los KPI de arriba. */
const corteActual = computed(
    () =>
        props.cortes.data.find(
            (c) => c.periodo === props.periodoActual.clave,
        ) ?? null,
);

const metricasActuales = computed(
    () =>
        corteActual.value?.metricas ?? {
            esperados: 0,
            recibidos: 0,
            pendientes: 0,
            no_recibidos: 0,
            incidencias: 0,
            no_aplica: 0,
            renglones: 0,
            cumplimiento: 0,
        },
);

/** Historia (más viejo → más nuevo) para la gráfica de cumplimiento. */
const historia = computed(() =>
    [...props.cortes.data].reverse().map((c) => ({
        mes: c.etiqueta.split(' ')[0]?.slice(0, 3) ?? c.periodo,
        cumplimiento: c.metricas.cumplimiento,
    })),
);

const distribucion = computed(() => [
    { estado: 'Recibidos', cantidad: metricasActuales.value.recibidos },
    { estado: 'Pendientes', cantidad: metricasActuales.value.pendientes },
    { estado: 'No recibidos', cantidad: metricasActuales.value.no_recibidos },
]);

const encendidas = computed(() => props.reglas.filter((r) => r.activa));
</script>

<template>
    <Head title="Recepción documental" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Recepción documental RH"
            descripcion="Control mensual de los originales firmados que llegan por paquetería. Lo esperado lo calcula el sistema desde eventos reales, según el catálogo de lo que se recibe."
            :icono="PackageCheck"
        >
            <Button variant="outline" as-child>
                <Link
                    data-tour="recepcion-configurar"
                    :href="rutaCatalogo.url()"
                    ><Settings2 class="size-4" />
                    {{
                        puedeConfigurar
                            ? 'Configurar lo que recibo'
                            : 'Ver lo que recibo'
                    }}</Link
                >
            </Button>
        </CrudPageHeader>

        <div
            data-tour="recepcion-indicadores"
            class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <KpiRecepcion
                :etiqueta="`Esperados en ${periodoActual.etiqueta}`"
                :valor="metricasActuales.esperados"
                :icono="PackageCheck"
                tono="info"
                :subtitulo="
                    corteActual
                        ? `${metricasActuales.no_aplica} no aplican`
                        : 'El corte del mes aún no se calcula'
                "
            />
            <KpiRecepcion
                etiqueta="Recibidos"
                :valor="metricasActuales.recibidos"
                :icono="CircleCheck"
                tono="success"
            />
            <KpiRecepcion
                etiqueta="Pendientes"
                :valor="metricasActuales.pendientes"
                :icono="metricasActuales.incidencias ? AlertTriangle : Clock"
                tono="warning"
                :subtitulo="`${metricasActuales.incidencias} con incidencia`"
            />
            <KpiRecepcion
                etiqueta="Cumplimiento del mes"
                :valor="metricasActuales.cumplimiento"
                :decimales="1"
                sufijo="%"
                :icono="Percent"
                tono="neutro"
                :avance="metricasActuales.cumplimiento"
            />
        </div>

        <div class="grid gap-3 lg:grid-cols-3">
            <DashboardChartCard
                class="lg:col-span-2"
                title="Cumplimiento por mes"
                description="Porcentaje de originales recibidos contra lo esperado en cada corte."
                type="area"
                :data="historia"
                x-key="mes"
                y-key="cumplimiento"
                :height="200"
                empty-title="Sin cortes todavía"
                empty-description="Cuando se calcule el primer corte, verás su avance aquí."
            />
            <DashboardChartCard
                :title="`Estado de ${periodoActual.etiqueta}`"
                description="Originales del mes en curso."
                type="donut"
                :data="distribucion"
                label-key="estado"
                value-key="cantidad"
                :colors="[
                    'var(--success)',
                    'var(--warning)',
                    'var(--destructive)',
                ]"
                :height="170"
                empty-title="Sin originales esperados"
                empty-description="Aún no hay nada que conciliar este mes."
            />
        </div>

        <section
            v-if="puedeGenerar"
            data-tour="recepcion-calcular"
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:flex-row sm:items-end"
        >
            <div class="flex flex-col gap-1.5">
                <label for="periodo" class="text-sm font-medium"
                    >Mes a conciliar</label
                >
                <MonthPicker
                    id="periodo"
                    v-model="form.periodo"
                    aria-label="Mes a conciliar"
                    class="w-full sm:w-52"
                />
                <p v-if="form.errors.periodo" class="text-xs text-destructive">
                    {{ form.errors.periodo }}
                </p>
            </div>
            <Button :disabled="form.processing" @click="calcular">
                <RefreshCw
                    class="size-4"
                    :class="{ 'animate-spin': form.processing }"
                />
                Calcular / actualizar corte
            </Button>
            <p class="text-xs text-muted-foreground sm:ml-auto sm:max-w-md">
                Calcular es seguro: nunca duplica ni borra lo ya recibido. El
                corte del mes en curso se actualiza solo cada mañana y cada vez
                que cambia el catálogo.
            </p>
        </section>

        <section
            data-tour="recepcion-cortes"
            class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
        >
            <header
                class="flex items-center justify-between gap-2 border-b border-border/60 px-4 py-3"
            >
                <h2 class="text-sm font-semibold">Cortes mensuales</h2>
                <span class="text-xs text-muted-foreground"
                    >{{ cortes.total }} corte(s)</span
                >
            </header>
            <ul
                v-if="cortes.data.length"
                class="entrada-escalonada divide-y divide-border/60"
            >
                <li v-for="c in cortes.data" :key="c.id">
                    <Link
                        :href="show.url(c.id)"
                        class="group flex fila-interactiva flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3.5"
                    >
                        <span
                            class="flex min-w-0 flex-1 basis-48 items-center gap-3"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                                aria-hidden="true"
                            >
                                <CalendarRange class="size-5" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-medium">{{
                                    c.etiqueta
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ c.dias }} días · {{ c.fecha_inicio }} al
                                    {{ c.fecha_fin }}</span
                                >
                            </span>
                        </span>
                        <span
                            class="grid w-full grid-cols-3 gap-2 text-center text-xs text-muted-foreground sm:w-auto sm:gap-6"
                        >
                            <span>
                                <span
                                    class="block text-base font-semibold text-foreground tabular-nums"
                                    ><NumeroAnimado
                                        :valor="c.metricas.esperados"
                                /></span>
                                Esperados
                            </span>
                            <span>
                                <span
                                    class="block text-base font-semibold text-success tabular-nums"
                                    ><NumeroAnimado
                                        :valor="c.metricas.recibidos"
                                /></span>
                                Recibidos
                            </span>
                            <span>
                                <span
                                    class="block text-base font-semibold text-warning tabular-nums"
                                    ><NumeroAnimado
                                        :valor="c.metricas.pendientes"
                                /></span>
                                Pendientes
                            </span>
                        </span>
                        <span class="flex w-full flex-col gap-1 sm:w-40">
                            <span
                                class="flex justify-between text-xs text-muted-foreground"
                            >
                                Cumplimiento
                                <span
                                    class="font-semibold text-foreground tabular-nums"
                                    ><NumeroAnimado
                                        :valor="c.metricas.cumplimiento"
                                        :decimales="1"
                                        sufijo="%"
                                /></span>
                            </span>
                            <span
                                class="h-1.5 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full rounded-full bg-success transition-[width] duration-700 ease-out motion-reduce:transition-none"
                                    :style="{
                                        width: `${Math.min(100, c.metricas.cumplimiento)}%`,
                                    }"
                                />
                            </span>
                        </span>
                        <Badge :variant="VARIANTE_ESTADO[c.estado]">{{
                            c.estado_etiqueta
                        }}</Badge>
                        <ChevronRight
                            class="hidden size-4 shrink-0 icono-avanza text-muted-foreground sm:block"
                        />
                    </Link>
                </li>
            </ul>
            <EmptyState
                v-else
                :icono="PackageCheck"
                titulo="Todavía no hay cortes"
                :descripcion="
                    puedeGenerar
                        ? 'Elige el mes arriba y presiona «Calcular / actualizar corte» para armar el primero.'
                        : 'Cuando Recursos Humanos calcule el corte de un mes, aparecerá aquí.'
                "
            />
        </section>

        <PaginacionTabla :datos="cortes" />

        <Link
            data-tour="recepcion-resumen-catalogo"
            :href="rutaCatalogo.url()"
            class="flex tarjeta-interactiva flex-col gap-3 rounded-2xl border border-border/60 bg-crema/50 p-4 text-sm"
        >
            <span class="flex items-center justify-between gap-2">
                <span class="font-semibold">Lo que se recibe cada mes</span>
                <span
                    class="flex items-center gap-1 text-xs text-muted-foreground"
                    >{{ encendidas.length }} de {{ reglas.length }} encendidas
                    <ChevronRight class="size-4 icono-avanza" />
                </span>
            </span>
            <span class="flex flex-wrap gap-1.5">
                <span
                    v-for="r in reglas"
                    :key="r.clave"
                    class="rounded-full px-2.5 py-1 text-xs"
                    :class="
                        r.activa
                            ? 'bg-success-soft text-success'
                            : 'border border-dashed border-border text-muted-foreground'
                    "
                    >{{ r.nombre }}</span
                >
            </span>
        </Link>
    </div>
</template>
