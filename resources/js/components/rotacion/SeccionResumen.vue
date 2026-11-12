<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Gauge,
    HelpCircle,
    Lightbulb,
    ShieldAlert,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import BarrasHorizontalesRotacion from '@/components/rotacion/BarrasHorizontalesRotacion.vue';
import type { BarraHorizontal } from '@/components/rotacion/BarrasHorizontalesRotacion.vue';
import BarrasVerticalesRotacion from '@/components/rotacion/BarrasVerticalesRotacion.vue';
import type { BarraVertical } from '@/components/rotacion/BarrasVerticalesRotacion.vue';
import DonaTipoBajaRotacion from '@/components/rotacion/DonaTipoBajaRotacion.vue';
import MetasRotacionPanel from '@/components/rotacion/MetasRotacionPanel.vue';
import { Button } from '@/components/ui/button';
import {
    DEFINICIONES,
    MESES_LARGOS,
    decimal1,
    esSeccion,
    etiquetaMes,
    mesCorto,
    numero,
    pct,
    plural,
    useNavegacionRotacion,
} from '@/lib/rotacion';
import { TONO_SUAVE } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';
import type {
    AccionHallazgoRotacion,
    AntiguedadRotacion,
    CalidadRotacion,
    CausalHomologadaRotacion,
    CausalesRotacionTablero,
    FiltrosRotacionTablero,
    HallazgoRotacionTablero,
    MetasRotacionPanel as Metas,
    OpcionesRotacion,
    PermisosRotacion,
    RangoAntiguedadRotacion,
    ResumenRotacion,
    SucursalRotacionFila,
    TendenciaRotacion,
} from '@/types';

/**
 * Dashboard de rotación (docs/rotacionNuevo/Dashboard_Rotacion_2026.pdf):
 * una vista ejecutiva de una pantalla con encabezado, seis indicadores y
 * cinco gráficas. Todos los % son participación sobre el total de bajas
 * (salvo «antes de 6 meses», sobre las bajas con fecha de ingreso válida).
 * Solo da forma a las cifras del motor; cada barra abre el histórico
 * filtrado. Debajo, como secundario: índice sobre plantilla, hallazgos y
 * metas.
 */
const props = defineProps<{
    resumen: ResumenRotacion;
    tendencia: TendenciaRotacion;
    causales: CausalesRotacionTablero;
    antiguedad: AntiguedadRotacion;
    sucursales: SucursalRotacionFila[];
    calidad: CalidadRotacion;
    filtros: FiltrosRotacionTablero;
    opciones: OpcionesRotacion;
    mesInicioSistema: string;
    hallazgos: HallazgoRotacionTablero[];
    metas: Metas;
    puede: PermisosRotacion;
    conDetalle: boolean;
}>();

const nav = useNavegacionRotacion();
const r = computed(() => props.resumen);

/* --- Encabezado ------------------------------------------------------- */

function partes(fecha: string): { anio: number; mes: number; dia: number } {
    const [anio, mes, dia] = fecha.slice(0, 10).split('-').map(Number);

    return { anio, mes, dia };
}

const anioTitulo = computed(() => {
    const d = partes(props.filtros.desde).anio;
    const h = partes(props.filtros.hasta).anio;

    return d === h ? String(h) : `${d}–${h}`;
});

const textoPeriodo = computed(() => {
    const d = partes(props.filtros.desde);
    const h = partes(props.filtros.hasta);
    const md = MESES_LARGOS[d.mes - 1];
    const mh = MESES_LARGOS[h.mes - 1];

    if (d.anio === h.anio && d.mes === h.mes) {
        return `${mh} ${h.anio}`;
    }

    return d.anio === h.anio
        ? `${md} – ${mh} ${h.anio}`
        : `${md} ${d.anio} – ${mh} ${h.anio}`;
});

