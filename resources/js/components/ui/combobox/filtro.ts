/**
 * Opción de los selects con búsqueda (`Combobox`, `ComboboxMultiple`,
 * `SelectSimple`). `descripcion` es la línea secundaria (p. ej. «1023 ·
 * Asesor · Cuernavaca») y también se busca en ella: así escribir el nombre
 * de una sucursal encuentra a su gente. `palabrasClave` agrega texto
 * buscable que no se muestra (clave de sucursal, RFC…).
 */
export type OpcionCombobox = {
    value: string;
    label: string;
    descripcion?: string | null;
    grupo?: string | null;
    palabrasClave?: string | null;
    disabled?: boolean;
};

/** Minúsculas y sin acentos: «José Núñez» → «jose nunez». */
export function normalizarTexto(texto: string | null | undefined): string {
    return (texto ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

/** Palabras normalizadas del término (vacío = sin filtro). */
export function palabrasDe(termino: string): string[] {
    return normalizarTexto(termino).split(/\s+/).filter(Boolean);
}

/**
 * Mismo criterio que el buscador del servidor
 * (BuscadorColaboradoresService): CADA palabra debe aparecer en la
 * etiqueta, la descripción o las palabras clave — «juan cuerna» encuentra a
 * Juan de Cuernavaca.
 */
export function coincideOpcion(
    opcion: OpcionCombobox,
    palabras: string[],
): boolean {
    if (palabras.length === 0) {
        return true;
    }

    const texto = normalizarTexto(
        `${opcion.label} ${opcion.descripcion ?? ''} ${opcion.grupo ?? ''} ${opcion.palabrasClave ?? ''}`,
    );

    return palabras.every((p) => texto.includes(p));
}

/** Agrupa conservando el orden de aparición (grupo vacío = sin encabezado). */
export function agruparOpciones(
    opciones: OpcionCombobox[],
): { grupo: string | null; opciones: OpcionCombobox[] }[] {
    const grupos: { grupo: string | null; opciones: OpcionCombobox[] }[] = [];

    for (const opcion of opciones) {
        const grupo = opcion.grupo ?? null;
        const existente = grupos.find((g) => g.grupo === grupo);

        if (existente) {
            existente.opciones.push(opcion);
        } else {
            grupos.push({ grupo, opciones: [opcion] });
        }
    }

    return grupos;
}
