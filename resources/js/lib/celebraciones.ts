import type { EventoCelebracion, TipoCelebracion } from '@/types';

/**
 * Presentación compartida de Cumpleaños y Aniversarios. Todas las fechas
 * llegan como Y-m-d del backend (America/Mexico_City) y se comparan contra
 * el `hoy` que también manda el backend: nunca contra el reloj del
 * navegador, que puede estar en otra zona horaria.
 */
export const MESES = [
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

const MESES_CORTOS = ['ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

export const TEXTOS_CELEBRACION: Record<
    TipoCelebracion,
    { hoy: string; proximos: string; periodo: string; vacioPeriodo: string; vacioProximos: string; tarjeta: string }
> = {
    cumpleanos: {
        hoy: 'Hoy cumplen años',
        proximos: 'Próximos cumpleaños',
        periodo: 'Cumpleaños de',
        vacioPeriodo: 'No hay cumpleaños en este periodo.',
        vacioProximos: 'No hay cumpleaños próximos en el rango elegido.',
        tarjeta: 'Tarjeta de cumpleaños',
    },
    aniversario_laboral: {
        hoy: 'Hoy cumplen aniversario en MR. LANA',
        proximos: 'Próximos aniversarios',
        periodo: 'Aniversarios de',
        vacioPeriodo: 'No hay aniversarios en este periodo.',
        vacioProximos: 'No hay aniversarios próximos en el rango elegido.',
        tarjeta: 'Tarjeta de aniversario',
    },
};

function partes(fecha: string): [number, number, number] {
    const [anio, mes, dia] = fecha.split('-').map(Number);

    return [anio, mes, dia];
}

/** Días entre dos fechas Y-m-d (b - a), sin depender de la zona horaria. */
export function diasEntre(a: string, b: string): number {
    const [aa, am, ad] = partes(a);
    const [ba, bm, bd] = partes(b);

    return Math.round((Date.UTC(ba, bm - 1, bd) - Date.UTC(aa, am - 1, ad)) / 86_400_000);
}

/** «25 SEP» */
export function fechaCorta(fecha: string): { dia: number; mes: string } {
    const [, mes, dia] = partes(fecha);

    return { dia, mes: MESES_CORTOS[mes - 1] };
}

/** «Hoy», «Mañana», «En 3 días», «Hace 2 días». */
export function fechaRelativa(fecha: string, hoy: string): string {
    const dias = diasEntre(hoy, fecha);

    if (dias === 0) {
        return 'Hoy';
    }

    if (dias === 1) {
        return 'Mañana';
    }

    if (dias === -1) {
        return 'Ayer';
    }

    return dias > 0 ? `En ${dias} días` : `Hace ${-dias} días`;
}

/** «Gerente de Sucursal · Cuernavaca» (sin etiquetas tipo formulario). */
export function subtituloPersona(evento: Pick<EventoCelebracion, 'puesto' | 'sucursal'>): string {
    return [evento.puesto, evento.sucursal].filter(Boolean).join(' · ');
}
