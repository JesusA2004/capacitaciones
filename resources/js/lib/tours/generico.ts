import type { Tour } from './tipos';

/**
 * Tour mínimo reutilizable para cualquier módulo: enseña a volver ahí desde
 * el menú y qué hace la pantalla. No inventa selectores frágiles por página
 * — solo usa el link del sidebar, que siempre existe si el usuario tiene
 * permiso para ver el módulo.
 */
export function tourGenerico(
    id: string,
    nombreModulo: string,
    href: string,
    queHaceAqui: string,
): Tour {
    return {
        id,
        titulo: `Cómo usar ${nombreModulo}`,
        pasos: [
            {
                selector: `a[href="${href}"]`,
                titulo: nombreModulo,
                texto: `Desde el menú siempre puedes volver aquí. ${queHaceAqui}`,
            },
        ],
    };
}
