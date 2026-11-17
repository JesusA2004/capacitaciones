/**
 * Formato de los datos de Seguimiento (docs/SEGUIMIENTO.md). Un valor que
 * Reportes no trae (null) se muestra «—», nunca como 0.
 */
const moneda = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    maximumFractionDigits: 0,
});

const compacto = new Intl.NumberFormat('es-MX', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

export function formatoMoneda(valor: number | null | undefined): string {
    return valor === null || valor === undefined ? '—' : moneda.format(valor);
}

export function formatoCompacto(valor: number | null | undefined): string {
    return valor === null || valor === undefined
        ? '—'
        : `$${compacto.format(valor)}`;
}

export function formatoPorcentaje(valor: number | null | undefined): string {
    return valor === null || valor === undefined
        ? '—'
        : `${valor.toLocaleString('es-MX', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`;
}

const diaMes = new Intl.DateTimeFormat('es-MX', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

/** «25 may – 31 may» a partir de fechas AAAA-MM-DD. */
export function rangoSemana(
    inicio: string | null,
    fin: string | null,
): string {
    if (!inicio || !fin) {
        return '';
    }

    return `${diaMes.format(new Date(`${inicio}T00:00:00Z`))} – ${diaMes.format(new Date(`${fin}T00:00:00Z`))}`;
}

/** Tono de la mora: verde < 10 %, oro < 20 %, rojo desde 20 %. */
export function tonoMora(mora: number | null | undefined): string {
    if (mora === null || mora === undefined) {
        return 'text-muted-foreground';
    }

    if (mora < 10) {
        return 'bg-success-soft text-success';
    }

    return mora < 20
        ? 'bg-warning-soft text-warning'
        : 'bg-danger-soft text-danger';
}
