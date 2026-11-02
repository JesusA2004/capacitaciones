import { router, usePage } from '@inertiajs/vue3';
import { computed, inject, provide, ref } from 'vue';
import type { ComputedRef, InjectionKey, Ref } from 'vue';
import type { Tono } from '@/lib/tonos';
import type {
    ClasificacionRotacion,
    FiltroRapidoRotacion,
    SeccionRotacion,
} from '@/types';

/**
 * Utilidades de Reportes → Rotación de personal (docs/ROTACION.md). Todo el
 * estado vive en la query string: cada clic conserva los filtros generales y
 * solo agrega el desglose (d_mes, d_sucursal, d_causal, d_rango…), así al
 * volver de un expediente la pantalla queda igual.
 */

export const SECCIONES: { valor: SeccionRotacion; etiqueta: string }[] = [
    { valor: 'resumen', etiqueta: 'Dashboard' },
    { valor: 'tendencias', etiqueta: 'Tendencias' },
    { valor: 'causales', etiqueta: 'Causales' },
    { valor: 'sucursales', etiqueta: 'Sucursales' },
    { valor: 'historico', etiqueta: 'Histórico' },
];

export function esSeccion(valor: unknown): valor is SeccionRotacion {
    return SECCIONES.some((s) => s.valor === valor);
}

/** Filtros que cambian TODAS las cifras (recarga completa). */
export const FILTROS_GENERALES = [
    'periodo',
    'anio',
    'mes',
    'desde',
    'hasta',
    'empresa_id',
    'sucursal_id',
    'departamento_id',
    'puesto_id',
    'clasificacion',
    'causal_analitica_id',
    'antiguedad',
    'fuente',
] as const;

/** Desglose y controles que solo acotan el histórico. */
export const PARAMETROS_HISTORICO = [
    'd_mes',
    'd_sucursal',
    'd_causal',
    'd_rango',
    'd_clasificacion',
    'rapido',
    'q',
    'orden',
    'dir',
    'page',
    'por_pagina',
] as const;

/** Lo que nunca viaja a una exportación ni se conserva entre visitas. */
const EFIMEROS = ['seccion', 'page', 'importacion', 'por_pagina'];

export type ValorParametro = string | number | null | undefined;
export type ParametrosRotacion = Record<string, ValorParametro>;

export type OpcionesActualizar = {
    seccion?: SeccionRotacion;
    /** Conserva la página actual del histórico (por omisión vuelve a la 1). */
    conservarPagina?: boolean;
};

export type NavegacionRotacion = {
    seccion: Ref<SeccionRotacion>;
    cargando: Ref<boolean>;
    consulta: ComputedRef<Record<string, string>>;
    /** Query para exportar: mismos filtros y desglose, sin lo efímero. */
    consultaExportacion: ComputedRef<Record<string, string>>;
    actualizar: (
        params: ParametrosRotacion,
        opciones?: OpcionesActualizar,
    ) => void;
    /** Abre el histórico con el desglose indicado (conserva los filtros). */
    verHistorico: (params?: ParametrosRotacion) => void;
    cambiarSeccion: (seccion: SeccionRotacion) => void;
    limpiar: () => void;
};

const CLAVE: InjectionKey<NavegacionRotacion> = Symbol('navegacion-rotacion');

function vacio(v: ValorParametro): boolean {
    return v === null || v === undefined || v === '';
}

/**
 * Crea la navegación de la pantalla (una sola vez, en la página) y la
 * comparte con las secciones.
 */
