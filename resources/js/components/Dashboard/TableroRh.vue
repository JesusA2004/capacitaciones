<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowRight, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { index as pendientesIndex } from '@/routes/rh/pendientes';
import type {
    InicioOperativoDatos,
    SemaforoRotacion,
    TableroRhDatos,
} from '@/types';

/**
 * Tablero de RH (Inicio operativo, pág. 3 de la propuesta). Un solo DTO del
 * backend (TableroRhService, el mismo de la API móvil); cada filtro recarga
 * SOLO `tablero`, así los cuatro bloques y las cinco gráficas cambian juntos.
 * Cada dato aparece una sola vez en la pantalla.
 */
const props = defineProps<{
    inicio: InicioOperativoDatos;
    tablero: TableroRhDatos;
}>();

const TODAS = 'todas';

const mes = ref(props.tablero.filters.mes);
const empresa = ref(
    props.tablero.filters.empresa_id
        ? String(props.tablero.filters.empresa_id)
        : TODAS,
);
const sucursal = ref(
    props.tablero.filters.sucursal_id
        ? String(props.tablero.filters.sucursal_id)
        : TODAS,
);
const meses = ref(props.tablero.filters.meses ?? 12);
const cargando = ref(false);

const sucursalesDeEmpresa = computed(() =>
    empresa.value === TODAS
        ? props.tablero.filters.sucursales
        : props.tablero.filters.sucursales.filter(
              (x) => String(x.empresa_id) === empresa.value,
          ),
);

function filtrar() {
    router.reload({
        data: {
            tablero_mes: mes.value,
            tablero_empresa_id: empresa.value === TODAS ? null : empresa.value,
            tablero_sucursal_id:
                sucursal.value === TODAS ? null : sucursal.value,
            tablero_meses: meses.value,
        },
        only: ['tablero'],
        onStart: () => (cargando.value = true),
        onFinish: () => (cargando.value = false),
    });
}

watch(empresa, () => {
    if (
        sucursal.value !== TODAS &&
        !sucursalesDeEmpresa.value.some((x) => String(x.id) === sucursal.value)
    ) {
        sucursal.value = TODAS;
    }
});
watch([mes, empresa, sucursal, meses], filtrar);

const hayFiltros = computed(
    () =>
        empresa.value !== TODAS ||
        sucursal.value !== TODAS ||
        meses.value !== 12 ||
        mes.value !== new Date().toISOString().slice(0, 7),
);

function limpiar() {
    mes.value = new Date().toISOString().slice(0, 7);
    empresa.value = TODAS;
    sucursal.value = TODAS;
    meses.value = 12;
}

const saludo = computed(() => {
    const hora = new Date().getHours();
    const parte =
        hora < 12
            ? 'Buenos días'
            : hora < 19
              ? 'Buenas tardes'
              : 'Buenas noches';

    return props.inicio.nombre ? `${parte}, ${props.inicio.nombre}` : parte;
});

const moneda = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    maximumFractionDigits: 0,
});
const numero = new Intl.NumberFormat('es-MX');

const s = computed(() => props.tablero.summary);
const poblacion = computed(() => s.value.plantilla_activa.poblacion);

// --- Semáforos ---
const TEXTO_SEMAFORO: Record<SemaforoRotacion, string> = {
    verde: 'text-semaforo-baja',
    ambar: 'text-semaforo-ambar',
    rojo: 'text-semaforo-sube',
};
const FONDO_SEMAFORO: Record<SemaforoRotacion, string> = {
    verde: 'bg-semaforo-baja',
    ambar: 'bg-semaforo-ambar',
    rojo: 'bg-semaforo-sube',
};

/** Cobertura: verde ≥ 95 %, ámbar de 85 a menos de 95 %, rojo < 85 %. */
function semaforoCobertura(porcentaje: number): SemaforoRotacion {
    return porcentaje >= 95 ? 'verde' : porcentaje >= 85 ? 'ambar' : 'rojo';
}

