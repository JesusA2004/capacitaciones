import type { DocumentoPlanEditor } from '@/types';

/**
 * Lógica pura del editor de hojas del PDF histórico
 * (pages/Rh/Expedientes/EditorHojasDivision.vue). Cada operación regresa un
 * estado NUEVO (nunca muta el anterior) para que deshacer/rehacer sea solo
 * una pila de estados. El backend (EditorPaginasDivisionService) vuelve a
 * validar todo: aquí solo se arma el plan.
 *
 * Reglas del modelo:
 *  - cada hoja del PDF está en exactamente un documento;
 *  - un documento con tipo conserva el orden de sus hojas (se puede
 *    reordenar); las hojas «sin clasificar» se agrupan solas en tramos
 *    consecutivos, igual que las deja el OCR.
 */
export type DocumentoEditor = {
    id: string;
    tipoId: number | null;
    paginas: number[];
};

export type ResultadoOperacion =
    { ok: true; documentos: DocumentoEditor[] } | { ok: false; error: string };

let consecutivo = 0;

function nuevoId(): string {
    consecutivo += 1;

    return `doc-${consecutivo}`;
}

export function crearEstado(plan: DocumentoPlanEditor[]): DocumentoEditor[] {
    return normalizar(
        plan.map((d) => ({
            id: nuevoId(),
            tipoId: d.document_type_id,
            paginas: [...d.paginas],
        })),
    );
}

/**
 * Forma única: sin documentos vacíos, las hojas sin tipo en tramos
 * consecutivos y todo ordenado por la primera hoja del PDF.
 */
export function normalizar(documentos: DocumentoEditor[]): DocumentoEditor[] {
    const conTipo = documentos.filter(
        (d) => d.tipoId !== null && d.paginas.length > 0,
    );
    const sueltas = documentos
        .filter((d) => d.tipoId === null)
        .flatMap((d) => d.paginas)
        .sort((a, b) => a - b);
    const tramos: DocumentoEditor[] = [];
    let tramo: number[] = [];

    for (const numero of sueltas) {
        if (tramo.length && numero !== tramo[tramo.length - 1] + 1) {
            tramos.push({
                id: `sin-${tramo[0]}`,
                tipoId: null,
                paginas: tramo,
            });
            tramo = [];
        }

        tramo.push(numero);
    }

    if (tramo.length) {
        tramos.push({ id: `sin-${tramo[0]}`, tipoId: null, paginas: tramo });
    }

    return [...conTipo, ...tramos].sort(
        (a, b) => Math.min(...a.paginas) - Math.min(...b.paginas),
    );
}

export function documentoDe(
    documentos: DocumentoEditor[],
    pagina: number,
): DocumentoEditor | undefined {
    return documentos.find((d) => d.paginas.includes(pagina));
}

function sinPaginas(
    documentos: DocumentoEditor[],
    paginas: number[],
): DocumentoEditor[] {
    const quitar = new Set(paginas);

    return documentos.map((d) => ({
        ...d,
        paginas: d.paginas.filter((p) => !quitar.has(p)),
    }));
}

function ascendente(paginas: number[]): boolean {
    return paginas.every((p, i) => i === 0 || p > paginas[i - 1]);
}

/**
 * Asigna un tipo a las hojas elegidas (una o muchas). Si ya hay un
 * documento de ese tipo, las hojas se le unen (el expediente guarda una
 * versión por tipo), salvo que se pida `aparte`. Si las hojas elegidas son
 * exactamente un documento completo, solo se le cambia el tipo.
 */
export function asignarTipo(
    documentos: DocumentoEditor[],
    paginas: number[],
    tipoId: number | null,
    aparte = false,
): ResultadoOperacion {
    if (!paginas.length) {
        return { ok: false, error: 'Selecciona al menos una hoja.' };
    }

    const elegidas = [...new Set(paginas)].sort((a, b) => a - b);

    if (tipoId === null) {
        return {
            ok: true,
            documentos: normalizar([
                ...sinPaginas(documentos, elegidas),
                { id: nuevoId(), tipoId: null, paginas: elegidas },
            ]),
        };
    }

    const completo = documentos.find(
        (d) =>
            d.tipoId !== null &&
            d.paginas.length === elegidas.length &&
            elegidas.every((p) => d.paginas.includes(p)),
    );
    const existente = aparte
        ? undefined
        : documentos.find((d) => d.tipoId === tipoId && d.id !== completo?.id);

    if (completo && !existente) {
        return {
            ok: true,
            documentos: normalizar(
                documentos.map((d) =>
                    d.id === completo.id ? { ...d, tipoId } : d,
                ),
            ),
        };
    }

    const restantes = sinPaginas(documentos, elegidas);

    if (existente) {
        return {
            ok: true,
            documentos: normalizar(
                restantes.map((d) => {
                    if (d.id !== existente.id) {
                        return d;
                    }

                    const unidas = [...d.paginas, ...elegidas];

                    return {
                        ...d,
                        paginas: ascendente(existente.paginas)
                            ? unidas.sort((a, b) => a - b)
                            : unidas,
                    };
                }),
            ),
        };
    }

    return {
        ok: true,
        documentos: normalizar([
            ...restantes,
            { id: nuevoId(), tipoId, paginas: elegidas },
        ]),
    };
}

