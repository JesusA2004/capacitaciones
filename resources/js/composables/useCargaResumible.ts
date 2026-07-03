import { reactive } from 'vue';
import { leerCookie } from '@/lib/http';
import {
    bloque as rutaBloque,
    cancelar as rutaCancelar,
    estado as rutaEstado,
    iniciar as rutaIniciar,
    pausar as rutaPausar,
    reanudar as rutaReanudar,
} from '@/routes/multimedia/cargas';

export type EstadoCargaResumible =
    | 'inactivo'
    | 'en_progreso'
    | 'pausada'
    | 'ensamblando'
    | 'completada'
    | 'cancelada'
    | 'expirada'
    | 'error';

type RespuestaCarga = {
    identificador: string;
    estado: EstadoCargaResumible;
    tamano_total_bytes: number;
    tamano_bloque_bytes: number;
    total_bloques: number;
    bytes_recibidos: number;
    bloques_recibidos: number[];
    porcentaje: number;
    error: string | null;
    recurso_multimedia_id: number | null;
};

type CargaPendiente = {
    identificador: string;
    nombreOriginal: string;
    tamanoTotalBytes: number;
};

const CLAVE_LOCAL_STORAGE = 'capacitacion.carga_video_pendiente';

export function guardarCargaPendiente(carga: CargaPendiente) {
    localStorage.setItem(CLAVE_LOCAL_STORAGE, JSON.stringify(carga));
}

export function leerCargaPendiente(): CargaPendiente | null {
    const valor = localStorage.getItem(CLAVE_LOCAL_STORAGE);

    return valor ? (JSON.parse(valor) as CargaPendiente) : null;
}

export function limpiarCargaPendiente() {
    localStorage.removeItem(CLAVE_LOCAL_STORAGE);
}

function cabecerasJson(): HeadersInit {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
    };
}

async function leerRespuesta(respuesta: Response): Promise<RespuestaCarga> {
    const cuerpo = await respuesta.json().catch(() => null);

    if (!respuesta.ok) {
        throw new Error(cuerpo?.message ?? `Error ${respuesta.status}`);
    }

    return cuerpo as RespuestaCarga;
}

function subirBloque(
    identificador: string,
    numeroBloque: number,
    datosBloque: Blob,
    onProgreso: (bytesEnviados: number) => void,
): { promesa: Promise<RespuestaCarga>; xhr: XMLHttpRequest } {
    const xhr = new XMLHttpRequest();
    const formData = new FormData();
    formData.append('numero_bloque', String(numeroBloque));
    formData.append('bloque', datosBloque, 'bloque.part');

    const promesa = new Promise<RespuestaCarga>((resolve, reject) => {
        xhr.open('POST', rutaBloque.url(identificador));
        xhr.setRequestHeader('X-XSRF-TOKEN', leerCookie('XSRF-TOKEN') ?? '');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.withCredentials = true;

        xhr.upload.addEventListener('progress', (evento) => {
            if (evento.lengthComputable) {
                onProgreso(evento.loaded);
            }
        });

        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(JSON.parse(xhr.responseText));

                return;
            }

            let mensaje = `Error ${xhr.status} al subir el bloque ${numeroBloque}.`;

            try {
                mensaje = JSON.parse(xhr.responseText)?.message ?? mensaje;
            } catch {
                // Respuesta sin cuerpo JSON: se conserva el mensaje genérico.
            }

            reject(new Error(mensaje));
        });

        xhr.addEventListener('error', () =>
            reject(new Error('Error de red al subir el bloque.')),
        );
        xhr.addEventListener('abort', () => {
            const error = new Error('Carga pausada.');
            error.name = 'AbortError';
            reject(error);
        });

        xhr.send(formData);
    });

    return { promesa, xhr };
}

/**
 * Orquesta la carga de un video por bloques reanudable: parte el archivo en
 * el tamaño de bloque que indica el backend, envía los bloques faltantes en
 * secuencia (permitiendo pausar/cancelar en cualquier momento), y reporta
 * progreso/velocidad real basados en bytes efectivamente enviados.
 */
