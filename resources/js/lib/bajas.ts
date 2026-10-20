/**
 * Utilidades del módulo de bajas (pantalla del gerente, expediente y
 * tablero). Las reglas reales viven en el backend (BajaReglasService); aquí
 * solo se adelantan los avisos en pantalla.
 */

/** Clases de color por forma de salida (tokens de resources/css/app.css). */
export const CLASE_FORMA: Record<
    string,
    { fondo: string; texto: string; borde: string }
> = {
    renuncia: {
        fondo: 'bg-forma-renuncia',
        texto: 'text-forma-renuncia',
        borde: 'border-forma-renuncia',
    },
    'no-renovacion': {
        fondo: 'bg-forma-no-renovacion',
        texto: 'text-forma-no-renovacion',
        borde: 'border-forma-no-renovacion',
    },
    rescision: {
        fondo: 'bg-forma-rescision',
        texto: 'text-forma-rescision',
        borde: 'border-forma-rescision',
    },
};

export function claseForma(token: string | null | undefined) {
    return (
        CLASE_FORMA[token ?? ''] ?? {
            fondo: 'bg-muted-foreground',
            texto: 'text-muted-foreground',
            borde: 'border-border',
        }
    );
}

function aFecha(iso: string): Date {
    const [a, m, d] = iso.slice(0, 10).split('-').map(Number);

    return new Date(a, (m ?? 1) - 1, d ?? 1);
}

/** Días naturales de `desde` a `hasta` (negativo si hasta es anterior). */
export function diasEntre(desde: string, hasta: string): number {
    return Math.round(
        (aFecha(hasta).getTime() - aFecha(desde).getTime()) / 86_400_000,
    );
}

/** Fecha ISO + N días hábiles (lunes a viernes, sin catálogo de feriados). */
export function sumarDiasHabiles(iso: string, dias: number): string {
    const fecha = aFecha(iso);
    let restantes = dias;

    while (restantes > 0) {
        fecha.setDate(fecha.getDate() + 1);

        if (fecha.getDay() !== 0 && fecha.getDay() !== 6) {
            restantes--;
        }
    }

    const mes = String(fecha.getMonth() + 1).padStart(2, '0');
    const dia = String(fecha.getDate()).padStart(2, '0');

    return `${fecha.getFullYear()}-${mes}-${dia}`;
}

export function hoyIso(): string {
    const hoy = new Date();

    return `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;
}

export function porcentaje(valor: number | null | undefined): string {
    return valor === null || valor === undefined
        ? '—'
        : `${valor.toLocaleString('es-MX', { maximumFractionDigits: 2 })}%`;
}