/**
 * «Continúa el documento anterior»: las hojas elegidas se unen al documento
 * que contiene la hoja inmediatamente anterior a la primera elegida (p. ej.
 * 9-11 al contrato que empieza en la 8), justo después de esa hoja.
 */
export function continuarAnterior(
    documentos: DocumentoEditor[],
    paginas: number[],
): ResultadoOperacion {
    if (!paginas.length) {
        return { ok: false, error: 'Selecciona las hojas que continúan.' };
    }

    const elegidas = [...new Set(paginas)].sort((a, b) => a - b);
    const anterior = elegidas[0] - 1;
    const destino =
        anterior >= 1 ? documentoDe(documentos, anterior) : undefined;

    if (!destino) {
        return {
            ok: false,
            error: 'La hoja 1 no tiene documento anterior.',
        };
    }

    if (destino.tipoId === null) {
        return {
            ok: false,
            error: `La hoja ${anterior} está sin clasificar: asígnale primero un tipo.`,
        };
    }

    const mover = elegidas.filter((p) => !destino.paginas.includes(p));
    const restantes = sinPaginas(documentos, mover);

    return {
        ok: true,
        documentos: normalizar(
            restantes.map((d) => {
                if (d.id !== destino.id) {
                    return d;
                }

                const posicion = d.paginas.indexOf(anterior) + 1;

                return {
                    ...d,
                    paginas: [
                        ...d.paginas.slice(0, posicion),
                        ...mover,
                        ...d.paginas.slice(posicion),
                    ],
                };
            }),
        ),
    };
}

/**
 * «Nuevo documento desde esta hoja»: parte el documento en dos; la hoja
 * elegida y las que le siguen (en el orden del documento) forman uno nuevo
 * del mismo tipo, que luego se puede cambiar.
 */
export function partirDesde(
    documentos: DocumentoEditor[],
    pagina: number,
): ResultadoOperacion {
    const origen = documentoDe(documentos, pagina);

    if (!origen || origen.tipoId === null) {
        return {
            ok: false,
            error: 'Esta hoja está sin clasificar: asígnale un tipo directamente.',
        };
    }

    const indice = origen.paginas.indexOf(pagina);

    if (indice === 0) {
        return {
            ok: false,
            error: `La hoja ${pagina} ya es la primera de su documento.`,
        };
    }

    return {
        ok: true,
        documentos: normalizar([
            ...documentos.map((d) =>
                d.id === origen.id
                    ? { ...d, paginas: d.paginas.slice(0, indice) }
                    : d,
            ),
            {
                id: nuevoId(),
                tipoId: origen.tipoId,
                paginas: origen.paginas.slice(indice),
            },
        ]),
    };
}

/**
 * Mueve las hojas elegidas a otro documento. Si el destino conserva el orden
 * del PDF (ascendente), las hojas entran en su lugar (mover la 4 a «5-6» da
 * «4-6», no «5, 6, 4»); si RH ya lo reordenó a mano, van al final.
 */
export function moverA(
    documentos: DocumentoEditor[],
    paginas: number[],
    destinoId: string,
): ResultadoOperacion {
    const destino = documentos.find((d) => d.id === destinoId);

    if (!destino) {
        return { ok: false, error: 'Ese documento ya no existe.' };
    }

    const mover = [...new Set(paginas)]
        .sort((a, b) => a - b)
        .filter((p) => !destino.paginas.includes(p));

    if (!mover.length) {
        return { ok: false, error: 'Esas hojas ya están en ese documento.' };
    }

    return {
        ok: true,
        documentos: normalizar(
            sinPaginas(documentos, mover).map((d) => {
                if (d.id !== destinoId) {
                    return d;
                }

                const unidas = [...d.paginas, ...mover];

                return {
                    ...d,
                    paginas: ascendente(d.paginas)
                        ? unidas.sort((a, b) => a - b)
                        : unidas,
                };
            }),
        ),
    };
}

