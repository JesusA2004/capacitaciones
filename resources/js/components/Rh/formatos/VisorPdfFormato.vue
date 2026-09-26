<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import type { PDFDocumentProxy } from 'pdfjs-dist';
import { nextTick, onBeforeUnmount, ref, shallowRef, useTemplateRef, watch } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { PT_A_MM, abrirPdf, textoConPosicion } from '@/lib/pdf';
import type { BloqueTexto } from '@/lib/pdf';

/**
 * Dibuja cada página del PDF base de una plantilla (pdf.js, canvas) al
 * ancho disponible × zoom, y expone por página una capa encima (slot
 * `capa`) con la escala en píxeles por milímetro, para posicionar campos
 * con las mismas coordenadas que usa el backend (mm, arriba-izquierda).
 */
const props = withDefaults(
    defineProps<{
        url: string;
        zoom?: number;
    }>(),
    { zoom: 1 },
);

const emit = defineEmits<{
    cargado: [paginas: { numero: number; ancho: number; alto: number }[], bloques: BloqueTexto[]];
    error: [];
}>();

defineSlots<{
    capa(props: { pagina: number; escala: number; ancho: number; alto: number }): unknown;
}>();

type PaginaVisible = { numero: number; anchoMm: number; altoMm: number };

const contenedor = useTemplateRef<HTMLElement>('contenedor');
const lienzos = ref<Record<number, HTMLCanvasElement | null>>({});
const paginas = ref<PaginaVisible[]>([]);
const documento = shallowRef<PDFDocumentProxy | null>(null);
const cargando = ref(true);
const fallo = ref(false);
const anchoDisponible = ref(800);

const escala = (pagina: PaginaVisible) =>
    (Math.max(320, anchoDisponible.value - 16) * props.zoom) / pagina.anchoMm;

useResizeObserver(contenedor, (entradas) => {
    const ancho = entradas[0]?.contentRect.width ?? 800;

    if (Math.abs(ancho - anchoDisponible.value) > 4) {
        anchoDisponible.value = ancho;
    }
});

async function cargar() {
    cargando.value = true;
    fallo.value = false;

    try {
        documento.value?.destroy();
        const doc = await abrirPdf(props.url);
        documento.value = doc;
        const lista: PaginaVisible[] = [];

        for (let n = 1; n <= doc.numPages; n++) {
            const viewport = (await doc.getPage(n)).getViewport({ scale: 1 });
            lista.push({ numero: n, anchoMm: viewport.width * PT_A_MM, altoMm: viewport.height * PT_A_MM });
        }

        paginas.value = lista;
        cargando.value = false;
        await nextTick();
        await dibujar();

        emit(
            'cargado',
            lista.map((p) => ({ numero: p.numero, ancho: p.anchoMm, alto: p.altoMm })),
            await textoConPosicion(doc),
        );
    } catch {
        cargando.value = false;
        fallo.value = true;
        emit('error');
    }
}

let dibujando = false;

async function dibujar() {
    const doc = documento.value;

    if (!doc || dibujando) {
        return;
    }

    dibujando = true;

    try {
        const densidad = window.devicePixelRatio || 1;

        for (const pagina of paginas.value) {
            const lienzo = lienzos.value[pagina.numero];

            if (!lienzo) {
                continue;
            }

            const pdfPagina = await doc.getPage(pagina.numero);
            const pxPorMm = escala(pagina);
            const viewport = pdfPagina.getViewport({ scale: (pxPorMm / PT_A_MM) * densidad });

            lienzo.width = Math.floor(viewport.width);
            lienzo.height = Math.floor(viewport.height);
            lienzo.style.width = `${pagina.anchoMm * pxPorMm}px`;
            lienzo.style.height = `${pagina.altoMm * pxPorMm}px`;

            const contexto = lienzo.getContext('2d');

            if (contexto) {
                await pdfPagina.render({ canvasContext: contexto, viewport }).promise;
            }
        }
    } finally {
        dibujando = false;
    }
}

watch(() => props.url, cargar, { immediate: true });
watch([anchoDisponible, () => props.zoom], () => void dibujar());

onBeforeUnmount(() => {
    void documento.value?.destroy();
});
</script>

<template>
    <div ref="contenedor" class="w-full min-w-0">
        <div v-if="cargando" class="flex items-center justify-center gap-2 py-24 text-sm text-muted-foreground">
            <Spinner />
            Cargando documento…
        </div>
        <div v-else-if="fallo" class="py-24 text-center text-sm text-destructive">
            No se pudo mostrar el documento base.
        </div>
        <div v-else class="flex flex-col items-center gap-4 overflow-x-auto pb-4">
            <div
                v-for="pagina in paginas"
                :key="pagina.numero"
                class="relative shrink-0 bg-white shadow-md ring-1 ring-black/10"
                :style="{ width: `${pagina.anchoMm * escala(pagina)}px`, height: `${pagina.altoMm * escala(pagina)}px` }"
                :data-pagina="pagina.numero"
            >
                <canvas :ref="(el) => (lienzos[pagina.numero] = el as HTMLCanvasElement | null)" class="block" />
                <div class="absolute inset-0">
                    <slot
                        name="capa"
                        :pagina="pagina.numero"
                        :escala="escala(pagina)"
                        :ancho="pagina.anchoMm"
                        :alto="pagina.altoMm"
                    />
                </div>
                <span class="absolute -top-3 left-2 rounded bg-muted px-1.5 text-[11px] text-muted-foreground">
                    Página {{ pagina.numero }}
                </span>
            </div>
        </div>
    </div>
</template>
