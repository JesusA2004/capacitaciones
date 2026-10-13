import type { PDFDocumentProxy } from 'pdfjs-dist';
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';
import { PT_A_MM, abrirPdf, renderizarEnLienzo } from '@/lib/pdf';

export type PaginaPdf = { numero: number; anchoMm: number; altoMm: number };

/**
 * Abre un PDF una sola vez (pdf.js, carga perezosa) y expone sus páginas en
 * milímetros — las mismas unidades (origen arriba-izquierda) que usa el
 * backend para las cajas del overlay. Lo comparten el lienzo del editor y
 * las miniaturas.
 */
export function usePdfDocumento(url: Ref<string | null>) {
    const documento = shallowRef<PDFDocumentProxy | null>(null);
    const paginas = ref<PaginaPdf[]>([]);
    const cargando = ref(false);
    const fallo = ref(false);

    async function cargar(direccion: string | null): Promise<void> {
        void documento.value?.destroy();
        documento.value = null;
        paginas.value = [];
        fallo.value = false;

        if (!direccion) {
            return;
        }

        cargando.value = true;

        try {
            const doc = await abrirPdf(direccion);
            const lista: PaginaPdf[] = [];

            for (let n = 1; n <= doc.numPages; n++) {
                const viewport = (await doc.getPage(n)).getViewport({
                    scale: 1,
                });
                lista.push({
                    numero: n,
                    anchoMm: viewport.width * PT_A_MM,
                    altoMm: viewport.height * PT_A_MM,
                });
            }

            documento.value = doc;
            paginas.value = lista;
        } catch {
            fallo.value = true;
        } finally {
            cargando.value = false;
        }
    }

    /**
     * Dibuja una página en un canvas a `pxPorMm` píxeles por milímetro
     * (nitidez según la densidad de la pantalla).
     */
    async function dibujar(
        numero: number,
        lienzo: HTMLCanvasElement,
        pxPorMm: number,
    ): Promise<void> {
        const doc = documento.value;
        const pagina = paginas.value.find((p) => p.numero === numero);

        if (!doc || !pagina) {
            return;
        }

        const densidad = window.devicePixelRatio || 1;
        const pdfPagina = await doc.getPage(numero);
        const viewport = pdfPagina.getViewport({
            scale: (pxPorMm / PT_A_MM) * densidad,
        });

        await renderizarEnLienzo(pdfPagina, lienzo, viewport, {
            width: `${pagina.anchoMm * pxPorMm}px`,
            height: `${pagina.altoMm * pxPorMm}px`,
        });
    }

    watch(url, (nueva) => void cargar(nueva), { immediate: true });

    onBeforeUnmount(() => {
        void documento.value?.destroy();
    });

    return { documento, paginas, cargando, fallo, dibujar };
}