/** Sube (-1) o baja (+1) una hoja dentro de su documento. */
export function reordenar(
    documentos: DocumentoEditor[],
    pagina: number,
    delta: -1 | 1,
): ResultadoOperacion {
    const origen = documentoDe(documentos, pagina);

    if (!origen || origen.tipoId === null) {
        return {
            ok: false,
            error: 'Solo se reordenan hojas de un documento clasificado.',
        };
    }

    const i = origen.paginas.indexOf(pagina);
    const j = i + delta;

    if (j < 0 || j >= origen.paginas.length) {
        return { ok: false, error: 'La hoja ya está en ese extremo.' };
    }

    const paginas = [...origen.paginas];
    [paginas[i], paginas[j]] = [paginas[j], paginas[i]];

    return {
        ok: true,
        documentos: normalizar(
            documentos.map((d) => (d.id === origen.id ? { ...d, paginas } : d)),
        ),
    };
}

/**
 * «5-7, 9» → [5, 6, 7, 9]. Acepta comas, espacios y guiones (también «–»).
 */
export function parsearRango(
    texto: string,
    total: number,
): { paginas: number[]; error: string | null } {
    const paginas = new Set<number>();
    const partes = texto
        .split(/[,;\s]+/)
        .map((p) => p.trim())
        .filter(Boolean);

    if (!partes.length) {
        return { paginas: [], error: 'Escribe hojas como «5-7, 9».' };
    }

    for (const parte of partes) {
        const rango = /^(\d+)\s*[-–]\s*(\d+)$/.exec(parte);
        const suelta = /^\d+$/.test(parte) ? Number(parte) : null;

        if (!rango && suelta === null) {
            return {
                paginas: [],
                error: `«${parte}» no es una hoja ni un rango.`,
            };
        }

        const inicio = rango ? Number(rango[1]) : (suelta as number);
        const fin = rango ? Number(rango[2]) : (suelta as number);

        if (inicio > fin) {
            return { paginas: [], error: `El rango «${parte}» está al revés.` };
        }

        if (inicio < 1 || fin > total) {
            return {
                paginas: [],
                error: `Las hojas van de 1 a ${total}; «${parte}» se sale.`,
            };
        }

        for (let n = inicio; n <= fin; n++) {
            paginas.add(n);
        }
    }

    return { paginas: [...paginas].sort((a, b) => a - b), error: null };
}

/** [5, 6, 7, 9] → «5-7, 9» (en orden ascendente). */
export function formatearRango(paginas: number[]): string {
    const orden = [...paginas].sort((a, b) => a - b);
    const tramos: string[] = [];
    let inicio: number | null = null;
    let anterior: number | null = null;

    for (const p of orden) {
        if (inicio === null || anterior === null) {
            inicio = anterior = p;
        } else if (p === anterior + 1) {
            anterior = p;
        } else {
            tramos.push(
                inicio === anterior ? `${inicio}` : `${inicio}-${anterior}`,
            );
            inicio = anterior = p;
        }
    }

    if (inicio !== null && anterior !== null) {
        tramos.push(
            inicio === anterior ? `${inicio}` : `${inicio}-${anterior}`,
        );
    }

    return tramos.join(', ');
}

/** Hojas en el orden del documento, compacto si son consecutivas («8-11»). */
export function describirOrden(paginas: number[]): string {
    return ascendente(paginas) ? formatearRango(paginas) : paginas.join(', ');
}

export function planDe(documentos: DocumentoEditor[]): DocumentoPlanEditor[] {
    return documentos.map((d) => ({
        document_type_id: d.tipoId,
        paginas: [...d.paginas],
    }));
}

export function firmaPlan(documentos: DocumentoEditor[]): string {
    return JSON.stringify(planDe(normalizar(documentos)));
}

/**
 * Colores por tipo de documento: solo tokens pastel del tema (nunca hex
 * sueltos). «Sin clasificar» tiene su propio estilo (aviso).
 */
const PALETA = [
    'bg-salvia border-esmeralda/50',
    'bg-crema border-oro/60',
    'bg-info-soft border-info/50',
    'bg-olivo border-bronce/40',
    'bg-menta border-esmeralda/40',
    'bg-crema-2 border-bronce/50',
    'bg-success-soft border-success/50',
    'bg-verde-suave border-esmeralda/50',
    'bg-oro/25 border-oro/70',
    'bg-esmeralda/20 border-esmeralda/60',
    'bg-bronce/20 border-bronce/60',
    'bg-primary/10 border-primary/40',
];

export const ESTILO_SIN_CLASIFICAR =
    'bg-warning-soft border-warning/60 border-dashed';

export function estiloTipo(
    tipoId: number | null,
    tipos: { id: number }[],
): string {
    if (tipoId === null) {
        return ESTILO_SIN_CLASIFICAR;
    }

    const indice = tipos.findIndex((t) => t.id === tipoId);

    return PALETA[(indice < 0 ? 0 : indice) % PALETA.length];
}
