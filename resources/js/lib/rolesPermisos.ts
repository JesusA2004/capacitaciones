import type {
    AccionPermiso,
    ModuloCatalogo,
    NivelAlcance,
} from '@/types/roles';

/** De mayor a menor: el primer alcance que el rol tenga es su nivel. */
export const ORDEN_ALCANCE: NivelAlcance[] = [
    'todos',
    'region',
    'sucursal_corporativo',
    'sucursal',
    'equipo',
];

/**
 * Etiquetas EXPLÍCITAS del alcance (qué registros ve): nada de «Todos» a
 * secas. Mismo texto que App\Enums\AlcanceDatos::etiqueta().
 */
export const ETIQUETA_NIVEL: Record<NivelAlcance | 'propios', string> = {
    propios: 'Solo lo propio',
    equipo: 'Lo suyo + su equipo directo',
    sucursal: 'Solo su sucursal',
    sucursal_corporativo: 'Su sucursal + Corporativo (no las demás sucursales)',
    region: 'Las sucursales de su región',
    todos: 'Todas las sucursales (toda la empresa)',
};

/** Una línea que explica cada alcance (selector y vista por persona). */
export const DESCRIPCION_NIVEL: Record<NivelAlcance | 'propios', string> = {
    propios: 'Solo los registros de la propia persona.',
    equipo: 'Los suyos y los de quienes le reportan directamente.',
    sucursal:
        'Los de su sucursal (y las adicionales que tenga asignadas). Ninguna otra.',
    sucursal_corporativo:
        'Los de su sucursal más todo el personal de Corporativo. No ve las demás sucursales.',
    region: 'Los de todas las sucursales de su región (matriz comercial).',
    todos: 'Toda la organización: todas las sucursales y Corporativo.',
};

/** Opciones del selector de alcance de un módulo, de menor a mayor. */
export function opcionesNivel(
    modulo: ModuloCatalogo,
): { value: NivelAlcance | 'propios'; label: string; descripcion: string }[] {
    return (
        [
            'propios',
            'equipo',
            'sucursal',
            'sucursal_corporativo',
            'region',
            'todos',
        ] as const
    )
        .filter((n) => n === 'propios' || modulo.alcances[n] !== undefined)
        .map((n) => ({
            value: n,
            label: ETIQUETA_NIVEL[n],
            descripcion: DESCRIPCION_NIVEL[n],
        }));
}

/** Texto sin acentos y en minúsculas para buscar «aprobacion» = «aprobación». */
export function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

export function coincide(filtro: string, ...textos: (string | null)[]) {
    if (filtro === '') {
        return true;
    }

    return textos.some((t) => t !== null && normalizar(t).includes(filtro));
}

export function accionesVisibles(
    modulo: ModuloCatalogo,
    filtro: string,
): AccionPermiso[] {
    if (filtro === '' || coincide(filtro, modulo.etiqueta, modulo.clave)) {
        return modulo.acciones;
    }

    return modulo.acciones.filter((a) =>
        coincide(filtro, a.etiqueta, a.descripcion, a.permiso),
    );
}

export function moduloVisible(modulo: ModuloCatalogo, filtro: string) {
    return (
        filtro === '' ||
        coincide(
            filtro,
            modulo.etiqueta,
            modulo.clave,
            modulo.descripcion,
            modulo.acceso?.permiso ?? null,
            ...Object.values(modulo.alcances),
        ) ||
        accionesVisibles(modulo, filtro).length > 0
    );
}

/** Todos los permisos que presenta un módulo (acceso + alcances + acciones). */
export function permisosDelModulo(modulo: ModuloCatalogo): string[] {
    return [
        ...(modulo.acceso ? [modulo.acceso.permiso] : []),
        ...(Object.values(modulo.alcances).filter(Boolean) as string[]),
        ...modulo.acciones.map((a) => a.permiso),
    ];
}

export function nivelDe(
    modulo: ModuloCatalogo,
    permisos: Set<string>,
): NivelAlcance | 'propios' {
    for (const nivel of ORDEN_ALCANCE) {
        const permiso = modulo.alcances[nivel];

        if (permiso && permisos.has(permiso)) {
            return nivel;
        }
    }

    return 'propios';
}

/** ¿El rol puede entrar al módulo (o, sin acceso propio, tiene alguna acción)? */
export function moduloActivo(modulo: ModuloCatalogo, permisos: Set<string>) {
    if (modulo.acceso) {
        return permisos.has(modulo.acceso.permiso);
    }

    return modulo.acciones.some((a) => permisos.has(a.permiso));
}
