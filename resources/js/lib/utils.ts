import type { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

const formateadorMoneda = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    maximumFractionDigits: 0,
});

/** Formatea un monto como moneda MXN sin decimales (p. ej. KPIs de costo de contratación). */
export function formatoMoneda(valor: number): string {
    return formateadorMoneda.format(valor);
}

/**
 * Columnas de una rejilla de tarjetas (KPIs, métricas) según CUÁNTAS hay,
 * para que nunca quede una sola tarjeta huérfana en la última fila
 * (p. ej. 5 tarjetas con `lg:grid-cols-4` = 4 + 1). Strings completos para
 * que Tailwind los detecte.
 *   1 → no ocupa todo el ancho; 2 → 2; 3 → 3; 4 → 2×2 / 4;
 *   5 → 2+2+1(ancha) / 3+2 / 5; 6 → 2×3 / 3×2 / 6; 7+ → auto-fit.
 */
export function columnasBalanceadas(cantidad: number): string {
    switch (cantidad) {
        case 1:
            return 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
        case 2:
            return 'grid-cols-1 min-[400px]:grid-cols-2';
        case 3:
            return 'grid-cols-1 sm:grid-cols-3';
        case 4:
            return 'grid-cols-2 lg:grid-cols-4';
        case 5:
            return 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 max-sm:[&>*:last-child]:col-span-2';
        case 6:
            return 'grid-cols-2 sm:grid-cols-3 2xl:grid-cols-6';
        default:
            return 'grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))]';
    }
}