/** Empresa con más sucursales: la «casa»; las demás se marcan en oro. */
const empresaPrincipal = computed(() => {
    const conteo = new Map<number, number>();

    for (const s of props.opciones.sucursales) {
        if (s.empresa_id !== null) {
            conteo.set(s.empresa_id, (conteo.get(s.empresa_id) ?? 0) + 1);
        }
    }

    const id = [...conteo.entries()].sort((a, b) => b[1] - a[1])[0]?.[0];

    return props.opciones.empresas.find((e) => e.id === id) ?? null;
});

const empresaEncabezado = computed(
    () =>
        props.opciones.empresas.find((e) => e.id === props.filtros.empresa_id)
            ?.nombre ??
        empresaPrincipal.value?.nombre ??
        'Mr. Lana',
);

const textoAlcance = computed(() => {
    const f = props.filtros;
    const o = props.opciones;
    const nombre = <T extends { id: number; nombre: string }>(
        lista: T[],
        id: number | null,
    ) => lista.find((x) => x.id === id)?.nombre ?? null;
    const partesAlcance: string[] = [];

    const sucursal = nombre(o.sucursales, f.sucursal_id);
    const empresa = nombre(o.empresas, f.empresa_id);

    if (sucursal) {
        partesAlcance.push(sucursal);
    } else if (empresa) {
        partesAlcance.push(`Todas las sucursales de ${empresa}`);
    } else {
        partesAlcance.push('Todas las sucursales');
    }

    const detalle = [
        nombre(o.departamentos, f.departamento_id),
        nombre(o.puestos, f.puesto_id),
        o.clasificaciones.find((c) => c.value === f.clasificacion)?.label,
        nombre(o.causales, f.causal_analitica_id),
        o.antiguedades.find((a) => a.value === f.antiguedad)?.label,
    ].filter((x): x is string => !!x);

    partesAlcance.push(...detalle);

    if (f.fuente === 'sistema') {
        partesAlcance.push('solo sistema');
    } else if (f.fuente === 'historico') {
        partesAlcance.push('solo histórico');
    }

    return partesAlcance.join(' · ');
});

const textoCorte = computed(() => {
    const { anio, mes, dia } = partes(props.filtros.hasta);

    return `${String(dia).padStart(2, '0')}/${String(mes).padStart(2, '0')}/${anio}`;
});

/** De dónde salen las cifras del periodo (por mes, según el motor). */
const textoFuente = computed(() => {
    const fuentes = new Set<string>();

    for (const m of props.tendencia.meses) {
        if (m.sin_datos || m.total === 0) {
            continue;
        }

        if (m.fuentes.includes('individual')) {
            fuentes.add(
                m.periodo >= props.mesInicioSistema
                    ? 'sistema'
                    : 'histórico individual',
            );
        }

        if (m.fuentes.includes('agregado')) {
            fuentes.add('totales del Excel');
        }
    }

    const orden = ['sistema', 'histórico individual', 'totales del Excel'];
    const lista = orden.filter((x) => fuentes.has(x));

    return lista.length ? lista.join(' + ') : 'sin datos';
});

/* --- Indicadores ------------------------------------------------------ */

/** Causales reales (sin «pendiente/sin homologar»), de mayor a menor. */
const causalesOrdenadas = computed(() =>
    [...props.causales.causales].sort((a, b) => b.bajas - a.bajas),
);

const porcentajeTotal = (n: number): number | null =>
    r.value.total > 0 ? Math.round((n * 1000) / r.value.total) / 10 : null;

