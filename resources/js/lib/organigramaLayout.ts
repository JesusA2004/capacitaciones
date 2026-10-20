/**
 * Acomodo del organigrama editable (sin dependencias, probado en
 * tests/js/organigrama-layout.test.mjs). Árbol «ordenado» por contornos
 * (Reingold–Tilford simplificado):
 *
 *  - Cada NIVEL del árbol es una fila: todas las tarjetas de un nivel
 *    comparten `y` (el árbol anterior, con flexbox anidado, alineaba cada
 *    rama por su cuenta y las líneas no cuadraban).
 *  - Las ramas hermanas se acercan hasta donde sus CONTORNOS lo permiten
 *    (nivel por nivel): una rama chica queda junto a la grande en vez de
 *    al otro extremo, y el padre queda centrado sobre su primer y último
 *    hijo — Dirección General justo encima de sus áreas.
 *  - Si TODOS los hijos de un nodo son hojas (gestores de un subgerente,
 *    coordinadoras de la regional…), se apilan en una columna bajo el
 *    padre con una guía vertical: no ensanchan el árbol.
 *  - Conectores ortogonales (bajada, travesaño a media altura, bajada).
 */

export type EntradaLayout = { clave: string; padre: string | null };

export type PosicionNodo = {
    clave: string;
    x: number;
    y: number;
    ancho: number;
    alto: number;
    nivel: number;
    /** Hoja apilada en columna (tarjeta compacta). */
    apilado: boolean;
};

export type OpcionesLayout = {
    ancho: number;
    alto: number;
    altoApilado: number;
    separacionX: number;
    separacionY: number;
    separacionApilado: number;
    sangriaApilado: number;
    /** Mínimo de hojas para apilarlas (con menos, van en fila). */
    minimoParaApilar: number;
};

export const OPCIONES_LAYOUT: OpcionesLayout = {
    ancho: 240,
    alto: 108,
    altoApilado: 64,
    separacionX: 28,
    separacionY: 64,
    separacionApilado: 10,
    sangriaApilado: 22,
    minimoParaApilar: 2,
};

export type ResultadoLayout = {
    posiciones: Map<string, PosicionNodo>;
    conectores: string[];
    ancho: number;
    alto: number;
};

/** Extremos izquierdo/derecho ocupados por nivel (relativos). */
type Contorno = Map<number, { min: number; max: number }>;

type Rama = {
    /** Desplazamiento de cada nodo respecto al borde izquierdo de la raíz. */
    nodos: {
        clave: string;
        dx: number;
        nivel: number;
        apilado: boolean;
        indice: number;
    }[];
    contorno: Contorno;
};

function unir(
    destino: Contorno,
    origen: Contorno,
    desplazamiento: number,
): void {
    for (const [nivel, { min, max }] of origen) {
        const actual = destino.get(nivel);
        destino.set(nivel, {
            min: Math.min(actual?.min ?? Infinity, min + desplazamiento),
            max: Math.max(actual?.max ?? -Infinity, max + desplazamiento),
        });
    }
}

/** Cuánto hay que recorrer `derecha` para no encimarse con `izquierda`. */
function separacionNecesaria(
    izquierda: Contorno,
    derecha: Contorno,
    hueco: number,
): number {
    let desplazamiento = -Infinity;

    for (const [nivel, { min }] of derecha) {
        const previo = izquierda.get(nivel);

        if (previo) {
            desplazamiento = Math.max(desplazamiento, previo.max + hueco - min);
        }
    }

    return Number.isFinite(desplazamiento) ? desplazamiento : 0;
}

