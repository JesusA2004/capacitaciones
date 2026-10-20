import { onBeforeUnmount, ref } from 'vue';
import { toast } from 'vue-sonner';
import { ErrorDocumento } from '@/composables/useDocumentosProceso';
import type { ArchivoPrevisualizable, DatoFaltante } from '@/types';

/** Un PDF de vista previa por pedir al backend (endpoint protegido). */
export type PdfPorVer = { clave: string; nombre: string; url: string };

/**
 * Vista previa de documentos ANTES de generarlos (regla: todo formato para
 * un colaborador se puede ver antes de generarlo). Pide cada PDF al backend
 * —que lo renderiza sin guardar nada— y lo muestra en el visor único
 * (DocumentPreviewDialog) como galería. Se descarga primero como blob para
 * poder explicar un error del motor (formato faltante, conversor caído…)
 * en vez de pintar una página de error dentro del visor.
 *
 * El visor abre DE INMEDIATO con el esqueleto animado (`cargando` →
 * prop `cargando` de DocumentPreviewDialog) y `cargandoClave` dice qué
 * botón mostró el spinner. Cerrar el visor a media carga cancela la
 * petición. Si nada se pudo preparar, el visor se cierra y se avisa.
 */
export function useVistaPreviaPdf(alFallar?: (e: unknown) => void) {
    const abierto = ref(false);
    const cargando = ref(false);
    const indice = ref(0);
    const archivos = ref<ArchivoPrevisualizable[]>([]);
    /** Clave (o claves unidas por «|») del documento que se está generando. */
    const cargandoClave = ref<string | null>(null);
    /** Título del visor mientras aún no llega ningún archivo. */
    const tituloCarga = ref<string | null>(null);
    let urls: string[] = [];
    let control: AbortController | null = null;

    function liberar() {
        urls.forEach((u) => URL.revokeObjectURL(u));
        urls = [];
    }

    async function obtener(
        url: string,
        senal: AbortSignal,
    ): Promise<{ url: string; faltantes: number }> {
        const respuesta = await fetch(url, {
            signal: senal,
            // JSON para que un error del backend llegue como JSON (no redirect).
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!respuesta.ok) {
            const datos = (await respuesta.json().catch(() => null)) as {
                message?: string;
                code?: string;
                faltantes?: DatoFaltante[];
                detalle?: Record<string, unknown>;
                errors?: Record<string, string[]>;
            } | null;
            const primerError = datos?.errors
                ? Object.values(datos.errors)[0]?.[0]
                : undefined;

            throw new ErrorDocumento(
                datos?.message && datos.code
                    ? datos.message
                    : (primerError ??
                          datos?.message ??
                          (respuesta.status === 403
                              ? 'No tienes permiso para ver este documento.'
                              : 'No se pudo preparar la vista previa.')),
                respuesta.status,
                datos?.code ?? null,
                datos?.faltantes ?? [],
                datos?.detalle ?? {},
            );
        }

        return {
            url: URL.createObjectURL(await respuesta.blob()),
            faltantes: Number(respuesta.headers.get('X-Datos-Faltantes') ?? 0),
        };
    }

    /** Pide los PDFs y abre el visor con los que sí se pudieron preparar. */
    async function abrir(documentos: PdfPorVer[]) {
        if (cargando.value || documentos.length === 0) {
            return;
        }

        cargando.value = true;
        cargandoClave.value = documentos.map((d) => d.clave).join('|');
        tituloCarga.value =
            documentos.length === 1
                ? documentos[0].nombre
                : `${documentos.length} documentos`;
        liberar();
        archivos.value = [];
        indice.value = 0;
        abierto.value = true;
        const propio = new AbortController();
        control = propio;
        const lista: ArchivoPrevisualizable[] = [];
        let error: unknown = null;
        let faltantes = 0;

        for (const documento of documentos) {
            if (propio.signal.aborted) {
                break;
            }

            try {
                const pdf = await obtener(documento.url, propio.signal);
                urls.push(pdf.url);
                faltantes += pdf.faltantes;
                lista.push({
                    clave: documento.clave,
                    nombre: `${documento.nombre}.pdf`,
                    previewUrl: pdf.url,
                    mime: 'application/pdf',
                    tipo: 'pdf',
                    etiqueta: 'Vista previa · no válida para firma',
                });
            } catch (e) {
                if (!propio.signal.aborted) {
                    error ??= e;
                }
            }
        }

        // Se cerró el visor a media carga: alCambiarAbierto ya limpió el
        // estado (y quizá ya empezó otra vista previa); no se toca nada.
        if (propio.signal.aborted) {
            return;
        }

        cargando.value = false;
        cargandoClave.value = null;
        control = null;

        if (error !== null) {
            if (alFallar) {
                alFallar(error);
            } else {
                toast.error(
                    error instanceof Error
                        ? error.message
                        : 'No se pudo preparar la vista previa.',
                );
            }
        }

        if (lista.length === 0) {
            abierto.value = false;

            return;
        }

        if (faltantes > 0) {
            toast.info(
                'Faltan datos: en la vista previa quedan en blanco. Al generar se te pedirán.',
            );
        }

        archivos.value = lista;
        indice.value = 0;
    }

    function alCambiarAbierto(valor: boolean) {
        abierto.value = valor;

        if (!valor) {
            control?.abort();
            control = null;
            cargando.value = false;
            cargandoClave.value = null;
            liberar();
            archivos.value = [];
        }
    }

    /** ¿Este documento (por clave) es el que se está generando? */
    function generandoClave(clave: string): boolean {
        return (
            cargandoClave.value !== null &&
            cargandoClave.value.split('|').includes(clave)
        );
    }

    onBeforeUnmount(() => {
        control?.abort();
        liberar();
    });

    return {
        abierto,
        cargando,
        cargandoClave,
        tituloCarga,
        indice,
        archivos,
        abrir,
        alCambiarAbierto,
        generandoClave,
    };
}
