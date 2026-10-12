import { ARTICULOS_ADMINISTRACION } from './articulos/administracion';
import { ARTICULOS_CONTRATACION } from './articulos/contratacion';
import { ARTICULOS_DESARROLLO } from './articulos/desarrollo';
import { ARTICULOS_RECLUTAMIENTO } from './articulos/reclutamiento';
import { ARTICULOS_SALIDA } from './articulos/salida';
import { ARTICULOS_VIDA_LABORAL } from './articulos/vida-laboral';
import { FLUJO } from './flujo';
import { GLOSARIO, PREGUNTAS_FRECUENTES } from './glosario';
import { PROCESOS } from './procesos';
import { ROLES_GUIA } from './roles';
import type {
    AccesoGuia,
    ArticuloGuia,
    ProcesoGuia,
    RolGuia,
    RolGuiaId,
} from './tipos';

/**
 * Punto de entrada de la Guía maestra para las pantallas Vue. El generador
 * de docs (scripts/guia/generar-docs.mjs) importa los mismos archivos de
 * datos directamente, así web y Markdown nunca difieren.
 */
export const ARTICULOS: ArticuloGuia[] = [
    ...ARTICULOS_RECLUTAMIENTO,
    ...ARTICULOS_CONTRATACION,
    ...ARTICULOS_DESARROLLO,
    ...ARTICULOS_VIDA_LABORAL,
    ...ARTICULOS_SALIDA,
    ...ARTICULOS_ADMINISTRACION,
];

export { FLUJO, GLOSARIO, PREGUNTAS_FRECUENTES, PROCESOS, ROLES_GUIA };

const PORID = new Map(ARTICULOS.map((a) => [a.id, a]));

export function articulo(id: string): ArticuloGuia | undefined {
    return PORID.get(id);
}

export function proceso(id: string): ProcesoGuia | undefined {
    return PROCESOS.find((p) => p.id === id);
}

export function rol(id: string): RolGuia | undefined {
    return ROLES_GUIA.find((r) => r.id === id);
}

export function procesoDeArticulo(art: ArticuloGuia): ProcesoGuia | undefined {
    return proceso(art.proceso);
}

/** Artículo anterior/siguiente dentro de su proceso (navegación del artículo). */
export function vecinos(art: ArticuloGuia): {
    anterior?: ArticuloGuia;
    siguiente?: ArticuloGuia;
} {
    const lista = proceso(art.proceso)?.articulos ?? [];
    const i = lista.indexOf(art.id);

    return {
        anterior: i > 0 ? articulo(lista[i - 1]) : undefined,
        siguiente:
            i >= 0 && i < lista.length - 1 ? articulo(lista[i + 1]) : undefined,
    };
}

export const NOMBRE_ROL: Record<RolGuiaId, string> = {
    rh: 'RH',
    gerente: 'Gerente',
    regional: 'Regional',
    direccion_comercial: 'Dirección Comercial',
    sistemas: 'Sistemas',
    colaborador: 'Colaborador',
};

/** Rol de la guía que corresponde a los roles técnicos del usuario. */
export function rolDelUsuario(
    roles: string[],
    modoColaborador: boolean,
): RolGuiaId {
    if (modoColaborador) {
        return 'colaborador';
    }

    for (const r of ROLES_GUIA) {
        if (
            r.id !== 'colaborador' &&
            r.rolesSistema.some((s) => roles.includes(s))
        ) {
            return r.id;
        }
    }

    return roles.includes('super_admin') ? 'rh' : 'colaborador';
}

/** Accesos que el usuario realmente puede abrir (permiso + modo). */
export function accesosVisibles(
    accesos: AccesoGuia[],
    tienePermiso: (permiso: string) => boolean,
    modo: 'operativo' | 'colaborador',
): AccesoGuia[] {
    return accesos.filter(
        (a) =>
            (!a.modo || a.modo === modo) &&
            (!a.permisos ||
                a.permisos.length === 0 ||
                a.permisos.some((p) => tienePermiso(p))),
    );
}