export function proveerNavegacionRotacion(
    seccionInicial: SeccionRotacion,
): NavegacionRotacion {
    const page = usePage();
    const seccion = ref<SeccionRotacion>(seccionInicial);
    const cargando = ref(false);

    const url = computed(
        () =>
            new URL(
                page.url,
                typeof window === 'undefined'
                    ? 'http://localhost'
                    : window.location.origin,
            ),
    );

    const consulta = computed<Record<string, string>>(() =>
        Object.fromEntries(url.value.searchParams.entries()),
    );

    const consultaExportacion = computed(() => {
        const q = { ...consulta.value };

        for (const k of EFIMEROS) {
            delete q[k];
        }

        return q;
    });

    function actualizar(
        params: ParametrosRotacion,
        opciones: OpcionesActualizar = {},
    ): void {
        const actual = { ...consulta.value };
        const nueva: Record<string, string> = { ...actual };

        delete nueva.importacion;

        if (!opciones.conservarPagina && !('page' in params)) {
            delete nueva.page;
        }

        for (const [clave, valor] of Object.entries(params)) {
            if (vacio(valor)) {
                delete nueva[clave];
            } else {
                nueva[clave] = String(valor);
            }
        }

        const destino = opciones.seccion ?? seccion.value;
        nueva.seccion = destino;
        seccion.value = destino;

        const cambiadas = new Set(
            [...Object.keys(actual), ...Object.keys(nueva)].filter(
                (k) => actual[k] !== nueva[k],
            ),
        );

        const general = FILTROS_GENERALES.some((k) => cambiadas.has(k));
        const historico = PARAMETROS_HISTORICO.some((k) => cambiadas.has(k));
        const only = general
            ? undefined
            : historico
              ? ['historico', 'desglose']
              : ['desglose'];

        router.get(url.value.pathname, nueva, {
            preserveState: true,
            preserveScroll: true,
            replace: !general && !historico,
            only,
            onStart: () => {
                cargando.value = true;
            },
            onFinish: () => {
                cargando.value = false;
            },
        });
    }

    function verHistorico(params: ParametrosRotacion = {}): void {
        actualizar(params, { seccion: 'historico' });
    }

    function cambiarSeccion(destino: SeccionRotacion): void {
        if (destino !== seccion.value) {
            actualizar({}, { seccion: destino, conservarPagina: true });
        }
    }

    function limpiar(): void {
        const nulos: ParametrosRotacion = {};

        for (const k of [...FILTROS_GENERALES, ...PARAMETROS_HISTORICO]) {
            nulos[k] = null;
        }

        actualizar(nulos);
    }

    const navegacion: NavegacionRotacion = {
        seccion,
        cargando,
        consulta,
        consultaExportacion,
        actualizar,
        verHistorico,
        cambiarSeccion,
        limpiar,
    };

    provide(CLAVE, navegacion);

    return navegacion;
}

export function useNavegacionRotacion(): NavegacionRotacion {
    const navegacion = inject(CLAVE);

    if (!navegacion) {
        throw new Error(
            'useNavegacionRotacion() necesita proveerNavegacionRotacion().',
        );
    }

    return navegacion;
}

/* --- Formato ---------------------------------------------------------- */

const entero = new Intl.NumberFormat('es-MX');
const decimal = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 1 });

export function numero(valor: number | null | undefined): string {
    return valor === null || valor === undefined ? '—' : entero.format(valor);
}

export function pct(valor: number | null | undefined): string {
    return valor === null || valor === undefined
        ? '—'
        : `${decimal.format(valor)} %`;
}

export function decimal1(valor: number | null | undefined): string {
    return valor === null || valor === undefined ? '—' : decimal.format(valor);
}

const MESES_CORTOS = [
    'ene',
    'feb',
    'mar',
    'abr',
    'may',
    'jun',
    'jul',
    'ago',
    'sep',
    'oct',
    'nov',
    'dic',
];