const causalPrincipal = computed(() => {
    const lista = causalesOrdenadas.value.filter(
        (c) => c.id !== null && c.clave !== 'sin_causal',
    );
    const primera = lista[0];

    if (!primera || primera.bajas === 0) {
        return null;
    }

    const empatadas = lista.filter((c) => c.bajas === primera.bajas);
    const corto = (c: CausalHomologadaRotacion, i: number) => {
        const base = c.nombre.split(/\s+o\s+|\s*[/(]/)[0].trim();

        return i === 0 ? base : base.charAt(0).toLowerCase() + base.slice(1);
    };

    return {
        causal: primera,
        bajas: primera.bajas,
        empate: empatadas.length > 1,
        nombre:
            empatadas.length > 1
                ? `${empatadas
                      .slice(0, -1)
                      .map(corto)
                      .join(
                          ', ',
                      )} y ${corto(empatadas[empatadas.length - 1], 1)} (empate)`
                : primera.nombre,
        porcentaje: porcentajeTotal(primera.bajas),
    };
});

type Indicador = {
    clave: string;
    valor: string;
    etiqueta: string;
    borde: string;
    definicion: string;
    accion?: () => void;
};

const indicadores = computed<Indicador[]>(() => {
    const c = causalPrincipal.value;
    const t = r.value.temprana;

    return [
        {
            clave: 'total',
            valor: numero(r.value.total),
            etiqueta: `Bajas totales ${anioTitulo.value}`,
            borde: 'border-l-primary',
            definicion: DEFINICIONES.total,
            accion: () => nav.verHistorico(),
        },
        {
            clave: 'promedio',
            valor: decimal1(r.value.promedio_mensual),
            etiqueta: 'Promedio mensual',
            borde: 'border-l-esmeralda',
            definicion: `${DEFINICIONES.promedio} ${plural(r.value.meses, 'mes considerado', 'meses considerados')}.`,
            accion: () => nav.cambiarSeccion('tendencias'),
        },
        {
            clave: 'voluntarias',
            valor: numero(r.value.voluntarias.bajas),
            etiqueta: `Voluntarias · ${pct(r.value.voluntarias.porcentaje)}`,
            borde: 'border-l-chart-1',
            definicion: DEFINICIONES.participacion,
            accion: () => nav.verHistorico({ d_clasificacion: 'voluntaria' }),
        },
        {
            clave: 'involuntarias',
            valor: numero(r.value.involuntarias.bajas),
            etiqueta: `Involuntarias · ${pct(r.value.involuntarias.porcentaje)}`,
            borde: 'border-l-oro',
            definicion: DEFINICIONES.participacion,
            accion: () => nav.verHistorico({ d_clasificacion: 'involuntaria' }),
        },
        {
            clave: 'causal',
            valor: c ? numero(c.bajas) : '—',
            etiqueta: c
                ? `${c.nombre} · ${pct(c.porcentaje)}${c.empate ? ' c/u' : ''}`
                : 'Causal principal: sin causales registradas',
            borde: 'border-l-esmeralda',
            definicion:
                'Causal homologada con más bajas; % sobre el total de bajas.',
            accion: c
                ? () =>
                      c.empate
                          ? nav.cambiarSeccion('causales')
                          : nav.verHistorico({
                                d_causal: c.causal.id ?? 'pendiente',
                            })
                : undefined,
        },
        {
            clave: 'temprana',
            valor: pct(t.porcentaje),
            etiqueta: 'Bajas antes de 6 meses',
            borde: 'border-l-destructive',
            definicion:
                t.base > 0
                    ? `${DEFINICIONES.temprana} ${numero(t.bajas)} de ${numero(t.base)}.`
                    : DEFINICIONES.temprana,
            accion: () => nav.verHistorico({ rapido: 'temprana' }),
        },
    ];
});

/* --- Gráficas --------------------------------------------------------- */

const promedioMes = computed(() => r.value.promedio_mensual);

const barrasMes = computed<BarraVertical[]>(() =>
    props.tendencia.meses.map((m) => {
        const pico = m.periodo === props.tendencia.mes_mayor && m.total > 0;

        return {
            clave: m.periodo,
            etiqueta: mesCorto(m.mes).replace(/^./, (x) => x.toUpperCase()),
            valor: m.sin_datos ? null : m.total,
            color: pico ? 'bg-esmeralda' : 'bg-primary',
            accesible: m.sin_datos
                ? `${etiquetaMes(m.periodo, true)}: sin datos`
                : `${etiquetaMes(m.periodo, true)}: ${plural(m.total, 'baja', 'bajas')}${pico ? ' (mes con más bajas)' : ''}. Ver colaboradores`,
        };
    }),
);

const barrasSucursal = computed<BarraHorizontal[]>(() => {
    const principal = empresaPrincipal.value?.nombre ?? null;

    return [...props.sucursales]
        .sort((a, b) => b.bajas - a.bajas)
        .slice(0, 12)
        .map((s) => {
            const externa =
                s.unidad_externa === true ||
                (principal !== null &&
                    s.empresa !== null &&
                    s.empresa !== principal);

            return {
                clave: String(s.sucursal_id),
                etiqueta: s.sucursal,
                valor: s.bajas,
                porcentaje: s.participacion,
                color: s.es_corporativo
                    ? 'bg-esmeralda'
                    : externa
                      ? 'bg-oro'
                      : 'bg-primary',
                accesible: `${s.sucursal}${s.empresa ? ` (${s.empresa})` : ''}: ${plural(s.bajas, 'baja', 'bajas')}, ${pct(s.participacion)} del total. Ver colaboradores`,
            };
        });
});

const hayExternas = computed(() =>
    barrasSucursal.value.some((b) => b.color === 'bg-oro'),
);
const hayCorporativo = computed(() =>
    barrasSucursal.value.some((b) => b.color === 'bg-esmeralda'),
);

/**
 * Un color por causal dentro de la familia de su tipo: verdes para
 * voluntarias, oros/terracota para involuntarias, gris sin clasificar.
 */
const FAMILIA = {
    voluntaria: [
        'bg-esmeralda',
        'bg-primary',
        'bg-chart-5',
        'bg-info/75',
        'bg-esmeralda/55',
        'bg-primary/55',
        'bg-success/70',
    ],
    involuntaria: [
        'bg-destructive/75',
        'bg-oro',
        'bg-bronce',
        'bg-warning/70',
        'bg-oro/55',
        'bg-bronce/55',
    ],
} as const;

const barrasCausal = computed<BarraHorizontal[]>(() => {
    const usados = { voluntaria: 0, involuntaria: 0 };
    const barras: BarraHorizontal[] = causalesOrdenadas.value.map((c) => {
        let color = 'bg-muted-foreground/35';

        if (
            c.clasificacion === 'voluntaria' ||
            c.clasificacion === 'involuntaria'
        ) {
            const familia = FAMILIA[c.clasificacion];
            color = familia[usados[c.clasificacion]++ % familia.length];
        }

        const p = porcentajeTotal(c.bajas);

        return {
            clave: String(c.id ?? 'pendiente'),
            etiqueta: c.nombre,
            valor: c.bajas,
            porcentaje: p,
            color,
            accesible: `${c.nombre} (${c.clasificacion_etiqueta}): ${plural(c.bajas, 'baja', 'bajas')}, ${pct(p)} del total. Ver colaboradores`,
        };
    });

    if (props.causales.sin_desglose > 0) {
        const p = porcentajeTotal(props.causales.sin_desglose);
        barras.push({
            clave: 'sin_desglose',
            etiqueta: 'Totales del Excel sin desglose',
            valor: props.causales.sin_desglose,
            porcentaje: p,
            color: 'bg-muted-foreground/20',
            accesible: `${plural(props.causales.sin_desglose, 'baja', 'bajas')} del Excel sin causal (${pct(p)} del total)`,
            soloLectura: true,
        });
    }

    return barras;
});

const ETIQUETA_RANGO: Partial<Record<RangoAntiguedadRotacion, string>> = {
    menos_3_meses: '< 3 m',
    de_3_a_6_meses: '3–6 m',
    de_6_a_12_meses: '6–12 m',
    de_1_a_3_anios: '1–3 a',
    mas_3_anios: '> 3 a',
};

const barrasAntiguedad = computed<BarraVertical[]>(() =>
    props.antiguedad.rangos
        .filter((x) => x.clave !== 'sin_fecha')
        .map((x) => ({
            clave: x.clave,
            etiqueta: ETIQUETA_RANGO[x.clave] ?? x.etiqueta,
            valor: x.bajas,
            color:
                x.clave === 'menos_3_meses'
                    ? 'bg-destructive/80'
                    : 'bg-primary',
            accesible: `${x.etiqueta}: ${plural(x.bajas, 'baja', 'bajas')}. Ver colaboradores`,
        })),
);

const sinFecha = computed(
    () =>
        props.antiguedad.rangos.find((x) => x.clave === 'sin_fecha')?.bajas ??
        0,
);

/* --- Nota al pie ------------------------------------------------------ */

const notas = computed(() => {
    const lista = [
        `Porcentajes = participación sobre el total de bajas del periodo (${numero(r.value.total)}).`,
    ];
    const t = r.value.temprana;

    if (t.base > 0) {
        lista.push(
            `«Antes de 6 meses» se calcula sobre ${plural(t.base, 'baja', 'bajas')} con fecha de ingreso válida.`,
        );
    }

    if (props.calidad.duplicadas_ligadas > 0) {
        lista.push(
            `Se depuraron ${plural(props.calidad.duplicadas_ligadas, 'duplicado', 'duplicados')} entre el histórico y el sistema.`,
        );
    } else {
        lista.push('Se depuran los duplicados entre histórico y sistema.');
    }

    lista.push(
        'Un reingreso que vuelve a causar baja cuenta como evento adicional.',
    );

    if (sinFecha.value > 0) {
        lista.push(
            `${plural(sinFecha.value, 'baja', 'bajas')} sin fecha de ingreso válida (fuera de la gráfica de antigüedad).`,
        );
    }

    if (props.causales.sin_desglose > 0) {
        lista.push(
            `${plural(props.causales.sin_desglose, 'baja', 'bajas')} de los totales del Excel no traen causal ni antigüedad.`,
        );
    }

    const corporativo = props.sucursales.find((s) => s.es_corporativo);

    if (corporativo) {
        lista.push(
            `Corporativo concentra ${plural(corporativo.bajas, 'baja', 'bajas')}.`,
        );
    }

    if (props.calidad.posibles_duplicados > 0) {
        lista.push(
            `${plural(props.calidad.posibles_duplicados, 'posible duplicado', 'posibles duplicados')} por revisar en Histórico.`,
        );
    }

    return lista.join(' ');
});

/* --- Secundario: índice, hallazgos y metas ----------------------------- */

const indiceTexto = computed(() => {
    if (props.conDetalle) {
        return {
            valor: 'No aplica',
            detalle: 'Con filtros de detalle no hay plantilla comparable.',
        };
    }

    if (r.value.rotacion.indice === null) {
        return {
            valor: 'Sin datos',
            detalle: 'Requiere la plantilla del periodo.',
        };
    }

    return {
        valor: pct(r.value.rotacion.indice),
        detalle: `Plantilla promedio ${decimal1(r.value.rotacion.plantilla_promedio)} · ${plural(r.value.rotacion.meses_con_plantilla, 'mes', 'meses')} con plantilla`,
    };
});

const TONO_HALLAZGO: Record<HallazgoRotacionTablero['tono'], Tono> = {
    alerta: 'alerta',
    info: 'info',
    peligro: 'peligro',
    neutro: 'neutro',
};

const ICONO_HALLAZGO = {
    alerta: AlertTriangle,
    info: Lightbulb,
    peligro: ShieldAlert,
    neutro: HelpCircle,
} as const;

function ejecutar(accion: AccionHallazgoRotacion): void {
    const filtros = { ...(accion.filtros ?? {}) };
    const destino = filtros.seccion;
    delete filtros.seccion;
    const seccion = esSeccion(destino) ? destino : undefined;

    if (accion.tipo === 'seccion') {
        if (seccion) {
            nav.cambiarSeccion(seccion);
        }

        return;
    }

    nav.actualizar(filtros, { seccion });
}

function abrirCausal(clave: string): void {
    nav.verHistorico({ d_causal: clave });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Encabezado del dashboard -->
        <header
            class="flex flex-col gap-3 rounded-2xl border-b-[3px] border-oro bg-salvia/80 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
        >
            <div class="min-w-0">
                <h2
                    class="font-display text-xl leading-tight font-semibold text-balance text-primary sm:text-2xl"
                >
                    Dashboard de Rotación de Personal {{ anioTitulo }}
                </h2>
                <p
                    class="mt-0.5 text-sm font-medium text-esmeralda sm:text-[15px]"
                >
                    <span class="text-bronce">{{ textoPeriodo }}</span>
                    · {{ textoAlcance }}
                </p>
            </div>
            <div
                class="shrink-0 text-xs leading-relaxed text-muted-foreground sm:text-right sm:text-[13px]"
            >
                <p class="font-medium text-foreground/80">
                    {{ empresaEncabezado }} · Gerencia de Recursos Humanos
                </p>
                <p>Corte: {{ textoCorte }} · Fuente: {{ textoFuente }}</p>
            </div>
        </header>

        <EmptyState
            v-if="r.total === 0"
            :icono="Users"
            titulo="Sin bajas en el periodo"
            descripcion="No hay bajas de rotación con estos filtros. Prueba con otro periodo o quita algún filtro."
        />

        <template v-else>
            <!-- Seis indicadores -->
            <ul
                class="grid grid-cols-2 gap-2.5 md:grid-cols-3 xl:grid-cols-6"
                aria-label="Indicadores de rotación"
            >
                <li
                    v-for="(k, i) in indicadores"
                    :key="k.clave"
                    class="aparece-escalonado"
                    :style="{ '--i': i }"
                >
                    <component
                        :is="k.accion ? 'button' : 'div'"
                        :type="k.accion ? 'button' : undefined"
                        :class="
                            cn(
                                'flex h-full w-full flex-col items-start gap-1 rounded-xl border border-l-4 border-border/70 bg-card px-3.5 py-3 text-left shadow-xs',
                                k.borde,
                                k.accion && 'tarjeta-interactiva',
                            )
                        "
                        :title="k.definicion"
                        @click="k.accion?.()"
                    >
                        <span
                            class="font-display text-2xl leading-none font-semibold text-primary tabular-nums sm:text-[1.75rem]"
                            >{{ k.valor }}</span
                        >
                        <span
                            class="text-xs leading-snug text-muted-foreground"
                            >{{ k.etiqueta }}</span
                        >
                    </component>
                </li>
            </ul>

            <!-- Fila 1: meses y sucursales -->
            <div
                class="grid gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]"
            >
                <section
                    class="flex min-w-0 flex-col rounded-2xl border border-border/70 bg-card p-4"
                    aria-labelledby="dash-meses"
                >
                    <div
                        class="mb-1 flex items-end justify-between gap-3 border-b-2 border-esmeralda/70 pb-1.5"
                    >
                        <h3 id="dash-meses" class="font-semibold text-primary">
                            Bajas por mes
                        </h3>
                        <span
                            v-if="promedioMes !== null"
                            class="inline-flex items-center gap-1.5 text-[11px] font-medium text-bronce"
                        >
                            <span
                                class="w-5 border-t-2 border-dashed border-bronce/80"
                                aria-hidden="true"
                            />
                            Promedio {{ decimal1(promedioMes) }}
                        </span>
                    </div>
                    <BarrasVerticalesRotacion
                        :barras="barrasMes"
                        :promedio="promedioMes"
                        etiqueta-grafica="Bajas por mes"
                        alto="h-52 sm:h-60"
                        @seleccionar="(m) => nav.verHistorico({ d_mes: m })"
                    />
                </section>

                <section
                    class="flex min-w-0 flex-col rounded-2xl border border-border/70 bg-card p-4"
                    aria-labelledby="dash-sucursales"
                >
                    <div
                        class="mb-2 flex items-end justify-between gap-3 border-b-2 border-esmeralda/70 pb-1.5"
                    >
                        <h3
                            id="dash-sucursales"
                            class="font-semibold text-primary"
                        >
                            Bajas por sucursal / área
                            <span class="font-normal text-muted-foreground"
                                >(top 12)</span
                            >
                        </h3>
                        <span
                            v-if="hayCorporativo || hayExternas"
                            class="flex flex-wrap justify-end gap-x-2.5 text-[11px] text-muted-foreground"
                        >
                            <span
                                v-if="hayCorporativo"
                                class="inline-flex items-center gap-1"
                                ><span
                                    class="size-2 rounded-[3px] bg-esmeralda"
                                    aria-hidden="true"
                                />Corporativo</span
                            >
                            <span
                                v-if="hayExternas"
                                class="inline-flex items-center gap-1"
                                ><span
                                    class="size-2 rounded-[3px] bg-oro"
                                    aria-hidden="true"
                                />Fuera de
                                {{
                                    empresaPrincipal?.nombre ?? 'la empresa'
                                }}</span
                            >
                        </span>
                    </div>
                    <BarrasHorizontalesRotacion
                        :barras="barrasSucursal"
                        etiqueta-grafica="Bajas por sucursal o área"
                        mayusculas
                        ancho-etiqueta="w-24 sm:w-36"
                        @seleccionar="
                            (s) => nav.verHistorico({ d_sucursal: s })
                        "
                    />
                </section>
            </div>

            <!-- Fila 2: causales, tipo y antigüedad -->
            <div
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,0.8fr)_minmax(0,1fr)]"
            >
                <section
                    class="flex min-w-0 flex-col rounded-2xl border border-border/70 bg-card p-4 md:col-span-2 xl:col-span-1"
                    aria-labelledby="dash-causales"
                >
                    <div
                        class="mb-2 flex items-end justify-between gap-3 border-b-2 border-esmeralda/70 pb-1.5"
                    >
                        <h3
                            id="dash-causales"
                            class="font-semibold text-primary"
                        >
                            Causales de baja
                        </h3>
                        <span
                            class="flex gap-x-2.5 text-[11px] text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1"
                                ><span
                                    class="size-2 rounded-[3px] bg-esmeralda"
                                    aria-hidden="true"
                                />Voluntaria</span
                            >
                            <span class="inline-flex items-center gap-1"
                                ><span
                                    class="size-2 rounded-[3px] bg-oro"
                                    aria-hidden="true"
                                />Involuntaria</span
                            >
                        </span>
                    </div>
                    <p
                        v-if="!barrasCausal.length"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Sin causales registradas en el periodo.
                    </p>
                    <BarrasHorizontalesRotacion
                        v-else
                        :barras="barrasCausal"
                        etiqueta-grafica="Causales de baja"
                        ancho-etiqueta="w-32 sm:w-52"
                        @seleccionar="abrirCausal"
                    />
                </section>

                <section
                    class="flex min-w-0 flex-col rounded-2xl border border-border/70 bg-card p-4"
                    aria-labelledby="dash-tipo"
                >
                    <h3
                        id="dash-tipo"
                        class="mb-3 border-b-2 border-esmeralda/70 pb-1.5 font-semibold text-primary"
                    >
                        Tipo de baja
                    </h3>
                    <div class="flex flex-1 items-center justify-center">
                        <DonaTipoBajaRotacion
                            :resumen="resumen"
                            @seleccionar="
                                (c) => nav.verHistorico({ d_clasificacion: c })
                            "
                        />
                    </div>
                </section>

                <section
                    class="flex min-w-0 flex-col rounded-2xl border border-border/70 bg-card p-4"
                    aria-labelledby="dash-antiguedad"
                >
                    <h3
                        id="dash-antiguedad"
                        class="border-b-2 border-esmeralda/70 pb-1.5 font-semibold text-primary"
                    >
                        Antigüedad al causar baja
                    </h3>
                    <BarrasVerticalesRotacion
                        :barras="barrasAntiguedad"
                        etiqueta-grafica="Bajas por antigüedad al causar baja"
                        alto="h-48 sm:h-56"
                        @seleccionar="(c) => nav.verHistorico({ d_rango: c })"
                    />
                </section>
            </div>

            <p class="text-[11px] leading-relaxed text-muted-foreground">
                {{ notas }}
                <button
                    v-if="
                        puede.ver_personas && r.sin_clasificar.por_revisar > 0
                    "
                    type="button"
                    class="cursor-pointer enlace-animado font-medium text-primary"
                    @click="nav.verHistorico({ rapido: 'pendientes' })"
                >
                    Revisar
                    {{
                        plural(
                            r.sin_clasificar.por_revisar,
                            'pendiente',
                            'pendientes',
                        )
                    }}
                </button>
            </p>
        </template>

        <!-- Secundario -->
        <div
            class="mt-2 grid items-start gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(20rem,2fr)]"
        >
            <section
                class="flex min-w-0 flex-col gap-3"
                aria-labelledby="titulo-hallazgos"
            >
                <h2 id="titulo-hallazgos" class="text-sm font-semibold">
                    Hallazgos
                    <span class="font-normal text-muted-foreground"
                        >· patrones para investigar, no conclusiones</span
                    >
                </h2>
                <p
                    v-if="!hallazgos.length"
                    class="rounded-2xl bg-crema/30 px-4 py-6 text-center text-sm text-muted-foreground"
                >
                    No hay hallazgos que destacar con estos filtros.
                </p>
                <ul v-else class="grid gap-2.5">
                    <li
                        v-for="(h, i) in hallazgos"
                        :key="h.clave"
                        class="flex aparece-escalonado gap-3 rounded-2xl border border-border/60 bg-card p-3.5 shadow-sm"
                        :style="{ '--i': i }"
                    >
                        <span
                            :class="
                                cn(
                                    'grid size-9 shrink-0 place-items-center rounded-xl',
                                    TONO_SUAVE[TONO_HALLAZGO[h.tono]],
                                )
                            "
                            aria-hidden="true"
                        >
                            <component
                                :is="ICONO_HALLAZGO[h.tono]"
                                class="size-4"
                            />
                        </span>
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <p class="text-sm font-medium">{{ h.titulo }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ h.detalle }}
                            </p>
                            <div
                                v-if="h.acciones.length"
                                class="mt-1 flex flex-wrap gap-2"
                            >
                                <template
                                    v-for="a in h.acciones"
                                    :key="a.etiqueta"
                                >
                                    <Button
                                        v-if="a.tipo === 'enlace' && a.href"
                                        size="sm"
                                        variant="outline"
                                        as-child
                                    >
                                        <Link :href="a.href"
                                            >{{ a.etiqueta
                                            }}<ArrowRight class="size-3.5"
                                        /></Link>
                                    </Button>
                                    <Button
                                        v-else
                                        size="sm"
                                        variant="outline"
                                        @click="ejecutar(a)"
                                        >{{ a.etiqueta
                                        }}<ArrowRight class="size-3.5"
                                    /></Button>
                                </template>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <div class="flex min-w-0 flex-col gap-4">
                <section
                    class="flex gap-3 rounded-2xl border border-border/60 bg-card p-4"
                    aria-labelledby="titulo-indice"
                >
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-xl bg-info-soft text-info"
                        aria-hidden="true"
                    >
                        <Gauge class="size-4" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <h2 id="titulo-indice" class="text-sm font-semibold">
                            Índice de rotación sobre plantilla
                            <span
                                :class="
                                    cn(
                                        'ml-1 font-display text-base tabular-nums',
                                        conDetalle || r.rotacion.indice === null
                                            ? 'text-muted-foreground'
                                            : 'text-info',
                                    )
                                "
                                >{{ indiceTexto.valor }}</span
                            >
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ indiceTexto.detalle }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ DEFINICIONES.indice }} A diferencia del
                            dashboard, no es participación: requiere la
                            plantilla de cada mes.
                        </p>
                    </div>
                </section>
                <MetasRotacionPanel
                    :metas="metas"
                    :puede-editar="puede.editar_metas"
                    :mes-inicial="filtros.hasta.slice(0, 7)"
                />
            </div>
        </div>
    </div>
</template>
