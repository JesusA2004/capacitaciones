import type {
    ArticuloGuia,
    PreguntaFrecuente,
    ProcesoGuia,
    RolGuia,
    RolGuiaId,
} from './tipos';

/**
 * Qué parte de la Guía le corresponde a quien la lee (cierre 2026-10-15):
 * ROL + MODO DE NAVEGACIÓN. En «Mi espacio» solo lo del colaborador; en el
 * modo operativo, solo los roles de la guía que coinciden con sus roles
 * técnicos (Sistemas no ve las decisiones de RH por tener lectura).
 * super_admin ve la ayuda global. Los accesos «Ir a…» además se filtran por
 * permiso real (accesosVisibles). Funciones puras: las prueba
 * tests/js/ayuda-por-rol.test.mjs.
 */
export type AlcanceGuia = { global: boolean; roles: RolGuiaId[] };

export function calcularAlcance(
    roles: string[],
    modoColaborador: boolean,
    rolesGuia: RolGuia[],
): AlcanceGuia {
    if (modoColaborador) {
        return { global: false, roles: ['colaborador'] };
    }

    if (roles.includes('super_admin')) {
        return { global: true, roles: rolesGuia.map((r) => r.id) };
    }

    const propios = rolesGuia
        .filter(
            (r) =>
                r.id !== 'colaborador' &&
                r.rolesSistema.some((s) => roles.includes(s)),
        )
        .map((r) => r.id);

    return {
        global: false,
        roles: propios.length > 0 ? propios : ['colaborador'],
    };
}

export function rolVisible(id: RolGuiaId, alcance: AlcanceGuia): boolean {
    return alcance.global || alcance.roles.includes(id);
}

export function procesoVisible(p: ProcesoGuia, alcance: AlcanceGuia): boolean {
    return alcance.global || p.roles.some((r) => alcance.roles.includes(r));
}

export function articuloVisible(
    art: ArticuloGuia,
    alcance: AlcanceGuia,
): boolean {
    return alcance.global || art.quien.some((r) => alcance.roles.includes(r));
}

/** El recorrido completo de reclutamiento a vida laboral es de RH. */
export function veFlujoCompleto(alcance: AlcanceGuia): boolean {
    return alcance.global || alcance.roles.includes('rh');
}

export function preguntaVisible(
    p: PreguntaFrecuente,
    alcance: AlcanceGuia,
): boolean {
    return (
        alcance.global ||
        !p.roles ||
        p.roles.some((r) => alcance.roles.includes(r))
    );
}
