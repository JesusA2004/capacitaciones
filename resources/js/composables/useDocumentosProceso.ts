import { leerCookie } from '@/lib/http';
import * as rutasProcedimiento from '@/routes/rh/cierres/procedimiento';
import { descargar as descargarDocumentoLaboral } from '@/routes/rh/documentos-laborales';
import * as rutasProceso from '@/routes/rh/documentos-proceso';
import * as rutasPrevia from '@/routes/rh/documentos-proceso/previa';
import type {
    DatoFaltante,
    DocumentoVistaPrevia,
    FlujoVistaPrevia,
    SeccionDocumentosProceso,
    TipoRegistroDocumental,
} from '@/types';

/**
 * Cliente de "Documentos del proceso" (rutas web JSON, mismas reglas que la
 * API móvil). Los errores conservan el código del backend para mostrar el
 * modal de datos faltantes o el aviso de formato no cargado.
 */
export class ErrorDocumento extends Error {
    constructor(
        mensaje: string,
        public readonly estado: number,
        public readonly codigo: string | null,
        public readonly faltantes: DatoFaltante[] = [],
        public readonly detalle: Record<string, unknown> = {},
    ) {
        super(mensaje);
    }
}

type Cuerpo = Record<string, unknown> | FormData;

async function solicitar<T>(
    metodo: 'GET' | 'POST',
    url: string,
    cuerpo?: Cuerpo,
): Promise<T> {
    const esForm = cuerpo instanceof FormData;
    const respuesta = await fetch(url, {
        method: metodo,
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            ...(esForm || cuerpo === undefined
                ? {}
                : { 'Content-Type': 'application/json' }),
        },
        credentials: 'same-origin',
        body:
            cuerpo === undefined
                ? undefined
                : esForm
                  ? cuerpo
                  : JSON.stringify(cuerpo),
    });

    const datos = (await respuesta.json().catch(() => null)) as
        | (T & {
              message?: string;
              code?: string;
              faltantes?: DatoFaltante[];
              detalle?: Record<string, unknown>;
              errors?: Record<string, string[]>;
          })
        | null;

    if (!respuesta.ok) {
        const primerError = datos?.errors
            ? Object.values(datos.errors)[0]?.[0]
            : undefined;

        throw new ErrorDocumento(
            datos?.message && datos.code
                ? datos.message
                : (primerError ??
                      datos?.message ??
                      (respuesta.status === 403
                          ? 'No tienes permiso para esta acción.'
                          : `Error ${respuesta.status}`)),
            respuesta.status,
            datos?.code ?? null,
            datos?.faltantes ?? [],
            datos?.detalle ?? {},
        );
    }

    return datos as T;
}

export function useDocumentosProceso() {
    return {
        delColaborador: (colaboradorId: number) =>
            solicitar<{ data: SeccionDocumentosProceso[] }>(
                'GET',
                rutasProceso.colaborador.url(colaboradorId),
            ).then((r) => r.data),

        seccion: (tipo: TipoRegistroDocumental, id: number, proceso?: string) =>
            solicitar<{ data: SeccionDocumentosProceso }>(
                'GET',
                rutasProceso.show.url(
                    { tipo, id },
                    proceso ? { query: { proceso } } : undefined,
                ),
            ).then((r) => r.data),

        generar: (
            tipo: TipoRegistroDocumental,
            id: number,
            cuerpo: {
                clave: string;
                proceso?: string;
                regenerar?: boolean;
                revision?: boolean;
                motivo?: string;
                completar?: Record<string, string>;
                manuales?: Record<string, string>;
                /** Solicitud de empleo / Estudio socioeconómico (AAAA-MM-DD). */
                fecha_emision?: string;
            },
        ) =>
            solicitar<{
                data: SeccionDocumentosProceso;
                documento_id: number;
                creado?: boolean;
            }>('POST', rutasProceso.generar.url({ tipo, id }), cuerpo),

        paquete: (
            tipo: TipoRegistroDocumental,
            id: number,
            cuerpo: { proceso?: string; completar?: Record<string, string> },
        ) =>
            solicitar<{ data: SeccionDocumentosProceso; message: string }>(
                'POST',
                rutasProceso.paquete.url({ tipo, id }),
                cuerpo,
            ),

        operar: (documentoId: number, accion: string, cuerpo: Cuerpo) =>
            solicitar<{ message: string }>(
                'POST',
                rutasProceso.operar.url({ documento: documentoId, accion }),
                cuerpo,
            ),

        procedimiento: (
            cierreId: number,
            accion: 'negativa' | 'testigos' | 'etapa',
            cuerpo: Cuerpo,
        ) =>
            solicitar<{ data: SeccionDocumentosProceso; message: string }>(
                'POST',
                rutasProcedimiento[accion].url(cierreId),
                cuerpo,
            ),

        /** Vista previa REAL (PDF inline, no guarda nada) de un documento del proceso. */
        urlVistaPrevia: (
            tipo: TipoRegistroDocumental,
            id: number,
            parametros: {
                clave: string;
                proceso?: string;
                completar?: Record<string, string>;
                manuales?: Record<string, string>;
                fecha_emision?: string;
            },
        ) => {
            const consulta = new URLSearchParams({ clave: parametros.clave });

            if (parametros.proceso) {
                consulta.set('proceso', parametros.proceso);
            }

            if (parametros.fecha_emision) {
                consulta.set('fecha_emision', parametros.fecha_emision);
            }

            for (const [grupo, valores] of [
                ['completar', parametros.completar],
                ['manuales', parametros.manuales],
            ] as const) {
                Object.entries(valores ?? {}).forEach(([k, v]) =>
                    consulta.set(`${grupo}[${k}]`, v),
                );
            }

            return `${rutasProceso.vistaPrevia.url({ tipo, id })}?${consulta.toString()}`;
        },

        /**
         * Documentos que un flujo generará al confirmarse (renovación, no
         * renovación, baja, préstamo), con la URL de vista previa de cada uno
         * armada con los datos del formulario. Nada se guarda.
         */
        previaFlujo: (
            flujo: FlujoVistaPrevia,
            id: number,
            datos: Record<string, string | number | null | undefined>,
        ) => {
            const consulta = new URLSearchParams();

            Object.entries(datos).forEach(([k, v]) => {
                if (v !== null && v !== undefined && v !== '') {
                    consulta.set(k, String(v));
                }
            });

            return solicitar<{ data: DocumentoVistaPrevia[] }>(
                'GET',
                `${rutasPrevia.documentos.url({ flujo, id })}?${consulta.toString()}`,
            ).then((r) => r.data);
        },

        urlDescarga: (documentoId: number) =>
            descargarDocumentoLaboral.url(documentoId),

        urlWord: (documentoId: number) => rutasProceso.word.url(documentoId),
    };
}
