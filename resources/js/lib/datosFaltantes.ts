import type { DatoFaltante } from '@/types';

/**
 * Llave con la que se envía un dato faltante al backend (`completar`): la
 * columna de la ficha, o `sucursal.<columna>` si vive en la sucursal.
 */
export function columnaDatoFaltante(f: DatoFaltante): string {
    return f.fuente === 'sucursal' ? `sucursal.${f.columna}` : f.columna;
}

/** ¿Ya se capturaron todos los datos que sí se pueden completar aquí? */
export function datosFaltantesCompletos(
    faltantes: DatoFaltante[],
    valores: Record<string, string>,
): boolean {
    return faltantes
        .filter((f) => f.editable)
        .every((f) => (valores[columnaDatoFaltante(f)] ?? '').trim() !== '');
}
