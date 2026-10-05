import { leerCookie } from '@/lib/http';
import {
    activar,
    desactivar,
    probar,
    show,
    validarDiseno,
} from '@/routes/rh/documentos-maestros';
import { store as cargarVersionRuta } from '@/routes/rh/documentos-maestros/versiones';
import type { MasterDetalle, ResultadoPruebaMaster } from '@/types';

/**
 * Cliente JSON de Administración → Documentos maestros. Los errores llegan
 * como texto listo para mostrar (el primer error de validación o el
 * mensaje del backend), nunca JSON crudo.
 */
export async function solicitar<T>(
    metodo: 'GET' | 'POST' | 'PUT' | 'DELETE',
    url: string,
    cuerpo?: FormData | Record<string, unknown>,
): Promise<T> {
    const esForm = cuerpo instanceof FormData;
    const r = await fetch(url, {
        method: metodo,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            ...(cuerpo && !esForm
                ? { 'Content-Type': 'application/json' }
                : {}),
        },
        body:
            cuerpo === undefined
                ? undefined
                : esForm
                  ? cuerpo
                  : JSON.stringify(cuerpo),
    });
    const datos = (await r.json().catch(() => null)) as
        (T & { message?: string; errors?: Record<string, string[]> }) | null;

    if (!r.ok) {
        const primero = datos?.errors
            ? Object.values(datos.errors)[0]?.[0]
            : undefined;

        throw new Error(
            primero ??
                datos?.message ??
                (r.status === 403
                    ? 'No tienes permiso para esta acción.'
                    : r.status === 429
                      ? 'Demasiados intentos seguidos: espera un minuto.'
                      : `Error ${r.status}`),
        );
    }

    return datos as T;
}

type ConDetalle = { message: string; data: MasterDetalle };

export function useDocumentosMaestros() {
    return {
        detalle: (id: number) =>
            solicitar<{ data: MasterDetalle }>('GET', show.url(id)).then(
                (r) => r.data,
            ),

        cargarVersion: (familia: string, archivo: File) => {
            const datos = new FormData();
            datos.append('archivo', archivo);

            return solicitar<ConDetalle>(
                'POST',
                cargarVersionRuta.url(familia),
                datos,
            );
        },

        validarDiseno: (id: number) =>
            solicitar<ConDetalle>('POST', validarDiseno.url(id)),

        activar: (id: number, motivoExcepcional?: string) =>
            solicitar<ConDetalle>(
                'POST',
                activar.url(id),
                motivoExcepcional
                    ? { motivo_excepcional: motivoExcepcional }
                    : {},
            ),

        desactivar: (id: number) =>
            solicitar<ConDetalle>('POST', desactivar.url(id)),

        probar: (id: number, colaboradorId: number) =>
            solicitar<{ data: ResultadoPruebaMaster }>('POST', probar.url(id), {
                colaborador_id: colaboradorId,
            }).then((r) => r.data),
    };
}
