/**
 * Textos de catálogos de capacitación para pantallas que reciben solo la
 * clave (mismo texto que App\Enums\TipoPregunta::etiqueta()).
 */
export const ETIQUETA_TIPO_PREGUNTA: Record<string, string> = {
    opcion_unica: 'Opción única',
    opcion_multiple: 'Opción múltiple',
    verdadero_falso: 'Verdadero o falso',
    respuesta_corta: 'Respuesta corta',
    respuesta_larga: 'Respuesta larga',
    escala: 'Escala',
    carga_archivo: 'Carga de archivo',
};

/** Duración legible: «45 min», «1 h 30 min». */
export function duracionLegible(minutos: number | null | undefined): string {
    if (!minutos) {
        return '—';
    }

    const horas = Math.floor(minutos / 60);
    const resto = minutos % 60;

    if (horas === 0) {
        return `${resto} min`;
    }

    return resto ? `${horas} h ${resto} min` : `${horas} h`;
}
