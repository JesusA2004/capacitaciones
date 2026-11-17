import {
    CalendarClock,
    CheckCircle2,
    CircleSlash,
    Clock,
    Hourglass,
    XCircle,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { Tono } from '@/lib/tonos';
import type { EstadoHitoTecnico } from '@/types';

/**
 * Estado visual de una evaluación técnica («Seguimiento N»): tono pastel +
 * ícono + texto (el color nunca va solo).
 */
export const ESTADO_TECNICA: Record<
    EstadoHitoTecnico,
    { tono: Tono; icono: Component; etiqueta: string }
> = {
    pendiente: { tono: 'info', icono: Clock, etiqueta: 'Pendiente' },
    vencida: { tono: 'peligro', icono: Hourglass, etiqueta: 'Vencida' },
    aprobada: { tono: 'exito', icono: CheckCircle2, etiqueta: 'Aprobada' },
    no_aprobada: { tono: 'alerta', icono: XCircle, etiqueta: 'No aprobada' },
    contestada: { tono: 'primario', icono: CheckCircle2, etiqueta: 'Contestada' },
    cancelada: { tono: 'neutro', icono: CircleSlash, etiqueta: 'Cancelada' },
    programada: { tono: 'oro', icono: CalendarClock, etiqueta: 'Programada' },
    no_generada: { tono: 'neutro', icono: CircleSlash, etiqueta: 'No se generó' },
};

/** «Vence hoy», «Faltan 3 días», «Venció hace 2 días». */
export function textoPlazo(diasRestantes: number): string {
    if (diasRestantes === 0) {
        return 'Vence hoy';
    }

    if (diasRestantes > 0) {
        return diasRestantes === 1 ? 'Vence mañana' : `Faltan ${diasRestantes} días`;
    }

    const atraso = Math.abs(diasRestantes);

    return atraso === 1 ? 'Venció ayer' : `Venció hace ${atraso} días`;
}

/** «Seguimiento N»: el nombre por defecto de cada evaluación del calendario. */
export function nombreSeguimiento(dias: number): string {
    return `Seguimiento ${dias}`;
}

export function iniciales(nombre: string): string {
    return nombre
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}
