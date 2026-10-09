import type { InjectionKey, Ref } from 'vue';
import type { NodoOrganigramaPersona, PuestoJerarquiaItem } from '@/types';

/**
 * Texto de búsqueda del organigrama (puesto o persona), compartido por
 * provide/inject entre la página y cada tarjeta del árbol — sin pasarlo
 * como prop por todos los niveles recursivos.
 */
export const CLAVE_BUSQUEDA_ORGANIGRAMA: InjectionKey<Ref<string>> = Symbol(
    'busqueda-organigrama',
);

function normalizar(texto: string): string {
    return (
        texto
            .normalize('NFD')
            // Quita acentos: "Gutiérrez" coincide con "gutierrez".
            .replace(/\p{Diacritic}/gu, '')
            .toLowerCase()
            .trim()
    );
}

/** Si el puesto o alguien que lo ocupa coincide con la búsqueda. */
export function coincideBusqueda(
    puesto: PuestoJerarquiaItem,
    busqueda: string,
): boolean {
    const texto = normalizar(busqueda);

    if (!texto) {
        return true;
    }

    return (
        normalizar(puesto.nombre).includes(texto) ||
        (puesto.ocupantes ?? []).some((persona) =>
            normalizar(persona.nombre).includes(texto),
        )
    );
}

/** Color y etiqueta por tipo de puesto (franja superior de la tarjeta). */
export const ESTILO_TIPO_PUESTO: Record<
    string,
    { etiqueta: string; franja: string; chip: string }
> = {
    comercial: {
        etiqueta: 'Comercial',
        franja: 'from-esmeralda to-primary',
        chip: 'bg-info-soft text-info',
    },
    administrativo: {
        etiqueta: 'Administrativo',
        franja: 'from-oro to-bronce',
        chip: 'bg-crema text-bronce',
    },
    operativo: {
        etiqueta: 'Operativo',
        franja: 'from-verde-suave to-esmeralda',
        chip: 'bg-success-soft text-success',
    },
    otro: {
        etiqueta: 'Otro',
        franja: 'from-crema-2 to-olivo',
        chip: 'bg-muted text-muted-foreground',
    },
};

export const ESTILO_SIN_TIPO = {
    etiqueta: '',
    franja: 'from-primary/60 to-primary',
    chip: 'bg-primary/10 text-primary',
};

/** Coincidencia de una tarjeta de persona: nombre, puesto o sucursal. */
export function coincidePersona(
    nodo: NodoOrganigramaPersona,
    busqueda: string,
): boolean {
    const texto = normalizar(busqueda);

    if (!texto) {
        return true;
    }

    return [nodo.persona?.nombre, nodo.puesto.nombre, nodo.sucursal?.nombre]
        .filter((valor): valor is string => Boolean(valor))
        .some((valor) => normalizar(valor).includes(texto));
}

/** Etiqueta de la asignación en la Matriz comercial. */
export function etiquetaRuta(tipo: string): string {
    return tipo === 'volante' ? 'Volante' : tipo === 'apoyo' ? 'Apoyo' : 'Ruta';
}

/**
 * Nivel de detalle de las tarjetas según el zoom ("zoom semántico"): al
 * alejar, las tarjetas dejan de mostrar datos secundarios y agrandan foto
 * y nombre, para que el árbol se siga leyendo en vez de volverse
 * diminuto.
 */
export type DetalleOrganigrama = 'completo' | 'compacto' | 'minimo';

export const CLAVE_DETALLE_ORGANIGRAMA: InjectionKey<Ref<DetalleOrganigrama>> =
    Symbol('detalle-organigrama');

export function detallePorZoom(zoom: number): DetalleOrganigrama {
    if (zoom >= 0.85) {
        return 'completo';
    }

    return zoom >= 0.55 ? 'compacto' : 'minimo';
}

/**
 * Acciones de edición del organigrama por personas, compartidas con todas
 * las tarjetas del árbol recursivo (sin pasarlas como prop nivel por nivel).
 */
export type AccionesOrganigrama = {
    puedeEditar: boolean;
    /** Tarjeta "sin ocupar": registrar quién la cubre temporalmente. */
    asignarCobertura: (nodo: NodoOrganigramaPersona) => void;
    /** Tarjeta de cobertura: terminarla (regresa a "sin ocupar"). */
    terminarCobertura: (nodo: NodoOrganigramaPersona) => void;
};

export const CLAVE_ACCIONES_ORGANIGRAMA: InjectionKey<AccionesOrganigrama> =
    Symbol('acciones-organigrama');

/**
 * Hijos de cada nodo del organigrama por personas (clave '' = raíces),
 * ordenados: primero mayor jerarquía, luego sucursal, luego nombre. Lo
 * comparten el árbol (tablet/escritorio) y la lista jerárquica (móvil) para
 * que ambos muestren exactamente el mismo orden.
 */
export function agruparPersonasPorPadre(
    nodos: NodoOrganigramaPersona[],
): Map<string, NodoOrganigramaPersona[]> {
    const claves = new Set(nodos.map((nodo) => nodo.clave));
    const mapa = new Map<string, NodoOrganigramaPersona[]>();

    for (const nodo of nodos) {
        const padre = nodo.padre && claves.has(nodo.padre) ? nodo.padre : '';
        mapa.set(padre, [...(mapa.get(padre) ?? []), nodo]);
    }

    for (const lista of mapa.values()) {
        lista.sort(
            (a, b) =>
                (a.puesto.nivel ?? 99) - (b.puesto.nivel ?? 99) ||
                (a.sucursal?.nombre ?? '').localeCompare(
                    b.sucursal?.nombre ?? '',
                ) ||
                (a.persona?.nombre ?? '').localeCompare(
                    b.persona?.nombre ?? '',
                ),
        );
    }

    return mapa;
}
