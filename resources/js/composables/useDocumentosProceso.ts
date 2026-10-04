import { leerCookie } from '@/lib/http';
import type {
    DatoFaltante,
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
        );
    }

    return datos as T;
}

const base = '/rh/documentos-proceso';

export function useDocumentosProceso() {
    return {
        delColaborador: (colaboradorId: number) =>
            solicitar<{ data: SeccionDocumentosProceso[] }>(
                'GET',
                `${base}/colaborador/${colaboradorId}`,
            ).then((r) => r.data),

        seccion: (tipo: TipoRegistroDocumental, id: number, proceso?: string) =>
            solicitar<{ data: SeccionDocumentosProceso }>(
                'GET',
                `${base}/${tipo}/${id}${proceso ? `?proceso=${proceso}` : ''}`,
            ).then((r) => r.data),

        generar: (
            tipo: TipoRegistroDocumental,
            id: number,
            cuerpo: {
                clave: string;
                proceso?: string;
                regenerar?: boolean;
                completar?: Record<string, string>;
                manuales?: Record<string, string>;
            },
        ) =>
            solicitar<{ data: SeccionDocumentosProceso; documento_id: number }>(
                'POST',
                `${base}/${tipo}/${id}/generar`,
                cuerpo,
            ),

        paquete: (
            tipo: TipoRegistroDocumental,
            id: number,
            cuerpo: { proceso?: string; completar?: Record<string, string> },
        ) =>
            solicitar<{ data: SeccionDocumentosProceso; message: string }>(
                'POST',
                `${base}/${tipo}/${id}/paquete`,
                cuerpo,
            ),

        operar: (documentoId: number, accion: string, cuerpo: Cuerpo) =>
            solicitar<{ message: string }>(
                'POST',
                `${base}/documento/${documentoId}/${accion}`,
                cuerpo,
            ),

        procedimiento: (
            cierreId: number,
            accion: 'negativa' | 'testigos' | 'etapa',
            cuerpo: Cuerpo,
        ) =>
            solicitar<{ data: SeccionDocumentosProceso; message: string }>(
                'POST',
                `/rh/cierres/${cierreId}/procedimiento/${accion}`,
                cuerpo,
            ),

        urlDescarga: (documentoId: number) =>
            `/rh/documentos-laborales/${documentoId}/descargar`,
    };
}