// --- Rotación mensual: barras con su valor, semáforo y meta punteada ---
const rotacion = computed(() => props.tablero.turnover_monthly);
const metaGlobal = computed(
    () =>
        [...rotacion.value].reverse().find((m) => m.meta !== null)?.meta ??
        null,
);
const escalaRotacion = computed(() => {
    const valores = rotacion.value
        .map((m) => m.indice)
        .filter((v): v is number => v !== null);
    const tope = Math.max(
        metaGlobal.value?.ambar_hasta ?? 0,
        metaGlobal.value?.verde_hasta ?? 0,
        ...valores,
        1,
    );

    return Math.ceil(tope * 1.15);
});
const lineasRotacion = computed(() =>
    [0, 1, 2, 3].map(
        (i) => Math.round((escalaRotacion.value / 3) * (3 - i) * 10) / 10,
    ),
);
const alturaRotacion = (valor: number) => (valor / escalaRotacion.value) * 100;
const sinRotacion = computed(() =>
    rotacion.value.every((m) => m.indice === null),
);

// --- Cobertura de plantilla ---
const coberturaTotal = computed(() => poblacion.value.total.cobertura);
const ubicaciones = computed(() =>
    [
        { etiqueta: 'Corporativo', datos: poblacion.value.corporativo },
        { etiqueta: 'Sucursales', datos: poblacion.value.sucursales },
    ].filter((u) => u.datos.autorizadas > 0 || u.datos.activos > 0),
);

// --- Embudo: personas que alcanzaron cada fase y su paso desde la anterior ---
const embudo = computed(() => {
    const etapas = props.tablero.recruitment_funnel;
    const max = Math.max(1, ...etapas.map((e) => e.total));

    return etapas.map((e, i) => ({
        ...e,
        ancho: (e.total / max) * 100,
        conversion:
            i === 0 || !etapas[i - 1].total
                ? null
                : Math.round((e.total / etapas[i - 1].total) * 100),
    }));
});
const conversionTotal = computed(() => {
    const etapas = props.tablero.recruitment_funnel;
    const inicio = etapas[0]?.total ?? 0;

    return inicio > 0
        ? Math.round(((etapas.at(-1)?.total ?? 0) / inicio) * 1000) / 10
        : null;
});

// --- Tiempo por nivel: la escala la marca el valor mayor, nada se recorta ---
const tiempos = computed(() => {
    const filas = props.tablero.time_to_hire_by_level;
    const max = Math.max(1, ...filas.map((t) => t.dias ?? 0));

    return filas.map((t) => ({ ...t, ancho: ((t.dias ?? 0) / max) * 100 }));
});
const sinTiempos = computed(() => tiempos.value.every((t) => t.dias === null));

// --- Plantilla por sucursal: misma escala (100 % = su autorizada) ---
const orden = ref<'cobertura' | 'vacantes' | 'nombre'>('cobertura');
const sucursalesCobertura = computed(() => {
    const filas = props.tablero.headcount_by_branch.filter(
        (f) => f.plantilla_autorizada > 0 || f.plantilla_actual > 0,
    );

    return [...filas].sort((a, b) => {
        // Corporativo siempre al final, sea cual sea el orden.
        if (a.es_corporativo !== b.es_corporativo) {
            return a.es_corporativo ? 1 : -1;
        }

        if (orden.value === 'nombre') {
            return a.sucursal.localeCompare(b.sucursal);
        }

        return orden.value === 'vacantes'
            ? b.vacantes - a.vacantes
            : a.cumplimiento - b.cumplimiento;
    });
});
</script>