export function calcularLayout(
    entradas: EntradaLayout[],
    opciones: OpcionesLayout = OPCIONES_LAYOUT,
    /**
     * Nodos con gente a cargo OCULTA (rama cerrada): no cuentan como hoja,
     * así un gerente con su rama cerrada sigue en la fila de su nivel en
     * vez de apilarse como si no tuviera a nadie.
     */
    conRamaOculta: Set<string> = new Set(),
): ResultadoLayout {
    const claves = new Set(entradas.map((e) => e.clave));
    const hijos = new Map<string, string[]>();
    const raices: string[] = [];

    for (const entrada of entradas) {
        // Un padre fuera de lo visible (recorte por sección o filtro) se
        // trata como raíz: nunca se pierde una tarjeta.
        if (entrada.padre === null || !claves.has(entrada.padre)) {
            raices.push(entrada.clave);
            continue;
        }

        const lista = hijos.get(entrada.padre) ?? [];
        lista.push(entrada.clave);
        hijos.set(entrada.padre, lista);
    }

    const filaAlto = opciones.alto + opciones.separacionY;
    const esHoja = (clave: string): boolean =>
        (hijos.get(clave) ?? []).length === 0 && !conRamaOculta.has(clave);
    const apila = (clave: string): boolean => {
        const propios = hijos.get(clave) ?? [];

        return (
            propios.length >= opciones.minimoParaApilar && propios.every(esHoja)
        );
    };

    const enCurso = new Set<string>();

    const armar = (clave: string, nivel: number): Rama => {
        const rama: Rama = {
            nodos: [{ clave, dx: 0, nivel, apilado: false, indice: 0 }],
            contorno: new Map([[nivel, { min: 0, max: opciones.ancho }]]),
        };

        // Protección contra ciclos (no deberían existir).
        if (enCurso.has(clave)) {
            return rama;
        }

        enCurso.add(clave);
        const propios = hijos.get(clave) ?? [];

        if (propios.length > 0 && apila(clave)) {
            const altoColumna =
                propios.length *
                    (opciones.altoApilado + opciones.separacionApilado) -
                opciones.separacionApilado;
            const filas = Math.max(
                1,
                Math.ceil((altoColumna + opciones.separacionY) / filaAlto),
            );

            propios.forEach((hijo, indice) => {
                rama.nodos.push({
                    clave: hijo,
                    dx: opciones.sangriaApilado,
                    nivel: nivel + 1,
                    apilado: true,
                    indice,
                });
            });

            for (let fila = 1; fila <= filas; fila++) {
                rama.contorno.set(nivel + fila, {
                    min: 0,
                    max: opciones.sangriaApilado + opciones.ancho,
                });
            }
        } else if (propios.length > 0) {
            const subramas = propios.map((hijo) => armar(hijo, nivel + 1));
            const desplazamientos: number[] = [];
            const acumulado: Contorno = new Map();

            subramas.forEach((sub, i) => {
                const desplazamiento =
                    i === 0
                        ? 0
                        : separacionNecesaria(
                              acumulado,
                              sub.contorno,
                              opciones.separacionX,
                          );
                desplazamientos.push(desplazamiento);
                unir(acumulado, sub.contorno, desplazamiento);
            });

            const primero = desplazamientos[0] ?? 0;
            const ultimo = desplazamientos[desplazamientos.length - 1] ?? 0;
            // Padre centrado sobre su primer y último hijo.
            const padreDx = (primero + ultimo) / 2;

            subramas.forEach((sub, i) => {
                const corrimiento = (desplazamientos[i] ?? 0) - padreDx;

                for (const nodo of sub.nodos) {
                    rama.nodos.push({ ...nodo, dx: nodo.dx + corrimiento });
                }

                unir(rama.contorno, sub.contorno, corrimiento);
            });
        }

        enCurso.delete(clave);

        return rama;
    };

    // Raíces una junto a otra, también por contornos.
    const relativos: Rama['nodos'] = [];
    const general: Contorno = new Map();

    raices.forEach((raiz, i) => {
        const rama = armar(raiz, 0);
        const desplazamiento =
            i === 0
                ? 0
                : separacionNecesaria(
                      general,
                      rama.contorno,
                      opciones.separacionX * 2,
                  );

        for (const nodo of rama.nodos) {
            relativos.push({ ...nodo, dx: nodo.dx + desplazamiento });
        }

        unir(general, rama.contorno, desplazamiento);
    });

    const minimoX = Math.min(0, ...relativos.map((n) => n.dx));
    const posiciones = new Map<string, PosicionNodo>();

    for (const nodo of relativos) {
        if (posiciones.has(nodo.clave)) {
            continue;
        }

        posiciones.set(nodo.clave, {
            clave: nodo.clave,
            x: nodo.dx - minimoX,
            y: nodo.apilado
                ? nodo.nivel * filaAlto +
                  nodo.indice *
                      (opciones.altoApilado + opciones.separacionApilado)
                : nodo.nivel * filaAlto,
            ancho: opciones.ancho,
            alto: nodo.apilado ? opciones.altoApilado : opciones.alto,
            nivel: nodo.nivel,
            apilado: nodo.apilado,
        });
    }

    // Conectores, ya con posiciones absolutas.
    const conectores: string[] = [];

    for (const [padre, propios] of hijos) {
        const p = posiciones.get(padre);
        const visibles = propios
            .map((hijo) => posiciones.get(hijo))
            .filter((pos): pos is PosicionNodo => pos !== undefined);

        if (!p || visibles.length === 0) {
            continue;
        }

        const baseY = p.y + p.alto;

        if (visibles.every((pos) => pos.apilado)) {
            const guiaX = p.x + opciones.sangriaApilado / 2;
            let ultimoMedio = baseY;

            for (const pos of visibles) {
                const medio = pos.y + pos.alto / 2;
                conectores.push(`M${guiaX} ${medio}H${pos.x}`);
                ultimoMedio = Math.max(ultimoMedio, medio);
            }

            conectores.push(`M${guiaX} ${baseY}V${ultimoMedio}`);
            continue;
        }

        const centroPadre = p.x + p.ancho / 2;
        const medioY = baseY + opciones.separacionY / 2;
        const centros = visibles.map((pos) => pos.x + pos.ancho / 2);
        conectores.push(`M${centroPadre} ${baseY}V${medioY}`);

        if (centros.length > 1 || centros[0] !== centroPadre) {
            conectores.push(
                `M${Math.min(centroPadre, ...centros)} ${medioY}H${Math.max(centroPadre, ...centros)}`,
            );
        }

        for (const pos of visibles) {
            conectores.push(`M${pos.x + pos.ancho / 2} ${medioY}V${pos.y}`);
        }
    }

    let ancho = 0;
    let alto = 0;

    for (const pos of posiciones.values()) {
        ancho = Math.max(ancho, pos.x + pos.ancho);
        alto = Math.max(alto, pos.y + pos.alto);
    }

    return { posiciones, conectores, ancho, alto };
}

/**
 * Zoom para que todo el árbol quepa en el recuadro (con margen), entre los
 * límites dados.
 */
export function zoomParaAjustar(
    contenido: { ancho: number; alto: number },
    caja: { ancho: number; alto: number },
    minimo = 0.2,
    maximo = 1,
): number {
    if (contenido.ancho <= 0 || contenido.alto <= 0) {
        return 1;
    }

    const escala = Math.min(
        (caja.ancho - 48) / contenido.ancho,
        (caja.alto - 48) / contenido.alto,
    );

    return Math.min(maximo, Math.max(minimo, Math.round(escala * 100) / 100));
}
