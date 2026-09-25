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
        franja: 'from-sky-400 to-sky-500',
        chip: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
    },
    administrativo: {
        etiqueta: 'Administrativo',
        franja: 'from-violet-400 to-violet-500',
        chip: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
    },
    operativo: {
        etiqueta: 'Operativo',
        franja: 'from-emerald-400 to-emerald-500',
        chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    },
    otro: {
        etiqueta: 'Otro',
        franja: 'from-slate-300 to-slate-400',
        chip: 'bg-slate-100 text-slate-700 dark:bg-slate-500/20 dark:text-slate-300',
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
