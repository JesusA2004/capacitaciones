<script setup lang="ts">
import {
    CalendarClock,
    Info,
    Loader2,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CamposFiltrosRotacion from '@/components/rotacion/CamposFiltrosRotacion.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatearFecha, formatearPeriodo } from '@/lib/fechas';
import {
    etiquetaMes,
    MESES_LARGOS,
    numero,
    useNavegacionRotacion,
} from '@/lib/rotacion';
import type {
    FiltrosRotacionTablero,
    OpcionesRotacion,
    PeriodoRotacion,
} from '@/types';

/**
 * Barra compacta de filtros: periodo siempre a la vista; alcance y detalle
 * en línea (escritorio, con «Más filtros») o en una hoja (móvil). Debajo,
 * el corte y lo que la fuente de datos no puede responder: nunca un cero
 * engañoso.
 */
const props = defineProps<{
    filtros: FiltrosRotacionTablero;
    opciones: OpcionesRotacion;
    corteParcial: boolean;
    noDesglosables: number;
    mesesRecortados: string[];
}>();

const nav = useNavegacionRotacion();
const escritorio = useMediaQuery('(min-width: 768px)');
const hojaAbierta = ref(false);
const masFiltros = ref(false);

const PERIODOS: { value: PeriodoRotacion; label: string }[] = [
    { value: 'acumulado', label: 'Acumulado del año' },
    { value: 'anio', label: 'Año completo' },
    { value: 'mes', label: 'Mes' },
    { value: 'rango', label: 'Rango de fechas' },
];

const anios = computed(() =>
    (props.opciones.anios.length
        ? props.opciones.anios
        : [props.filtros.anio]
    ).map((a) => ({ value: a, label: String(a) })),
);
const meses = MESES_LARGOS.map((m, i) => ({ value: i + 1, label: m }));

const desde = ref(props.filtros.desde);
const hasta = ref(props.filtros.hasta);

watch(
    () => [props.filtros.desde, props.filtros.hasta],
    ([d, h]) => {
        desde.value = d;
        hasta.value = h;
    },
);

function cambiarPeriodo(periodo: PeriodoRotacion | null): void {
    const p = periodo ?? 'acumulado';

    nav.actualizar({
        periodo: p,
        mes:
            p === 'mes'
                ? (props.filtros.mes ??
                  (props.filtros.anio === new Date().getFullYear()
                      ? new Date().getMonth() + 1
                      : 12))
                : null,
        desde: p === 'rango' ? props.filtros.desde : null,
        hasta: p === 'rango' ? props.filtros.hasta : null,
    });
}

function cambiarRango(nuevoDesde: string, nuevoHasta: string): void {
    desde.value = nuevoDesde;
    hasta.value = nuevoHasta;

    if (nuevoDesde && nuevoHasta) {
        nav.actualizar({
            periodo: 'rango',
            desde: nuevoDesde,
            hasta: nuevoHasta,
        });
    }
}

const DETALLE = [
    'area',
    'puesto',
    'clasificacion',
    'causal',
    'antiguedad',
] as const;
const ALCANCE = ['empresa', 'sucursal', 'fuente'] as const;

const conDetalle = computed(
    () =>
        props.filtros.departamento_id !== null ||
        props.filtros.puesto_id !== null ||
        props.filtros.clasificacion !== null ||
        props.filtros.causal_analitica_id !== null ||
        props.filtros.antiguedad !== null,
);

const activosDetalle = computed(
    () =>
        [
            props.filtros.departamento_id,
            props.filtros.puesto_id,
            props.filtros.clasificacion,
            props.filtros.causal_analitica_id,
            props.filtros.antiguedad,
        ].filter((v) => v !== null).length,
);

const activosTotal = computed(
    () =>
        activosDetalle.value +
        [props.filtros.empresa_id, props.filtros.sucursal_id].filter(
            (v) => v !== null,
        ).length +
        (props.filtros.fuente !== 'todas' ? 1 : 0),
);

const hayFiltros = computed(
    () =>
        activosTotal.value > 0 ||
        props.filtros.periodo !== 'acumulado' ||
        props.filtros.anio !== new Date().getFullYear() ||
        Object.keys(nav.consulta.value).some((k) => k.startsWith('d_')) ||
        'rapido' in nav.consulta.value ||
        'q' in nav.consulta.value,
);

// Abre «Más filtros» si se llegó con un filtro de detalle activo.
watch(
    activosDetalle,
    (n) => {
        if (n > 0) {
            masFiltros.value = true;
        }
    },
    { immediate: true },
);

const textoPeriodo = computed(() =>
    formatearPeriodo(props.filtros.desde, props.filtros.hasta),
);
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-3 shadow-sm sm:p-4"
        aria-label="Filtros de rotación"
    >
        <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-0 basis-full sm:basis-48">
                <Label class="mb-1 text-xs text-muted-foreground"
                    >Periodo</Label
                >
                <SelectSimple
                    :model-value="filtros.periodo"
                    :opciones="PERIODOS"
                    aria-label="Periodo"
                    @update:model-value="cambiarPeriodo"
                />
            </div>
            <div
                v-if="filtros.periodo !== 'rango'"
                class="min-w-0 flex-1 sm:w-28 sm:flex-none"
            >
                <Label class="mb-1 text-xs text-muted-foreground">Año</Label>
                <SelectSimple
                    :model-value="filtros.anio"
                    :opciones="anios"
                    numerico
                    aria-label="Año"
                    @update:model-value="(v) => nav.actualizar({ anio: v })"
                />
            </div>
            <div
                v-if="filtros.periodo === 'mes'"
                class="min-w-0 flex-1 sm:w-40 sm:flex-none"
            >
                <Label class="mb-1 text-xs text-muted-foreground">Mes</Label>
                <SelectSimple
                    :model-value="filtros.mes"
                    :opciones="meses"
                    numerico
                    aria-label="Mes"
                    @update:model-value="(v) => nav.actualizar({ mes: v })"
                />
            </div>
            <template v-if="filtros.periodo === 'rango'">
                <div class="min-w-0 flex-1 sm:w-48 sm:flex-none">
                    <Label class="mb-1 text-xs text-muted-foreground"
                        >Desde</Label
                    >
                    <DatePicker
                        :model-value="desde"
                        :max-value="hasta"
                        @update:model-value="(v) => cambiarRango(v, hasta)"
                    />
                </div>
                <div class="min-w-0 flex-1 sm:w-48 sm:flex-none">
                    <Label class="mb-1 text-xs text-muted-foreground"
                        >Hasta</Label
                    >
                    <DatePicker
                        :model-value="hasta"
                        :min-value="desde"
                        @update:model-value="(v) => cambiarRango(desde, v)"
                    />
                </div>
            </template>

            <CamposFiltrosRotacion
                v-if="escritorio"
                :filtros="filtros"
                :opciones="opciones"
                :campos="[...ALCANCE]"
                class="min-w-0 flex-1 basis-[30rem]"
            />

            <div class="ml-auto flex items-center gap-2">
                <Button
                    v-if="escritorio"
                    variant="outline"
                    :aria-expanded="masFiltros"
                    @click="masFiltros = !masFiltros"
                >
                    <SlidersHorizontal class="size-4" />
                    Más filtros
                    <span
                        v-if="activosDetalle"
                        class="rounded-full bg-oro/15 px-1.5 text-xs text-bronce tabular-nums"
                        >{{ activosDetalle }}</span
                    >
                </Button>
                <Button
                    v-else
                    variant="outline"
                    class="flex-1"
                    @click="hojaAbierta = true"
                >
                    <SlidersHorizontal class="size-4" />
                    Filtros
                    <span
                        v-if="activosTotal"
                        class="rounded-full bg-oro/15 px-1.5 text-xs text-bronce tabular-nums"
                        >{{ activosTotal }}</span
                    >
                </Button>
                <Button
                    v-if="hayFiltros"
                    variant="ghost"
                    class="text-muted-foreground"
                    @click="nav.limpiar()"
                >
                    <X class="size-4" />
                    Limpiar filtros
                </Button>
            </div>
        </div>

        <Transition name="desplegar">
            <div
                v-if="escritorio && masFiltros"
                class="rounded-2xl bg-crema/40 p-3"
            >
                <CamposFiltrosRotacion
                    :filtros="filtros"
                    :opciones="opciones"
                    :campos="[...DETALLE]"
                />
                <p class="mt-2 text-xs text-muted-foreground">
                    Estos filtros solo pueden responderse con bajas
                    individuales: el histórico agregado del Excel no trae
                    puesto, área, causal ni antigüedad.
                </p>
            </div>
        </Transition>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1.5">
                <CalendarClock class="size-3.5" aria-hidden="true" />
                <span>
                    {{ textoPeriodo }} · Corte al
                    <strong class="font-medium text-foreground">{{
                        formatearFecha(filtros.hasta)
                    }}</strong>
                </span>
                <span
                    v-if="corteParcial"
                    class="rounded-full bg-warning-soft px-2 py-0.5 text-warning"
                    >Corte parcial</span
                >
            </span>
            <Loader2
                v-if="nav.cargando.value"
                class="size-3.5 animate-spin text-primary"
                aria-label="Actualizando"
            />
        </div>

        <ul
            v-if="noDesglosables > 0 || mesesRecortados.length || conDetalle"
            class="flex flex-col gap-1.5 text-xs"
        >
            <li
                v-if="noDesglosables > 0"
                class="flex items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-warning"
            >
                <Info class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                <span
                    ><strong class="tabular-nums">{{
                        numero(noDesglosables)
                    }}</strong>
                    {{
                        noDesglosables === 1
                            ? 'baja del histórico agregado no se puede desglosar'
                            : 'bajas del histórico agregado no se pueden desglosar'
                    }}
                    con este filtro: no se cuentan como cero, simplemente no hay
                    detalle.</span
                >
            </li>
            <li
                v-if="mesesRecortados.length"
                class="flex items-start gap-2 rounded-xl bg-info-soft px-3 py-2 text-info"
            >
                <Info class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                <span
                    >El total mensual del Excel de
                    {{ mesesRecortados.map((m) => etiquetaMes(m)).join(', ') }}
                    no entra porque el rango no cubre el mes completo.</span
                >
            </li>
            <li
                v-if="conDetalle"
                class="flex items-start gap-2 rounded-xl bg-muted px-3 py-2"
            >
                <Info class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                <span
                    >Con filtros de detalle el índice de rotación no aplica: no
                    hay plantilla por puesto, causal ni antigüedad.</span
                >
            </li>
        </ul>

        <Sheet v-model:open="hojaAbierta">
            <SheetContent side="bottom" class="max-h-[85svh] rounded-t-3xl">
                <SheetHeader>
                    <SheetTitle>Filtros</SheetTitle>
                    <SheetDescription
                        >Cada cambio actualiza el tablero al
                        momento.</SheetDescription
                    >
                </SheetHeader>
                <div class="px-4 pb-6">
                    <CamposFiltrosRotacion
                        :filtros="filtros"
                        :opciones="opciones"
                        :campos="[...ALCANCE, ...DETALLE]"
                        apilado
                    />
                    <Button class="mt-4 w-full" @click="hojaAbierta = false"
                        >Ver resultados</Button
                    >
                </div>
            </SheetContent>
        </Sheet>
    </section>
</template>

<style scoped>
.desplegar-enter-active,
.desplegar-leave-active {
    transition:
        opacity 180ms ease,
        transform 200ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

.desplegar-enter-from,
.desplegar-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
    .desplegar-enter-active,
    .desplegar-leave-active {
        transition: none;
    }
}
</style>
