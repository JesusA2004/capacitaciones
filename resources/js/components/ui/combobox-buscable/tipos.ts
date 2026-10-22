/**
 * Opción de `ComboboxBuscable`. Solo `value` y `label` son obligatorios.
 */
export type OpcionBuscable = {
    value: string | number;
    label: string;
    /** Encabezado del bloque donde se agrupa (p. ej. «Requeridos»). */
    grupo?: string;
    /** Segunda línea pequeña bajo la etiqueta (también se busca en ella). */
    descripcion?: string;
    /** Términos extra que también encuentran la opción (clave, sinónimos). */
    alias?: string[];
    /** Chip pequeño a la derecha (p. ej. «Opcional»). */
    chip?: string;
    chipTono?: 'primario' | 'neutro';
    /** Se ve pero no se puede elegir. */
    deshabilitado?: boolean;
    /** Texto en lugar del chip cuando está deshabilitada («Ya asignado»). */
    nota?: string;
};
