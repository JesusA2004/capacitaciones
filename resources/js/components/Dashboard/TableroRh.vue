<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { TableroRhDatos } from '@/types';

const props = defineProps<{ tablero: TableroRhDatos }>();

const mes = ref(props.tablero.filters.mes);
const sucursal = ref(
    props.tablero.filters.sucursal_id
        ? String(props.tablero.filters.sucursal_id)
        : '',
);

function filtrar() {
    router.reload({
        data: {
            tablero_mes: mes.value,
            tablero_sucursal_id: sucursal.value || null,
        },
        only: ['tablero'],
    });
}

const moneda = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    maximumFractionDigits: 0,
});
const numero = new Intl.NumberFormat('es-MX');

const s = computed(() => props.tablero.summary);

const tarjetas = computed(() => [
    {
        clave: 'plantilla',
        titulo: 'Plantilla activa',
        valor: numero.format(s.value.plantilla_activa.valor),
        detalle: `de ${numero.format(s.value.plantilla_activa.autorizada)} autorizada · ${s.value.plantilla_activa.porcentaje}%`,
    },
    {
        clave: 'vacantes',
        titulo: 'Vacantes abiertas',
        valor: numero.format(s.value.vacantes_abiertas.valor),
        detalle: `${s.value.vacantes_abiertas.sucursales} sucursales · ${s.value.vacantes_abiertas.corporativo} corporativo`,
    },
    {
        clave: 'rotacion',
        titulo: 'Rotación del mes',
        valor: `${s.value.rotacion_mes.porcentaje}%`,
        detalle: `${s.value.rotacion_mes.bajas} bajas en el mes`,
    },
    {
        clave: 'costo',
        titulo: 'Costo por contratación',
        valor:
            s.value.costo_por_contratacion.valor === null
                ? '—'
                : moneda.format(s.value.costo_por_contratacion.valor),
        detalle: 'campañas ÷ contratados',
    },
    {
        clave: 'tiempo',
        titulo: 'Tiempo de contratación',
        valor:
            s.value.tiempo_contratacion.dias === null
                ? '—'
                : `${s.value.tiempo_contratacion.dias} días`,
        detalle: `postulación → contratación · ${s.value.tiempo_contratacion.contratados} contratados`,
    },
    {
        clave: 'permanencia',
        titulo: 'Permanencia promedio',
        valor:
            s.value.permanencia_promedio.meses === null
                ? '—'
                : `${s.value.permanencia_promedio.meses} meses`,
        detalle: 'antigüedad de la plantilla vigente',
    },
    {
        clave: 'vencer',
        titulo: 'Contratos por vencer',
        valor: numero.format(s.value.contratos_por_vencer.valor),
        detalle: 'periodos de prueba · próximos 30 días',
    },
    {
        clave: 'inversion',
        titulo: 'Inversión en campañas',
        valor: moneda.format(s.value.inversion_campanas_mes.valor),
        detalle: `${s.value.inversion_campanas_mes.campanas} campañas en el mes`,
    },
]);

const maxEmbudo = computed(() =>
    Math.max(1, ...props.tablero.recruitment_funnel.map((e) => e.total)),
);
const maxTiempo = computed(() =>
    Math.max(1, ...props.tablero.time_to_hire_by_level.map((e) => e.dias ?? 0)),
);
const maxRotacion = computed(() =>
    Math.max(1, ...props.tablero.turnover_monthly.map((m) => m.porcentaje)),
);
</script>

