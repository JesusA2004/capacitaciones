import type * as PdfJs from 'pdfjs-dist';
import type { PDFDocumentProxy } from 'pdfjs-dist';

/**
 * Carga perezosa de pdf.js (solo lo descargan las pantallas que dibujan un
 * PDF, como el editor de plantillas oficiales).
 */
let modulo: Promise<typeof PdfJs> | null = null;

async function pdfjs(): Promise<typeof PdfJs> {
    modulo ??= Promise.all([
        import('pdfjs-dist'),
        import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
    ]).then(([lib, worker]) => {
        lib.GlobalWorkerOptions.workerSrc = worker.default;

        return lib;
    });

    return modulo;
}

export const PT_A_MM = 25.4 / 72;

export async function abrirPdf(url: string): Promise<PDFDocumentProxy> {
    const lib = await pdfjs();

    return lib.getDocument({ url, withCredentials: true }).promise;
}

export type BloqueTexto = {
    pagina: number;
    texto: string;
    x: number;
    y: number;
    ancho: number;
    alto: number;
};

/**
 * Texto de cada página con su caja en milímetros (origen arriba-izquierda):
 * lo que el backend usa para detectar campos con posiciones exactas.
 */
export async function textoConPosicion(
    documento: PDFDocumentProxy,
): Promise<BloqueTexto[]> {
    const bloques: BloqueTexto[] = [];

    for (let numero = 1; numero <= documento.numPages; numero++) {
        const pagina = await documento.getPage(numero);
        const viewport = pagina.getViewport({ scale: 1 });
        const contenido = await pagina.getTextContent();

        for (const item of contenido.items) {
            if (!('str' in item) || item.str.trim().length < 2) {
                continue;
            }

            const [, , c, d, e, f] = item.transform as number[];
            const alto = Math.hypot(c, d) || 10;
            const [vx, vy] = viewport.convertToViewportPoint(e, f);

            bloques.push({
                pagina: numero,
                texto: item.str.trim().slice(0, 500),
                x: round(vx * PT_A_MM),
                y: round(Math.max(0, vy - alto) * PT_A_MM),
                ancho: round(item.width * PT_A_MM),
                alto: round(alto * 1.15 * PT_A_MM),
            });
        }
    }

    return bloques;
}

function round(valor: number): number {
    return Math.round(valor * 100) / 100;
}

type PaginaPdfJs = Awaited<ReturnType<PDFDocumentProxy['getPage']>>;
type VistaPdfJs = ReturnType<PaginaPdfJs['getViewport']>;
type TareaRender = ReturnType<PaginaPdfJs['render']>;

const tareasPorLienzo = new WeakMap<HTMLCanvasElement, TareaRender>();

/**
 * Dibuja una página en un canvas cancelando antes el render anterior del
 * MISMO canvas: pdf.js no permite dos render() simultáneos sobre un canvas
 * ("Cannot use the same canvas during multiple render() operations"), cosa
 * que pasa al cambiar zoom/página rápido. La cancelación es el resultado
 * esperado del render viejo y se descarta; cualquier otro error se propaga.
 */
export async function renderizarEnLienzo(
    pagina: PaginaPdfJs,
    lienzo: HTMLCanvasElement,
    viewport: VistaPdfJs,
    tamanoCss: { width: string; height: string },
): Promise<void> {
    tareasPorLienzo.get(lienzo)?.cancel();

    const contexto = lienzo.getContext('2d');

    if (!contexto) {
        return;
    }

    lienzo.width = Math.floor(viewport.width);
    lienzo.height = Math.floor(viewport.height);
    lienzo.style.width = tamanoCss.width;
    lienzo.style.height = tamanoCss.height;

    const tarea = pagina.render({ canvasContext: contexto, viewport });
    tareasPorLienzo.set(lienzo, tarea);

    try {
        await tarea.promise;
    } catch (error) {
        if (!(
            error instanceof Error &&
            error.name === 'RenderingCancelledException'
        )) {
            throw error;
        }
    } finally {
        if (tareasPorLienzo.get(lienzo) === tarea) {
            tareasPorLienzo.delete(lienzo);
        }
    }
}