export const MESES_LARGOS = [
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

/** "2026-10" → "oct 2026" (o "Octubre 2026" con `largo`). */
export function etiquetaMes(periodo: string | null, largo = false): string {
    if (!periodo) {
        return '—';
    }

    const [anio, mes] = periodo.split('-').map(Number);

    if (!anio || !mes) {
        return periodo;
    }

    return largo
        ? `${MESES_LARGOS[mes - 1]} ${anio}`
        : `${MESES_CORTOS[mes - 1]} ${anio}`;
}

export function mesCorto(mes: number): string {
    return MESES_CORTOS[mes - 1] ?? String(mes);
}

/** "128 días (~4.2 meses)". */
export function diasYMeses(dias: number | null | undefined): string {
    if (dias === null || dias === undefined) {
        return '—';
    }

    return `${entero.format(dias)} ${dias === 1 ? 'día' : 'días'} (~${decimal.format(dias / 30.44)} meses)`;
}

export function plural(n: number, uno: string, varios: string): string {
    return `${entero.format(n)} ${n === 1 ? uno : varios}`;
}

/* --- Colores (tokens de la paleta, nunca hex) -------------------------- */

export const CLASIFICACION_VISUAL: Record<
    ClasificacionRotacion,
    { etiqueta: string; fondo: string; relleno: string; tono: Tono }
> = {
    voluntaria: {
        etiqueta: 'Voluntaria',
        fondo: 'bg-chart-1',
        relleno: 'fill-chart-1',
        tono: 'primario',
    },
    involuntaria: {
        etiqueta: 'Involuntaria',
        fondo: 'bg-chart-2',
        relleno: 'fill-chart-2',
        tono: 'oro',
    },
    sin_clasificar: {
        etiqueta: 'Sin clasificar',
        fondo: 'bg-muted-foreground/30',
        relleno: 'fill-muted-foreground/30',
        tono: 'neutro',
    },
};

export function tonoClasificacion(
    clasificacion: ClasificacionRotacion | null | undefined,
): Tono {
    return clasificacion ? CLASIFICACION_VISUAL[clasificacion].tono : 'neutro';
}

/* --- Filtros rápidos del histórico ------------------------------------- */

export const FILTROS_RAPIDOS: {
    valor: FiltroRapidoRotacion;
    etiqueta: string;
}[] = [
    { valor: 'pendientes', etiqueta: 'Pendientes de revisar' },
    { valor: 'sin_causal', etiqueta: 'Sin causal' },
    { valor: 'sin_homologar', etiqueta: 'Sin homologar' },
    { valor: 'sin_fecha', etiqueta: 'Sin fecha de ingreso' },
    { valor: 'inconsistente', etiqueta: 'Fechas inconsistentes' },
    { valor: 'temprana', etiqueta: 'Rotación temprana' },
    { valor: 'estructural', etiqueta: 'Estructurales' },
];

export const ALERTAS_HISTORICO: Record<
    string,
    { etiqueta: string; tono: Tono }
> = {
    sin_causal: { etiqueta: 'Sin causal', tono: 'peligro' },
    sin_homologar: { etiqueta: 'Sin homologar', tono: 'alerta' },
    sin_fecha: { etiqueta: 'Sin fecha de ingreso', tono: 'alerta' },
    inconsistente: { etiqueta: 'Fechas inconsistentes', tono: 'peligro' },
};

/** Definiciones cortas para los tooltips (docs/ROTACION.md → Definiciones). */
export const DEFINICIONES = {
    total: 'Voluntarias + involuntarias + sin clasificar del periodo y alcance.',
    promedio: 'Bajas ÷ meses considerados. Son eventos por mes, no un %.',
    participacion: 'Bajas del grupo ÷ bajas del alcance × 100.',
    temprana:
        'Bajas antes de 6 meses ÷ bajas con fecha de ingreso válida × 100.',
    indice: 'Bajas del periodo ÷ promedio de las plantillas promedio mensuales × 100.',
    indiceMensual: 'Bajas del mes ÷ plantilla promedio del mes × 100.',
    plantilla: '(Plantilla al inicio + plantilla al cierre) ÷ 2.',
    sinClasificar:
        'Bajas sin tipo de salida: causal pendiente o totales del Excel sin desglose.',
} as const;
