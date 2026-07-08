import type { GraficaAsistenciaSesiones } from '@/types';

/**
 * Pequeñas transformaciones compartidas por los 3 dashboards para convertir
 * las formas de datos que devuelve MetricasDashboardService (conteos por
 * clave, pares completados/total) en el arreglo `{ etiqueta, valor }` que
 * espera DashboardChartCard para una dona.
 */
export type PuntoDonut = {
    clave: string;
    etiqueta: string;
    valor: number;
};

const ETIQUETAS_ASISTENCIA: Record<string, string> = {
    presente: 'Presente',
    asistencia_parcial: 'Parcial',
    ausente: 'Ausente',
    pendiente: 'Pendiente',
    tarde: 'Tarde',
    pendiente_revision: 'En revisión',
    corregida_manualmente: 'Corregida',
};

export function asistenciaADonut(
    asistencia: GraficaAsistenciaSesiones,
): PuntoDonut[] {
    return Object.entries(asistencia)
        .filter(([, valor]) => valor > 0)
        .map(([clave, valor]) => ({
            clave,
            etiqueta: ETIQUETAS_ASISTENCIA[clave] ?? clave,
            valor,
        }));
}

export function parADonut(
    completado: number,
    pendiente: number,
    etiquetaCompletado: string,
    etiquetaPendiente: string,
): PuntoDonut[] {
    return [
        {
            clave: 'completado',
            etiqueta: etiquetaCompletado,
            valor: completado,
        },
        {
            clave: 'pendiente',
            etiqueta: etiquetaPendiente,
            valor: Math.max(0, pendiente),
        },
    ];
}
