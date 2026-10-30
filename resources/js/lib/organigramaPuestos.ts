/**
 * Árbol de la vista «Por puestos» (sin dependencias, probado en
 * tests/js/organigrama-puestos.test.mjs).
 *
 * Un puesto regional muestra además la rama de sucursal que depende «del
 * regional de su región» aunque en el catálogo cuelgue de otro regional. Esa
 * arista es solo de dibujo: la validación del catálogo no la ve, así que un
 * regional bajo otro regional cerraba un ciclo aquí y el árbol se renderizaba
 * sin fin. Toda arista que regrese a un puesto de su propia cadena se descarta.
 */

export type PuestoDeArbol = {
    id: number;
    puesto_superior_id: number | null;
    region?: string | null;
    depende_de_region?: boolean;
};

export function hijosDePuestos<T extends PuestoDeArbol>(
    puestos: T[],
): Map<number, T[]> {
    const porId = new Map(puestos.map((p) => [p.id, p]));
    const candidatos = new Map<number, T[]>();

    for (const puesto of puestos) {
        candidatos.set(
            puesto.id,
            puestos.filter(
                (p) =>
                    p.puesto_superior_id === puesto.id ||
                    (Boolean(puesto.region) && p.depende_de_region === true),
            ),
        );
    }

    const hijos = new Map<number, T[]>();
    const enCadena = new Set<number>();

    const recorrer = (id: number): void => {
        enCadena.add(id);
        const aceptados: T[] = [];

        for (const hijo of candidatos.get(id) ?? []) {
            if (enCadena.has(hijo.id)) {
                continue;
            }

            aceptados.push(hijo);

            if (!hijos.has(hijo.id)) {
                recorrer(hijo.id);
            }
        }

        hijos.set(id, aceptados);
        enCadena.delete(id);
    };

    // Primero desde las raíces, para que la cadena sea la que se dibuja.
    const raices = puestos.filter(
        (p) => !p.puesto_superior_id || !porId.has(p.puesto_superior_id),
    );

    for (const puesto of [...raices, ...puestos]) {
        if (!hijos.has(puesto.id)) {
            recorrer(puesto.id);
        }
    }

    return hijos;
}
