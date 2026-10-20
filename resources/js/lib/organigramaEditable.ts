import type { NodoOrganigramaPersona, RegionOrganigrama } from '@/types';

/**
 * Utilidades del organigrama editable (General): chips Q1/Q3, cadena de
 * mando del lado del cliente (solo para MOSTRAR — el jefe real lo deriva el
 * backend, JefeDirectoService) y errores de validación de los endpoints JSON.
 */

/** «Región Q3» → «Q3». */
export function codigoRegion(
    region: { id: number; nombre: string } | null | undefined,
    regiones: RegionOrganigrama[] = [],
): string | null {
    if (!region) {
        return null;
    }

    const conocida = regiones.find((r) => r.id === region.id);

    if (conocida) {
        return conocida.codigo;
    }

    const coincidencia = region.nombre.match(/Q\s*\d+/i);

    return coincidencia
        ? coincidencia[0].replace(/\s+/g, '').toUpperCase()
        : region.nombre;
}

/** Color del chip por lado (solo tokens de la paleta). */
export function claseChipRegion(codigo: string | null): string {
    if (codigo === 'Q1') {
        return 'bg-info-soft text-info border-info/30';
    }

    if (codigo === 'Q3') {
        return 'bg-crema text-bronce border-bronce/30';
    }

    return 'bg-olivo text-primary border-primary/20';
}

export const CLASE_POR_CLASIFICAR =
    'bg-warning-soft text-warning border-warning/40 border-dashed';

/** Lado que se muestra en la tarjeta (clasificado, o null si falta). */
export function regionDeNodo(
    nodo: NodoOrganigramaPersona,
): { id: number; nombre: string } | null {
    if (nodo.clasificacion) {
        return nodo.clasificacion.region;
    }

    return nodo.region;
}

export function hijosPorPadre(
    nodos: NodoOrganigramaPersona[],
): Map<string, NodoOrganigramaPersona[]> {
    const claves = new Set(nodos.map((n) => n.clave));
    const mapa = new Map<string, NodoOrganigramaPersona[]>();

    for (const nodo of nodos) {
        const padre = nodo.padre && claves.has(nodo.padre) ? nodo.padre : '';
        const lista = mapa.get(padre) ?? [];
        lista.push(nodo);
        mapa.set(padre, lista);
    }

    // Personas primero, luego coberturas y vacantes; dentro, por puesto y
    // nombre: el orden del dibujo es estable entre recargas.
    const peso = (n: NodoOrganigramaPersona): number =>
        n.tipo === 'persona' ? 0 : n.tipo === 'cobertura' ? 1 : 2;

    for (const lista of mapa.values()) {
        lista.sort(
            (a, b) =>
                (a.puesto.nivel ?? 99) - (b.puesto.nivel ?? 99) ||
                // Los regionales (Q1, Q3) juntos y en orden, al final.
                Number(a.region !== null) - Number(b.region !== null) ||
                (a.region?.nombre ?? '').localeCompare(
                    b.region?.nombre ?? '',
                    'es',
                ) ||
                peso(a) - peso(b) ||
                (a.sucursal?.nombre ?? a.region?.nombre ?? '').localeCompare(
                    b.sucursal?.nombre ?? b.region?.nombre ?? '',
                    'es',
                ) ||
                (a.persona?.nombre ?? '').localeCompare(
                    b.persona?.nombre ?? '',
                    'es',
                ),
        );
    }

    return mapa;
}

/** Total de personas debajo (toda la rama), para «12 a cargo». */
export function contarRama(
    clave: string,
    hijos: Map<string, NodoOrganigramaPersona[]>,
): number {
    let total = 0;
    const pendientes = [...(hijos.get(clave) ?? [])];
    const vistos = new Set<string>();

    while (pendientes.length) {
        const nodo = pendientes.pop();

        if (!nodo || vistos.has(nodo.clave)) {
            continue;
        }

        vistos.add(nodo.clave);

        if (nodo.tipo === 'persona') {
            total++;
        }

        pendientes.push(...(hijos.get(nodo.clave) ?? []));
    }

    return total;
}

/**
 * Persona a la que reporta (primer titular o quien cubre, subiendo por la
 * cadena) — mismo criterio que OrganigramaPersonasService::personaArriba().
 */
export function jefeVisible(
    nodo: NodoOrganigramaPersona,
    porClave: Map<string, NodoOrganigramaPersona>,
): NodoOrganigramaPersona | null {
    let actual = nodo.padre ? porClave.get(nodo.padre) : undefined;
    const vistos = new Set<string>();

    while (actual && !vistos.has(actual.clave)) {
        vistos.add(actual.clave);

        if (actual.persona && actual.persona.id !== nodo.persona?.id) {
            return actual;
        }

        actual = actual.padre ? porClave.get(actual.padre) : undefined;
    }

    return null;
}

export function lugarDeNodo(nodo: NodoOrganigramaPersona): string | null {
    return nodo.sucursal?.nombre ?? nodo.region?.nombre ?? null;
}

/**
 * POST JSON que devuelve el primer mensaje de validación (422) como Error
 * legible, en vez de «Error 422».
 */
export async function postJsonConErrores<T>(
    url: string,
    cuerpo: unknown,
    xsrf: string,
): Promise<T> {
    const respuesta = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf,
        },
        credentials: 'same-origin',
        body: JSON.stringify(cuerpo),
    });

    if (respuesta.status === 422) {
        const datos = (await respuesta.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        const primero = Object.values(datos.errors ?? {})[0]?.[0];

        throw new Error(primero ?? datos.message ?? 'Revisa los datos.');
    }

    if (respuesta.status === 403) {
        throw new Error('No tienes permiso para mover a esta persona.');
    }

    if (!respuesta.ok) {
        throw new Error(
            'No se pudo calcular la vista previa. Intenta de nuevo.',
        );
    }

    return respuesta.json() as Promise<T>;
}
