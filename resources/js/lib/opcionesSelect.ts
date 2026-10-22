/**
 * Convierte un catálogo `{ id, nombre }` (sucursales, puestos,
 * departamentos, empresas, responsables…) en opciones de `SelectSimple`
 * (value como string). Con más de 7 opciones SelectSimple se vuelve un
 * select con búsqueda.
 */
export function opcionesDe(
    catalogo:
        readonly { id: number | string; nombre: string }[] | null | undefined,
): { value: string; label: string }[] {
    return (catalogo ?? []).map((o) => ({
        value: String(o.id),
        label: o.nombre,
    }));
}
