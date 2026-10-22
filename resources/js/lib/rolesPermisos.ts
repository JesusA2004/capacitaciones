import type {
    AccionPermiso,
    ModuloCatalogo,
    NivelAlcance,
} from '@/types/roles';

/** De mayor a menor: el primer alcance que el rol tenga es su nivel. */
export const ORDEN_ALCANCE: NivelAlcance[] = [
    'todos',
    'region',
    'sucursal',
    'equipo',
];

export const ETIQUETA_NIVEL: Record<NivelAlcance | 'propios', string> = {
    propios: 'Propios',
    equipo: 'Equipo',
    sucursal: 'Sucursal',
    region: 'Región',
    todos: 'Todos',
};

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