export function useCargaResumible() {
    const estadoActual = reactive({
        estado: 'inactivo' as EstadoCargaResumible,
        identificador: null as string | null,
        porcentaje: 0,
        bytesEnviados: 0,
        tamanoTotalBytes: 0,
        velocidadBps: 0,
        error: null as string | null,
        recursoMultimediaId: null as number | null,
    });

    let archivo: File | null = null;
    let tamanoBloque = 0;
    let totalBloques = 0;
    let bloquesRecibidos = new Set<number>();
    let xhrActual: XMLHttpRequest | null = null;
    let detenido = false;
    let ultimaMuestraTiempo = 0;
    let ultimaMuestraBytes = 0;

    function actualizarVelocidad(bytesTotalEnviados: number) {
        const ahora = performance.now();

        if (ultimaMuestraTiempo === 0) {
            ultimaMuestraTiempo = ahora;
            ultimaMuestraBytes = bytesTotalEnviados;

            return;
        }

        const deltaTiempoS = (ahora - ultimaMuestraTiempo) / 1000;

        if (deltaTiempoS >= 0.5) {
            estadoActual.velocidadBps =
                (bytesTotalEnviados - ultimaMuestraBytes) / deltaTiempoS;
            ultimaMuestraTiempo = ahora;
            ultimaMuestraBytes = bytesTotalEnviados;
        }
    }

    function aplicarRespuesta(respuesta: RespuestaCarga) {
        estadoActual.identificador = respuesta.identificador;
        estadoActual.porcentaje = respuesta.porcentaje;
        estadoActual.bytesEnviados = respuesta.bytes_recibidos;
        estadoActual.tamanoTotalBytes = respuesta.tamano_total_bytes;
        estadoActual.error = respuesta.error;
        estadoActual.recursoMultimediaId = respuesta.recurso_multimedia_id;
        totalBloques = respuesta.total_bloques;
        tamanoBloque = respuesta.tamano_bloque_bytes;
        bloquesRecibidos = new Set(respuesta.bloques_recibidos);
        estadoActual.estado = respuesta.estado;
    }

    async function enviarBloquesPendientes() {
        if (!archivo || !estadoActual.identificador) {
            return;
        }

        detenido = false;
        estadoActual.estado = 'en_progreso';

        for (let numero = 0; numero < totalBloques; numero++) {
            if (detenido) {
                return;
            }

            if (bloquesRecibidos.has(numero)) {
                continue;
            }

            const inicio = numero * tamanoBloque;
            const fin = Math.min(inicio + tamanoBloque, archivo.size);
            const datosBloque = archivo.slice(inicio, fin);

            const bytesPreviosDeOtrosBloques = estadoActual.bytesEnviados;

            const { promesa, xhr } = subirBloque(
                estadoActual.identificador,
                numero,
                datosBloque,
                (bytesEnviadosDeEsteBloque) => {
                    estadoActual.bytesEnviados =
                        bytesPreviosDeOtrosBloques + bytesEnviadosDeEsteBloque;
                    estadoActual.porcentaje = Math.min(
                        100,
                        Math.round(
                            (estadoActual.bytesEnviados /
                                estadoActual.tamanoTotalBytes) *
                                100,
                        ),
                    );
                    actualizarVelocidad(estadoActual.bytesEnviados);
                },
            );

            xhrActual = xhr;

            try {
                const respuesta = await promesa;
                aplicarRespuesta(respuesta);
            } catch (error) {
                if ((error as Error).name === 'AbortError') {
                    return;
                }

                estadoActual.estado = 'error';
                estadoActual.error =
                    error instanceof Error
                        ? error.message
                        : 'Error desconocido al subir el bloque.';

                throw error;
            } finally {
                xhrActual = null;
            }
        }
    }

    async function iniciar(archivoSeleccionado: File, tipo: string) {
        archivo = archivoSeleccionado;
        estadoActual.error = null;

        const respuesta = await fetch(rutaIniciar.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: cabecerasJson(),
            body: JSON.stringify({
                nombre_original: archivoSeleccionado.name,
                tipo,
                tamano_total_bytes: archivoSeleccionado.size,
            }),
        }).then(leerRespuesta);

        aplicarRespuesta(respuesta);
        guardarCargaPendiente({
            identificador: respuesta.identificador,
            nombreOriginal: archivoSeleccionado.name,
            tamanoTotalBytes: archivoSeleccionado.size,
        });

        await enviarBloquesPendientes();

        if (estadoActual.estado === 'completada') {
            limpiarCargaPendiente();
        }
    }

    /**
     * Reanuda una carga cuyo identificador ya se conoce (después de recargar
     * la página, por ejemplo), siempre que el usuario vuelva a seleccionar
     * el mismo archivo: el navegador no permite recuperar el contenido de un
     * archivo local automáticamente entre recargas por razones de seguridad.
     */
    async function reanudarConIdentificador(
        identificador: string,
        archivoSeleccionado: File,
    ) {
        archivo = archivoSeleccionado;
        estadoActual.error = null;

        const respuestaEstado = await fetch(rutaEstado.url(identificador), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).then(leerRespuesta);

        aplicarRespuesta(respuestaEstado);

        if (respuestaEstado.estado === 'pausada') {
            await fetch(rutaReanudar.url(identificador), {
                method: 'POST',
                credentials: 'same-origin',
                headers: cabecerasJson(),
            })
                .then(leerRespuesta)
                .then(aplicarRespuesta);
        }

        await enviarBloquesPendientes();

        if (estadoActual.estado === 'completada') {
            limpiarCargaPendiente();
        }
    }

    function pausar() {
        detenido = true;
        xhrActual?.abort();
        estadoActual.estado = 'pausada';

        if (estadoActual.identificador) {
            void fetch(rutaPausar.url(estadoActual.identificador), {
                method: 'POST',
                credentials: 'same-origin',
                headers: cabecerasJson(),
            });
        }
    }

    async function reanudar() {
        if (!estadoActual.identificador || !archivo) {
            return;
        }

        await fetch(rutaReanudar.url(estadoActual.identificador), {
            method: 'POST',
            credentials: 'same-origin',
            headers: cabecerasJson(),
        })
            .then(leerRespuesta)
            .then(aplicarRespuesta);

        await enviarBloquesPendientes();
    }

    async function cancelar() {
        detenido = true;
        xhrActual?.abort();

        if (estadoActual.identificador) {
            await fetch(rutaCancelar.url(estadoActual.identificador), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: cabecerasJson(),
            }).catch(() => undefined);
        }

        limpiarCargaPendiente();
        estadoActual.estado = 'cancelada';
    }

    function reiniciar() {
        archivo = null;
        tamanoBloque = 0;
        totalBloques = 0;
        bloquesRecibidos = new Set();
        detenido = false;
        ultimaMuestraTiempo = 0;
        ultimaMuestraBytes = 0;
        estadoActual.estado = 'inactivo';
        estadoActual.identificador = null;
        estadoActual.porcentaje = 0;
        estadoActual.bytesEnviados = 0;
        estadoActual.tamanoTotalBytes = 0;
        estadoActual.velocidadBps = 0;
        estadoActual.error = null;
    }

    return {
        estadoActual,
        iniciar,
        reanudarConIdentificador,
        pausar,
        reanudar,
        cancelar,
        reiniciar,
    };
}
