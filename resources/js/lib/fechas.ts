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

const MESES_LARGOS = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
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

/** "29 de septiembre de 2026" — mes completo en español, sin abreviar. */
export function formatearFechaLarga(valor: string | null | undefined): string {
    if (!valor) {
        return '—';
    }

    const partes = partesFecha(valor);

    if (!partes) {
        return valor;
    }

    return `${partes.dia} de ${MESES_LARGOS[partes.mes - 1]} de ${partes.anio}`;
}

/** "29/09/2026" — formato numérico dd/mm/aaaa (el que piden otros sistemas). */
export function formatearFechaNumerica(
    valor: string | null | undefined,
): string {
    if (!valor) {
        return '—';
    }

    const partes = partesFecha(valor);

    if (!partes) {
        return valor;
    }

    const dia = String(partes.dia).padStart(2, '0');
    const mes = String(partes.mes).padStart(2, '0');

    return `${dia}/${mes}/${partes.anio}`;
}

/**
 * "29 de septiembre de 2026 · 29/09/2026" — las dos formas juntas, para
 * fechas clave del expediente (nacimiento, ingreso) que RH lee y además
 * captura en otros sistemas. No reemplaza a formatearFecha (listas).
 */
export function formatearFechaDoble(valor: string | null | undefined): string {
    if (!valor) {
        return '—';
    }

    if (!partesFecha(valor)) {
        return valor;
    }

    return `${formatearFechaLarga(valor)} · ${formatearFechaNumerica(valor)}`;
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

    // Laravel manda `toDateTimeString()` en UTC y sin zona: sin esto el navegador
    // lo tomaba por hora local y mostraba las 9 p.m. a las 3 p.m.
    const fecha = new Date(
        /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(valor)
            ? `${valor.replace(' ', 'T')}Z`
            : valor,
    );

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
