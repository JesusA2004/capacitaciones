import { leerCookie } from '@/lib/http';
import type { DescripcionCorreccion } from '@/types';

/** Respuesta no exitosa de una corrección, con lo que la pantalla necesita. */
export class ErrorCorreccion extends Error {
    constructor(
        message: string,
        public readonly estado: number,
        public readonly errores: Record<string, string[]> = {},
        public readonly actual: DescripcionCorreccion | null = null,
    ) {
        super(message);
    }
}

/** Petición JSON de correcciones: `data` si salió bien; ErrorCorreccion si no. */
export async function pedirCorreccion<T>(
    url: string,
    cuerpo?: unknown,
): Promise<T> {
    let respuesta: Response;

    try {
        respuesta = await fetch(url, {
            method: cuerpo === undefined ? 'GET' : 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            },
            credentials: 'same-origin',
            body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
        });
    } catch {
        throw new ErrorCorreccion(
            'No hay conexión con el servidor. Tu captura sigue aquí: intenta de nuevo.',
            0,
        );
    }

    const datos = (await respuesta.json().catch(() => ({}))) as {
        data?: T;
        message?: string;
        errors?: Record<string, string[]>;
        actual?: DescripcionCorreccion;
    };

    if (!respuesta.ok) {
        throw new ErrorCorreccion(
            datos.message ??
                'No se pudo completar la corrección. Intenta de nuevo.',
            respuesta.status,
            datos.errors ?? {},
            datos.actual ?? null,
        );
    }

    return datos.data as T;
}
