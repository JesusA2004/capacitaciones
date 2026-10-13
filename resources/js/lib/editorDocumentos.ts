import type { CampoEditor } from '@/types';

/**
 * Valores de DATOS (no de estilo de la interfaz) del editor de documentos.
 * `FONDO_TAPAR` es el color con que el overlay cubre un texto de ejemplo
 * impreso en el PDF original antes de escribir el dato (siempre blanco
 * papel).
 */
export const FONDO_TAPAR = '#FFFFFF';

/** Tamaños por defecto de un espacio nuevo en PDF (mm / pt). */
export const CAJA_NUEVA = { width: 60, height: 5, font_size: 9 } as const;

export function campoNuevo(fieldKey: string, pagina: number, x: number, y: number): CampoEditor {
    return {
        id: null,
        field_key: fieldKey,
        zona: 'dato',
        estado: 'sin_asignar',
        source_key: null,
        expression: null,
        transform: null,
        label: null,
        type: 'texto',
        page: pagina,
        x: Math.round(x * 2) / 2,
        y: Math.round(y * 2) / 2,
        width: CAJA_NUEVA.width,
        height: CAJA_NUEVA.height,
        font_size: CAJA_NUEVA.font_size,
        font_size_min: null,
        font_weight: null,
        text_align: null,
        line_height: null,
        multiline: false,
        required: true,
        background_color: null,
        selector: null,
        contexto: null,
        sugerencias: [],
        detection_method: 'manual',
        motivo: null,
        motivo_blanco: null,
        bloqueado: false,
    };
}

/** Siguiente clave libre «c12» para un espacio nuevo. */
export function siguienteClave(campos: CampoEditor[]): string {
    const usados = new Set(campos.map((c) => c.field_key));
    let n = campos.length + 1;

    while (usados.has(`c${n}`)) {
        n++;
    }

    return `c${n}`;
}
