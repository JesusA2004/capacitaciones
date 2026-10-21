import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * Descarga de archivos que el servidor tarda en preparar (ZIP de expediente,
 * reportes pesados…). En vez de un <a download> mudo, se pide el archivo con
 * fetch() para poder mostrar un estado de «preparando», detectar errores
 * (403, 429, 500) con un mensaje claro y permitir cancelar con AbortController.
 *
 * Uso típico junto con <DescargaEnProgreso>:
 *   const descarga = useDescargaArchivo();
 *   descarga.descargar(url, { nombreRespaldo: 'expediente.zip' });
 */

export type EstadoDescarga =
    'inactiva' | 'preparando' | 'lista' | 'error' | 'cancelada';

export type OpcionesDescarga = {
    /** Nombre del archivo si el servidor no manda Content-Disposition. */
    nombreRespaldo?: string;
    /** Milisegundos que se muestra «¡Listo!» antes de cerrar (0 = no cerrar). */
    cierreAutomaticoMs?: number;
};

const CIERRE_AUTOMATICO_MS = 1500;

/**
 * Extrae el nombre de archivo de Content-Disposition. Prefiere filename*
 * (RFC 5987, UTF-8 percent-encoded: conserva acentos y ñ) sobre filename.
 */
export function nombreDesdeContentDisposition(
    cabecera: string | null,
): string | null {
    if (!cabecera) {
        return null;
    }

    const extendido = /filename\*\s*=\s*([^']*)'[^']*'([^;]+)/i.exec(cabecera);

    if (extendido) {
        const valor = extendido[2].trim().replace(/^"(.*)"$/, '$1');

        try {
            return decodeURIComponent(valor);
        } catch {
            // Codificación inválida: se intenta con filename normal.
        }
    }

    const simple = /filename\s*=\s*("((?:\\.|[^"\\])*)"|[^;]+)/i.exec(cabecera);

    if (simple) {
        const valor = (simple[2] ?? simple[1]).trim();

        return valor.replace(/\\(.)/g, '$1') || null;
    }

    return null;
}

async function mensajeDeError(respuesta: Response): Promise<string> {
    let mensajeServidor: string | null = null;

    if (
        (respuesta.headers.get('Content-Type') ?? '').includes(
            'application/json',
        )
    ) {
        try {
            const cuerpo: unknown = await respuesta.json();

            if (
                cuerpo &&
                typeof cuerpo === 'object' &&
                'message' in cuerpo &&
                typeof cuerpo.message === 'string' &&
                cuerpo.message.trim() !== ''
            ) {
                mensajeServidor = cuerpo.message.trim();
            }
        } catch {
            // Cuerpo no legible: se usa el mensaje genérico del código.
        }
    }

    switch (respuesta.status) {
        case 401:
        case 419:
            return 'Tu sesión expiró. Recarga la página e inicia sesión de nuevo.';
        case 403:
            return (
                mensajeServidor ??
                'No tienes permiso para descargar este archivo.'
            );
        case 404:
            return 'No encontramos el archivo solicitado. Puede que ya no exista.';
        case 429: {
            const espera = Number(respuesta.headers.get('Retry-After'));
            const cuando =
                Number.isFinite(espera) && espera > 0
                    ? `en ${Math.ceil(espera)} segundos`
                    : 'en un minuto';

            return `Hiciste demasiadas descargas seguidas. Intenta de nuevo ${cuando}.`;
        }
        default:
            if (respuesta.status >= 500) {
                return 'El servidor no pudo preparar el archivo. Intenta de nuevo en unos momentos; si persiste, avisa a Sistemas.';
            }

            return (
                mensajeServidor ??
                `No se pudo descargar el archivo (código ${respuesta.status}).`
            );
    }
}

function entregarAlNavegador(blob: Blob, nombre: string): void {
    const url = URL.createObjectURL(blob);
    const enlace = document.createElement('a');

    enlace.href = url;
    enlace.download = nombre;
    enlace.rel = 'noopener';
    enlace.style.display = 'none';
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();

    // Se revoca después de que el navegador tomó la descarga.
    window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

export function useDescargaArchivo() {
    const estado = ref<EstadoDescarga>('inactiva');
    const error = ref<string | null>(null);
    const nombreArchivo = ref<string | null>(null);

    let controlador: AbortController | null = null;
    let temporizadorCierre: number | null = null;
    let ultimaUrl: string | null = null;
    let ultimasOpciones: OpcionesDescarga = {};

    const enProgreso = computed(() => estado.value === 'preparando');
    const visible = computed(
        () => estado.value !== 'inactiva' && estado.value !== 'cancelada',
    );

    function limpiarTemporizador(): void {
        if (temporizadorCierre !== null) {
            window.clearTimeout(temporizadorCierre);
            temporizadorCierre = null;
        }
    }

    async function descargar(
        url: string,
        opciones: OpcionesDescarga = {},
    ): Promise<boolean> {
        if (estado.value === 'preparando') {
            return false;
        }

        limpiarTemporizador();
        ultimaUrl = url;
        ultimasOpciones = opciones;
        controlador = new AbortController();
        estado.value = 'preparando';
        error.value = null;
        nombreArchivo.value = null;

        try {
            const respuesta = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: '*/*',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controlador.signal,
            });

            if (!respuesta.ok) {
                error.value = await mensajeDeError(respuesta);
                estado.value = 'error';

                return false;
            }

            // Una redirección al login (sesión vencida) llega como 200 HTML.
            if (
                respuesta.redirected &&
                (respuesta.headers.get('Content-Type') ?? '').includes(
                    'text/html',
                )
            ) {
                error.value =
                    'Tu sesión expiró. Recarga la página e inicia sesión de nuevo.';
                estado.value = 'error';

                return false;
            }

            const blob = await respuesta.blob();
            const nombre =
                nombreDesdeContentDisposition(
                    respuesta.headers.get('Content-Disposition'),
                ) ??
                opciones.nombreRespaldo ??
                'descarga';

            nombreArchivo.value = nombre;
            entregarAlNavegador(blob, nombre);
            estado.value = 'lista';

            const espera = opciones.cierreAutomaticoMs ?? CIERRE_AUTOMATICO_MS;

            if (espera > 0) {
                temporizadorCierre = window.setTimeout(cerrar, espera);
            }

            return true;
        } catch (e) {
            if (e instanceof DOMException && e.name === 'AbortError') {
                estado.value = 'cancelada';

                return false;
            }

            error.value =
                'No hay conexión con el servidor. Revisa tu red e intenta de nuevo.';
            estado.value = 'error';

            return false;
        } finally {
            controlador = null;
        }
    }

    function cancelar(): void {
        controlador?.abort();
    }

    function reintentar(): Promise<boolean> {
        if (!ultimaUrl) {
            return Promise.resolve(false);
        }

        return descargar(ultimaUrl, ultimasOpciones);
    }

    function cerrar(): void {
        if (estado.value === 'preparando') {
            return;
        }

        limpiarTemporizador();
        estado.value = 'inactiva';
        error.value = null;
    }

    onBeforeUnmount(() => {
        limpiarTemporizador();
        controlador?.abort();
    });

    return {
        estado,
        error,
        nombreArchivo,
        enProgreso,
        visible,
        descargar,
        cancelar,
        reintentar,
        cerrar,
    };
}
