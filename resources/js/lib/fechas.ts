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

type PartesFecha = { anio: number; mes: number; dia: number };

/**
 * Lee año/mes/día directo del string (sin pasar por Date + zona horaria):
 * los campos `date` de Laravel llegan como "2026-09-29T00:00:00.000000Z"
 * (medianoche UTC) y convertirlos con new Date().toLocaleDateString() en una
 * zona horaria negativa (México) los recorre un día — por eso aquí se leen
 * los dígitos directamente.
 */
function partesFecha(valor: string): PartesFecha | null {
    const coincidencia = /^(\d{4})-(\d{2})-(\d{2})/.exec(valor);

    if (!coincidencia) {
        return null;
    }

    return {
        anio: Number(coincidencia[1]),
        mes: Number(coincidencia[2]),
        dia: Number(coincidencia[3]),
    };
}

/** "29 sep 2026" — para cualquier fecha u hora ISO que llegue del backend. */
export function formatearFecha(valor: string | null | undefined): string {
    if (!valor) {
        return '—';
    }

    const partes = partesFecha(valor);

    if (!partes) {
        return valor;
    }

    return `${partes.dia} ${MESES_CORTOS[partes.mes - 1]} ${partes.anio}`;
}

/**
 * "29 sep 2026" o, si es un rango, la forma corta que no repite lo que ya
 * comparten ambas fechas: "29 – 30 sep 2026", "29 sep – 3 oct 2026".
 */
export function formatearPeriodo(
    inicio: string | null | undefined,
    fin?: string | null,
): string {
    if (!inicio) {
        return '—';
    }

    if (!fin || fin === inicio) {
        return formatearFecha(inicio);
    }

    const a = partesFecha(inicio);
    const b = partesFecha(fin);

    if (!a || !b) {
        return `${formatearFecha(inicio)} – ${formatearFecha(fin)}`;
    }

    if (a.anio === b.anio && a.mes === b.mes) {
        return `${a.dia} – ${b.dia} ${MESES_CORTOS[b.mes - 1]} ${b.anio}`;
    }

    if (a.anio === b.anio) {
        return `${a.dia} ${MESES_CORTOS[a.mes - 1]} – ${b.dia} ${MESES_CORTOS[b.mes - 1]} ${b.anio}`;
    }

    return `${formatearFecha(inicio)} – ${formatearFecha(fin)}`;
}

/** "29 sep 2026, 10:30 a. m." — para timestamps donde la hora sí importa (historial/auditoría). */
export function formatearFechaHora(valor: string | null | undefined): string {
    if (!valor) {
        return '—';
    }

    const fecha = new Date(valor);

    if (Number.isNaN(fecha.getTime())) {
        return valor;
    }

    return fecha.toLocaleString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}