<template>
    <section
        class="flex flex-col gap-3"
        :class="cargando && 'pointer-events-none'"
        aria-label="Tablero de Recursos Humanos"
        :aria-busy="cargando"
    >
        <header
            class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between"
        >
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight text-primary">
                    {{ saludo }}
                    <span
                        class="ml-1 text-sm font-normal text-muted-foreground"
                    >
                        <span class="tabular-nums">{{
                            numero.format(inicio.pendientes)
                        }}</span>
                        pendientes<template v-if="inicio.vencidos > 0">
                            ·
                            <span
                                class="font-semibold text-semaforo-sube tabular-nums"
                                >{{ numero.format(inicio.vencidos) }}
                                {{
                                    inicio.vencidos === 1
                                        ? 'vencido'
                                        : 'vencidos'
                                }}</span
                            ></template
                        >
                    </span>
                </h1>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <MonthPicker v-model="mes" class="w-44" />
                <SelectSimple
                    v-if="tablero.filters.empresas.length > 1"
                    v-model="empresa"
                    :opciones="[
                        { value: TODAS, label: 'Todas las empresas' },
                        ...tablero.filters.empresas.map((e) => ({
                            value: String(e.id),
                            label: e.nombre,
                        })),
                    ]"
                    placeholder="Empresa"
                    aria-label="Empresa"
                    placeholder-busqueda="Buscar empresa…"
                    class="h-9 w-44"
                />
                <SelectSimple
                    v-if="tablero.filters.sucursales.length > 1"
                    v-model="sucursal"
                    :opciones="[
                        { value: TODAS, label: 'Todas las sucursales' },
                        ...sucursalesDeEmpresa.map((op) => ({
                            value: String(op.id),
                            label: op.nombre,
                        })),
                    ]"
                    placeholder="Sucursal"
                    aria-label="Sucursal"
                    placeholder-busqueda="Buscar sucursal…"
                    class="h-9 w-48"
                />
                <div
                    class="inline-flex items-center gap-1"
                    role="radiogroup"
                    aria-label="Periodo del tablero"
                >
                    <button
                        v-for="n in [6, 12, 24]"
                        :key="n"
                        type="button"
                        role="radio"
                        :aria-checked="meses === n"
                        class="h-9 rounded-full border px-3 text-xs font-semibold transition-colors"
                        :class="
                            meses === n
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'bg-card text-muted-foreground hover:text-foreground'
                        "
                        @click="meses = n"
                    >
                        {{ n }} m
                    </button>
                </div>
                <Button
                    v-if="hayFiltros"
                    variant="ghost"
                    size="sm"
                    class="h-9"
                    @click="limpiar"
                >
                    <RotateCcw class="size-4" /> Limpiar
                </Button>
                <Button v-if="inicio.pendientes > 0" as-child class="h-9">
                    <Link :href="pendientesIndex.url()">
                        Ver pendientes <ArrowRight class="size-4" />
                    </Link>
                </Button>
            </div>
        </header>

        <div
            class="flex flex-col gap-3 transition-opacity duration-200"
            :class="cargando ? 'opacity-50' : 'opacity-100'"
        >
            <!-- Cuatro bloques: cada indicador aparece una sola vez. -->
            <div
                data-tour="dashboard-kpis"
                class="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
            >
                <article class="bloque border-t-primary">
                    <h2 class="bloque-titulo text-primary">Plantilla</h2>
                    <dl class="bloque-datos">
                        <div>
                            <dt>Autorizada</dt>
                            <dd>
                                {{ numero.format(poblacion.total.autorizadas) }}
                            </dd>
                            <p>
                                Corporativo
                                {{ poblacion.corporativo.autorizadas }} ·
                                Sucursales
                                {{ poblacion.sucursales.autorizadas }}
                            </p>
                        </div>
                        <div>
                            <dt>Activa</dt>
                            <dd>
                                {{ numero.format(poblacion.total.activos) }}
                            </dd>
                            <p>
                                Corporativo
                                {{ poblacion.corporativo.activos }} · Sucursales
                                {{ poblacion.sucursales.activos }}
                            </p>
                        </div>
                        <div>
                            <dt>Vacantes</dt>
                            <dd class="text-bronce">
                                {{ numero.format(poblacion.total.vacantes) }}
                            </dd>
                            <p>
                                Corporativo
                                {{ poblacion.corporativo.vacantes }} ·
                                Sucursales
                                {{ poblacion.sucursales.vacantes }}
                            </p>
                        </div>
                        <div>
                            <dt>Cobertura</dt>
                            <dd
                                :class="
                                    poblacion.total.autorizadas > 0
                                        ? TEXTO_SEMAFORO[
                                              semaforoCobertura(coberturaTotal)
                                          ]
                                        : ''
                                "
                            >
                                {{
                                    poblacion.total.autorizadas > 0
                                        ? `${coberturaTotal}%`
                                        : '—'
                                }}
                            </dd>
                            <p>
                                Meta ≥ 95%<template
                                    v-if="
                                        poblacion.total.fuera_de_plantilla > 0
                                    "
                                >
                                    ·
                                    {{ poblacion.total.fuera_de_plantilla }}
                                    fuera de plantilla</template
                                >
                            </p>
                        </div>
                    </dl>
                </article>

                <article class="bloque border-t-bronce">
                    <h2 class="bloque-titulo text-bronce">
                        Movimiento de personal ·
                        {{ tablero.filters.periodo_etiqueta }}
                    </h2>
                    <dl class="bloque-datos">
                        <div>
                            <dt>Altas del mes</dt>
                            <dd>{{ numero.format(s.altas_mes.valor) }}</dd>
                            <p>Ingresos registrados</p>
                        </div>
                        <div>
                            <dt>Bajas del mes</dt>
                            <dd>{{ numero.format(s.rotacion_mes.bajas) }}</dd>
                            <p>Por fecha de último día</p>
                        </div>
                        <div>
                            <dt>Rotación del mes</dt>
                            <dd
                                :class="
                                    s.rotacion_mes.semaforo
                                        ? TEXTO_SEMAFORO[
                                              s.rotacion_mes.semaforo
                                          ]
                                        : ''
                                "
                            >
                                {{
                                    s.rotacion_mes.porcentaje === null
                                        ? '—'
                                        : `${s.rotacion_mes.porcentaje}%`
                                }}
                            </dd>
                            <p>
                                {{
                                    s.rotacion_mes.porcentaje === null
                                        ? 'Sin datos de plantilla'
                                        : metaGlobal
                                          ? `Meta hasta ${metaGlobal.verde_hasta}%`
                                          : 'Sin meta vigente'
                                }}
                            </p>
                        </div>
                        <div>
                            <dt>Acumulada {{ s.rotacion_acumulada.anio }}</dt>
                            <dd>
                                {{
                                    s.rotacion_acumulada.porcentaje === null
                                        ? '—'
                                        : `${s.rotacion_acumulada.porcentaje}%`
                                }}
                            </dd>
                            <p>
                                {{ numero.format(s.rotacion_acumulada.bajas) }}
                                bajas en el año
                            </p>
                        </div>
                    </dl>
                </article>

                <article class="bloque border-t-forma-renuncia">
                    <h2 class="bloque-titulo text-forma-rescision">
                        Reclutamiento
                    </h2>
                    <dl class="bloque-datos">
                        <div>
                            <dt>Candidatos en proceso</dt>
                            <dd>
                                {{ numero.format(s.reclutamiento.en_proceso) }}
                            </dd>
                            <p>
                                {{ s.reclutamiento.contratados_mes }}
                                contratados en el mes
                            </p>
                        </div>
                        <div>
                            <dt>Tiempo de contratación</dt>
                            <dd>
                                {{
                                    s.tiempo_contratacion.dias === null
                                        ? '—'
                                        : `${s.tiempo_contratacion.dias} días`
                                }}
                            </dd>
                            <p>Postulación a ingreso</p>
                        </div>
                        <div>
                            <dt>Costo por contratación</dt>
                            <dd>
                                {{
                                    s.costo_por_contratacion.valor === null
                                        ? '—'
                                        : moneda.format(
                                              s.costo_por_contratacion.valor,
                                          )
                                }}
                            </dd>
                            <p>
                                {{
                                    s.costo_por_contratacion.valor === null
                                        ? 'Falta inversión o contratados'
                                        : 'Inversión ÷ contratados'
                                }}
                            </p>
                        </div>
                        <div>
                            <dt>Inversión en campañas</dt>
                            <dd>
                                {{
                                    s.inversion_campanas_mes.valor > 0
                                        ? moneda.format(
                                              s.inversion_campanas_mes.valor,
                                          )
                                        : '—'
                                }}
                            </dd>
                            <p>
                                {{ s.inversion_campanas_mes.campanas }}
                                campañas en el mes
                            </p>
                        </div>
                    </dl>
                </article>

                <article class="bloque border-t-semaforo-baja">
                    <h2 class="bloque-titulo text-primary">
                        Permanencia y expediente
                    </h2>
                    <dl class="bloque-datos">
                        <div>
                            <dt>Permanencia promedio</dt>
                            <dd>
                                {{
                                    s.permanencia_promedio.meses === null
                                        ? '—'
                                        : `${s.permanencia_promedio.meses} m`
                                }}
                            </dd>
                            <p>Antigüedad de la plantilla</p>
                        </div>
                        <div>
                            <dt>Contratos por vencer</dt>
                            <dd class="text-bronce">
                                {{
                                    numero.format(s.contratos_por_vencer.valor)
                                }}
                            </dd>
                            <p>
                                Próximos {{ s.contratos_por_vencer.dias }} días
                            </p>
                        </div>
                        <div>
                            <dt>Expedientes incompletos</dt>
                            <dd
                                :class="
                                    s.expedientes_incompletos.valor
                                        ? 'text-semaforo-sube'
                                        : ''
                                "
                            >
                                {{
                                    s.expedientes_incompletos.valor === null
                                        ? '—'
                                        : numero.format(
                                              s.expedientes_incompletos.valor,
                                          )
                                }}
                            </dd>
                            <p>Datos contractuales faltantes</p>
                        </div>
                        <div>
                            <dt>Pendientes vencidos</dt>
                            <dd
                                :class="
                                    inicio.vencidos > 0
                                        ? 'text-semaforo-sube'
                                        : ''
                                "
                            >
                                {{ numero.format(inicio.vencidos) }}
                            </dd>
                            <p>
                                De {{ numero.format(inicio.pendientes) }} por
                                atender
                            </p>
                        </div>
                    </dl>
                </article>
            </div>

            <div
                class="grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]"
            >
                <!-- Rotación mensual -->
                <article data-tour="dashboard-rotacion" class="grafica">
                    <header
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div>
                            <h2 class="grafica-titulo">
                                Rotación mensual · últimos
                                {{ rotacion.length }} meses
                            </h2>
                            <p class="grafica-nota">
                                Bajas ÷ plantilla promedio · color según la meta
                            </p>
                        </div>
                        <ul
                            v-if="metaGlobal"
                            class="flex gap-3 text-[11px] text-muted-foreground"
                        >
                            <li class="flex items-center gap-1">
                                <span class="punto bg-semaforo-baja" /> ≤
                                {{ metaGlobal.verde_hasta }}%
                            </li>
                            <li class="flex items-center gap-1">
                                <span class="punto bg-semaforo-ambar" />
                                {{ metaGlobal.verde_hasta }}–{{
                                    metaGlobal.ambar_hasta
                                }}%
                            </li>
                            <li class="flex items-center gap-1">
                                <span class="punto bg-semaforo-sube" /> &gt;
                                {{ metaGlobal.ambar_hasta }}%
                            </li>
                        </ul>
                    </header>

                    <p
                        v-if="sinRotacion"
                        class="grid h-60 place-items-center text-center text-sm text-muted-foreground"
                    >
                        Sin datos de bajas ni plantilla para este periodo y
                        alcance.
                    </p>
                    <div v-else class="mt-4 flex gap-2">
                        <!-- Eje Y -->
                        <div
                            class="flex h-52 w-9 shrink-0 flex-col justify-between text-right text-[10px] text-muted-foreground tabular-nums"
                            aria-hidden="true"
                        >
                            <span v-for="l in lineasRotacion" :key="l"
                                >{{ l }}%</span
                            >
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="relative h-52">
                                <div
                                    class="absolute inset-0 flex flex-col justify-between"
                                    aria-hidden="true"
                                >
                                    <span
                                        v-for="l in lineasRotacion"
                                        :key="l"
                                        class="block border-t border-border/60"
                                    />
                                </div>
                                <!-- Meta punteada -->
                                <div
                                    v-if="metaGlobal"
                                    class="absolute inset-x-0 z-10 border-t-2 border-dashed border-primary"
                                    :style="{
                                        bottom: `${alturaRotacion(metaGlobal.verde_hasta)}%`,
                                    }"
                                >
                                    <span
                                        class="absolute -top-4 right-0 text-[10px] font-semibold text-primary"
                                        >Meta
                                        {{ metaGlobal.verde_hasta }}%</span
                                    >
                                </div>
                                <ul
                                    class="absolute inset-0 flex items-end gap-1 sm:gap-2"
                                >
                                    <li
                                        v-for="m in rotacion"
                                        :key="m.mes"
                                        class="flex h-full min-w-0 flex-1 flex-col items-center justify-end"
                                        :title="
                                            m.indice === null
                                                ? `${m.etiqueta}: sin datos`
                                                : `${m.etiqueta}: ${m.indice}% · ${m.bajas} bajas · plantilla promedio ${m.plantilla_promedio ?? '—'}`
                                        "
                                    >
                                        <template v-if="m.indice !== null">
                                            <span
                                                v-if="rotacion.length <= 12"
                                                class="text-[10px] font-semibold tabular-nums"
                                                >{{ m.indice }}</span
                                            >
                                            <span
                                                class="w-full max-w-9 rounded-t-sm transition-[height] duration-500 motion-reduce:transition-none"
                                                :class="[
                                                    m.semaforo
                                                        ? FONDO_SEMAFORO[
                                                              m.semaforo
                                                          ]
                                                        : 'bg-muted-foreground',
                                                    m.en_curso && 'opacity-50',
                                                ]"
                                                :style="{
                                                    height: `${alturaRotacion(m.indice)}%`,
                                                    minHeight: '2px',
                                                }"
                                            />
                                        </template>
                                        <span
                                            v-else
                                            class="text-[10px] text-muted-foreground"
                                            >s/d</span
                                        >
                                    </li>
                                </ul>
                            </div>
                            <ul class="mt-1 flex gap-1 sm:gap-2">
                                <li
                                    v-for="(m, i) in rotacion"
                                    :key="m.mes"
                                    class="min-w-0 flex-1 text-center text-[10px] leading-tight text-muted-foreground"
                                >
                                    <!-- En pantallas chicas se alterna el mes para que quepa completo. -->
                                    <span
                                        v-if="
                                            rotacion.length <= 12 ||
                                            i % 2 === 0 ||
                                            m.en_curso
                                        "
                                        class="block whitespace-nowrap"
                                        :class="
                                            (rotacion.length - 1 - i) % 2 ===
                                                1 && 'invisible sm:visible'
                                        "
                                        >{{ m.etiqueta }}</span
                                    >
                                    <span
                                        v-if="m.en_curso"
                                        class="block text-[9px] font-medium whitespace-nowrap"
                                        >en curso</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </div>
                </article>

                <!-- Cobertura -->
                <article class="grafica">
                    <h2 class="grafica-titulo">Cobertura de plantilla</h2>
                    <p class="grafica-nota">Plazas autorizadas ocupadas</p>

                    <p
                        v-if="poblacion.total.autorizadas === 0"
                        class="grid h-48 place-items-center text-center text-sm text-muted-foreground"
                    >
                        Sin plazas autorizadas en este filtro.
                    </p>
                    <div v-else class="mt-3 flex flex-col items-center gap-4">
                        <div
                            class="dona relative grid size-32 place-items-center rounded-full"
                            :class="
                                TEXTO_SEMAFORO[
                                    semaforoCobertura(coberturaTotal)
                                ]
                            "
                            :style="{
                                background: `conic-gradient(currentColor ${Math.min(100, coberturaTotal) * 3.6}deg, var(--muted) 0deg)`,
                            }"
                            role="img"
                            :aria-label="`${coberturaTotal}% de la plantilla autorizada ocupada`"
                        >
                            <div
                                class="grid size-24 place-items-center rounded-full bg-card text-center"
                            >
                                <div>
                                    <p
                                        class="text-xl font-semibold text-foreground tabular-nums"
                                    >
                                        {{ coberturaTotal }}%
                                    </p>
                                    <p
                                        class="text-[10px] text-muted-foreground"
                                    >
                                        {{ poblacion.total.autorizadas }} plazas
                                    </p>
                                </div>
                            </div>
                        </div>
                        <ul class="flex w-full flex-col gap-3">
                            <li v-for="u in ubicaciones" :key="u.etiqueta">
                                <p
                                    class="flex justify-between gap-2 text-xs font-medium"
                                >
                                    {{ u.etiqueta }}
                                    <span class="tabular-nums"
                                        >{{ u.datos.ocupadas }} /
                                        {{ u.datos.autorizadas }} ·
                                        {{ u.datos.cobertura }}%</span
                                    >
                                </p>
                                <div
                                    class="mt-1 h-2 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none"
                                        :class="
                                            FONDO_SEMAFORO[
                                                semaforoCobertura(
                                                    u.datos.cobertura,
                                                )
                                            ]
                                        "
                                        :style="{
                                            width: `${Math.min(100, u.datos.cobertura)}%`,
                                        }"
                                    />
                                </div>
                            </li>
                        </ul>
                        <ul
                            class="flex flex-wrap justify-center gap-3 text-[11px] text-muted-foreground"
                        >
                            <li class="flex items-center gap-1">
                                <span class="punto bg-semaforo-baja" /> ≥95%
                            </li>
                            <li class="flex items-center gap-1">
                                <span class="punto bg-semaforo-ambar" /> 85–94%
                            </li>
                            <li class="flex items-center gap-1">
                                <span class="punto bg-semaforo-sube" /> &lt;85%
                            </li>
                        </ul>
                    </div>
                </article>

                <!-- Embudo -->
                <article data-tour="dashboard-embudo" class="grafica">
                    <h2 class="grafica-titulo">Embudo de reclutamiento</h2>
                    <p class="grafica-nota">
                        Personas por etapa · % de paso desde la anterior ·
                        últimos {{ tablero.filters.meses }} meses
                    </p>
                    <ul class="mt-3 flex flex-col gap-2">
                        <li
                            v-for="e in embudo"
                            :key="e.clave"
                            class="grid grid-cols-[5.5rem_minmax(0,1fr)_1.75rem_2.25rem] items-center gap-2 text-xs"
                        >
                            <span class="truncate">{{ e.etiqueta }}</span>
                            <span class="flex h-4 justify-center">
                                <span
                                    class="h-full rounded-sm bg-forma-renuncia transition-[width] duration-500 motion-reduce:transition-none"
                                    :style="{
                                        width: `${e.ancho}%`,
                                        minWidth: e.total ? '4px' : '0',
                                    }"
                                />
                            </span>
                            <span
                                class="text-right font-semibold tabular-nums"
                                >{{ numero.format(e.total) }}</span
                            >
                            <span
                                class="text-right text-[11px] text-muted-foreground tabular-nums"
                                >{{
                                    e.conversion !== null
                                        ? `${e.conversion}%`
                                        : ''
                                }}</span
                            >
                        </li>
                    </ul>
                    <p
                        v-if="conversionTotal !== null"
                        class="mt-3 text-xs text-muted-foreground"
                    >
                        Conversión total:
                        <span class="font-semibold text-semaforo-baja"
                            >{{ conversionTotal }}%</span
                        >
                    </p>
                </article>
            </div>

            <div class="grid gap-3 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <!-- Plantilla por sucursal -->
                <article class="grafica">
                    <header
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div>
                            <h2 class="grafica-titulo">
                                Plantilla por sucursal
                            </h2>
                            <p class="grafica-nota">
                                Activos / autorizada · misma escala para todas ·
                                clic para filtrar el tablero
                            </p>
                        </div>
                        <div
                            class="flex flex-wrap gap-1"
                            role="radiogroup"
                            aria-label="Ordenar sucursales"
                        >
                            <button
                                v-for="o in [
                                    { v: 'cobertura', t: 'Menor cobertura' },
                                    { v: 'vacantes', t: 'Más vacantes' },
                                    { v: 'nombre', t: 'Nombre' },
                                ] as const"
                                :key="o.v"
                                type="button"
                                role="radio"
                                :aria-checked="orden === o.v"
                                class="h-7 rounded-full border px-2.5 text-xs font-medium transition-colors"
                                :class="
                                    orden === o.v
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-card text-muted-foreground hover:text-foreground'
                                "
                                @click="orden = o.v"
                            >
                                {{ o.t }}
                            </button>
                        </div>
                    </header>

                    <p
                        v-if="sucursalesCobertura.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Sin plantilla autorizada en este filtro.
                    </p>
                    <ul
                        v-else
                        class="mt-3 grid gap-x-6 gap-y-0.5 md:grid-cols-2"
                    >
                        <li
                            v-for="f in sucursalesCobertura"
                            :key="f.sucursal_id"
                        >
                            <button
                                type="button"
                                class="grid w-full grid-cols-[minmax(0,6.5rem)_minmax(0,1fr)_2.75rem_3rem] items-center gap-2 rounded-md px-1.5 py-1 text-left text-xs transition-colors hover:bg-muted/60"
                                :class="
                                    f.es_corporativo &&
                                    'bg-crema/60 font-semibold'
                                "
                                :title="`${f.sucursal}: ${f.plantilla_actual} de ${f.plantilla_autorizada} plazas ocupadas · ${f.vacantes} vacantes`"
                                @click="sucursal = String(f.sucursal_id)"
                            >
                                <span class="truncate">{{ f.sucursal }}</span>
                                <span
                                    class="h-2 overflow-hidden rounded-full bg-muted"
                                >
                                    <span
                                        class="block h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none"
                                        :class="
                                            FONDO_SEMAFORO[
                                                semaforoCobertura(
                                                    f.cumplimiento,
                                                )
                                            ]
                                        "
                                        :style="{
                                            width: `${Math.min(100, f.cumplimiento)}%`,
                                        }"
                                    />
                                </span>
                                <span
                                    class="text-right font-medium tabular-nums"
                                    >{{ f.plantilla_actual }}/{{
                                        f.plantilla_autorizada
                                    }}</span
                                >
                                <span
                                    v-if="f.plantilla_autorizada > 0"
                                    class="rounded-full px-1 py-0.5 text-center text-[10px] font-semibold text-white tabular-nums"
                                    :class="
                                        FONDO_SEMAFORO[
                                            semaforoCobertura(f.cumplimiento)
                                        ]
                                    "
                                    >{{ Math.round(f.cumplimiento) }}%</span
                                >
                                <span
                                    v-else
                                    class="text-center text-[10px] text-muted-foreground"
                                    >s/plazas</span
                                >
                            </button>
                        </li>
                    </ul>
                </article>

                <!-- Tiempo por nivel -->
                <article data-tour="dashboard-tiempo" class="grafica">
                    <h2 class="grafica-titulo">
                        Tiempo de contratación por nivel
                    </h2>
                    <p class="grafica-nota">
                        Días promedio, postulación a ingreso · últimos
                        {{ tablero.filters.meses }} meses
                    </p>
                    <p
                        v-if="sinTiempos"
                        class="grid h-40 place-items-center text-center text-sm text-muted-foreground"
                    >
                        Sin contrataciones medibles en el periodo.
                    </p>
                    <ul v-else class="mt-3 flex flex-col gap-3">
                        <li
                            v-for="t in tiempos"
                            :key="t.clave"
                            class="grid grid-cols-[minmax(0,7rem)_minmax(0,1fr)_3rem] items-center gap-2 text-xs"
                            :title="`${t.etiqueta}: ${t.contratados} contratados`"
                        >
                            <span class="truncate">{{ t.etiqueta }}</span>
                            <span
                                class="h-2.5 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full rounded-full bg-oro transition-[width] duration-500 motion-reduce:transition-none"
                                    :style="{ width: `${t.ancho}%` }"
                                />
                            </span>
                            <span
                                class="text-right font-semibold tabular-nums"
                                >{{
                                    t.dias === null ? 's/d' : `${t.dias} d`
                                }}</span
                            >
                        </li>
                    </ul>
                    <p
                        v-if="!sinTiempos"
                        class="mt-3 text-[11px] text-muted-foreground"
                    >
                        Escala automática según el valor mayor.
                    </p>
                </article>
            </div>
        </div>
    </section>
</template>

<style scoped>
.bloque {
    border-style: solid;
    border-width: 4px 1px 1px;
    border-radius: 0.75rem;
    background: var(--card);
    padding: 0.75rem 1rem 1rem;
}

.bloque-titulo {
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: uppercase;
}

.bloque-datos {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem 1rem;
    margin-top: 0.625rem;
}

.bloque-datos dt {
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.bloque-datos dd {
    font-size: 1.375rem;
    font-weight: 700;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
}

.bloque-datos p {
    font-size: 0.625rem;
    line-height: 1.25;
    color: var(--muted-foreground);
}

.grafica {
    border: 1px solid var(--border);
    border-radius: 0.75rem;
    background: var(--card);
    padding: 0.875rem 1rem 1rem;
    min-width: 0;
}

.grafica-titulo {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--primary);
}

.grafica-nota {
    font-size: 0.6875rem;
    color: var(--muted-foreground);
}

.punto {
    display: inline-block;
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 9999px;
}
</style>
