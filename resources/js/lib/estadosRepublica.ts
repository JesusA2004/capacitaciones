/**
 * Las 32 entidades federativas — espejo de App\Enums\EstadoRepublica (el
 * valor es el nombre que se guarda e imprime). Un test de Pest verifica que
 * esta lista no se desincronice del Enum.
 */
export const ESTADOS_REPUBLICA = [
    'Aguascalientes',
    'Baja California',
    'Baja California Sur',
    'Campeche',
    'Chiapas',
    'Chihuahua',
    'Ciudad de México',
    'Coahuila',
    'Colima',
    'Durango',
    'Estado de México',
    'Guanajuato',
    'Guerrero',
    'Hidalgo',
    'Jalisco',
    'Michoacán',
    'Morelos',
    'Nayarit',
    'Nuevo León',
    'Oaxaca',
    'Puebla',
    'Querétaro',
    'Quintana Roo',
    'San Luis Potosí',
    'Sinaloa',
    'Sonora',
    'Tabasco',
    'Tamaulipas',
    'Tlaxcala',
    'Veracruz',
    'Yucatán',
    'Zacatecas',
] as const;

function clave(texto: string): string {
    return texto
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9 ]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/** Nombre oficial a partir de lo capturado («MORELOS» → «Morelos»); '' si no hay equivalencia. */
export function estadoOficial(texto: string | null | undefined): string {
    const buscado = clave(texto ?? '');

    if (buscado === '') {
        return '';
    }

    return ESTADOS_REPUBLICA.find((e) => clave(e) === buscado) ?? '';
}