<template>
    <section
        class="flex flex-col gap-5 rounded-3xl bg-[var(--mrl-fondo)] p-4 sm:p-6"
        aria-label="Tablero de Recursos Humanos"
    >
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1
                    class="text-lg font-semibold tracking-tight text-[var(--mrl-petroleo)] sm:text-xl"
                >
                    MR. LANA PEOPLE · Tablero de Recursos Humanos
                </h1>
                <p class="text-sm text-[var(--mrl-texto-suave)]">
                    Corte al mes en curso ·
                    {{ tablero.filters.periodo_etiqueta }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input
                    v-model="mes"
                    type="month"
                    class="h-9 rounded-lg border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 text-sm"
                    aria-label="Mes"
                    @change="filtrar"
                />
                <select
                    v-if="tablero.filters.sucursales.length > 1"
                    v-model="sucursal"
                    class="h-9 rounded-lg border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 text-sm"
                    aria-label="Sucursal"
                    @change="filtrar"
                >
                    <option value="">Todo mi alcance</option>
                    <option
                        v-for="op in tablero.filters.sucursales"
                        :key="op.id"
                        :value="String(op.id)"
                    >
                        {{ op.nombre }}
                    </option>
                </select>
            </div>
        </header>

        <div
            data-tour="dashboard-kpis"
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <article
                v-for="(t, i) in tarjetas"
                :key="t.clave"
                class="flex flex-col gap-1 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <p
                    class="text-xs font-medium tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                >
                    {{ t.titulo }}
                </p>
                <p
                    class="text-2xl font-semibold tabular-nums"
                    :class="
                        i === 0
                            ? 'text-[var(--mrl-petroleo)]'
                            : 'text-[var(--mrl-texto)]'
                    "
                >
                    {{ t.valor }}
                </p>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    {{ t.detalle }}
                </p>
            </article>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            <article
                data-tour="dashboard-embudo"
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
            >
                <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                    Embudo de reclutamiento
                </h2>
                <p class="mb-4 text-xs text-[var(--mrl-texto-suave)]">
                    Personas que alcanzaron cada etapa en el periodo
                </p>
                <ul class="flex flex-col gap-2.5">
                    <li
                        v-for="e in tablero.recruitment_funnel"
                        :key="e.clave"
                        class="group grid grid-cols-[96px_1fr_48px] items-center gap-3 text-sm"
                        :title="`${e.etiqueta}: ${e.total}`"
                    >
                        <span class="truncate text-[var(--mrl-texto)]">{{
                            e.etiqueta
                        }}</span>
                        <span class="h-3 rounded-full bg-[var(--mrl-fondo)]">
                            <span
                                class="block h-full rounded-full bg-[var(--mrl-petroleo)] transition-opacity group-hover:opacity-80"
                                :style="{
                                    width: `${(e.total / maxEmbudo) * 100}%`,
                                    minWidth: e.total ? '6px' : '0',
                                }"
                            />
                        </span>
                        <span
                            class="text-right text-[var(--mrl-texto)] tabular-nums"
                            >{{ numero.format(e.total) }}</span
                        >
                    </li>
                </ul>
            </article>

            <article
                data-tour="dashboard-tiempo"
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
            >
                <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                    Tiempo de contratación por nivel
                </h2>
                <p class="mb-4 text-xs text-[var(--mrl-texto-suave)]">
                    Días promedio de postulación a contratación
                </p>
                <ul class="flex flex-col gap-2.5">
                    <li
                        v-for="e in tablero.time_to_hire_by_level"
                        :key="e.clave"
                        class="group grid grid-cols-[96px_1fr_64px] items-center gap-3 text-sm"
                        :title="
                            e.dias === null
                                ? `${e.etiqueta}: sin contrataciones`
                                : `${e.etiqueta}: ${e.dias} días (${e.contratados} contratados)`
                        "
                    >
                        <span class="truncate text-[var(--mrl-texto)]">{{
                            e.etiqueta
                        }}</span>
                        <span class="h-3 rounded-full bg-[var(--mrl-fondo)]">
                            <span
                                class="block h-full rounded-full bg-[var(--mrl-cyan)] transition-opacity group-hover:opacity-80"
                                :style="{
                                    width: `${((e.dias ?? 0) / maxTiempo) * 100}%`,
                                    minWidth: e.dias ? '6px' : '0',
                                }"
                            />
                        </span>
                        <span
                            class="text-right text-[var(--mrl-texto)] tabular-nums"
                            >{{ e.dias === null ? '—' : `${e.dias} d` }}</span
                        >
                    </li>
                </ul>
            </article>
        </div>

        <article
            data-tour="dashboard-rotacion"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        >
            <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                Rotación mensual
            </h2>
            <p class="mb-4 text-xs text-[var(--mrl-texto-suave)]">
                Bajas del mes ÷ plantilla promedio
            </p>
            <div
                class="flex h-44 items-end gap-2 border-b border-[var(--mrl-borde)] pb-0"
            >
                <div
                    v-for="m in tablero.turnover_monthly"
                    :key="m.mes"
                    class="group flex h-full flex-1 flex-col items-center justify-end gap-1"
                    :title="`${m.etiqueta}: ${m.porcentaje}% · ${m.bajas} bajas`"
                >
                    <span
                        class="text-[11px] text-[var(--mrl-texto-suave)] tabular-nums opacity-0 transition-opacity group-hover:opacity-100"
                        >{{ m.porcentaje }}%</span
                    >
                    <span
                        class="w-full max-w-10 rounded-t-[4px] transition-opacity group-hover:opacity-80"
                        :class="
                            m.mes === tablero.filters.mes
                                ? 'bg-[var(--mrl-petroleo)]'
                                : 'bg-[var(--mrl-gris-verdoso)]'
                        "
                        :style="{
                            height: `${(m.porcentaje / maxRotacion) * 100}%`,
                            minHeight: m.porcentaje ? '4px' : '0',
                        }"
                    />
                </div>
            </div>
            <div class="mt-1 flex gap-2">
                <span
                    v-for="m in tablero.turnover_monthly"
                    :key="m.mes"
                    class="flex-1 text-center text-[11px] text-[var(--mrl-texto-suave)]"
                    >{{ m.etiqueta }}</span
                >
            </div>
        </article>
    </section>
</template>
