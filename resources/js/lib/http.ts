/**
 * Cliente minimo para los pocos endpoints que no encajan en una visita
 * Inertia normal (por ejemplo, previsualizaciones que no deben navegar ni
 * reemplazar props de la pagina). Usa la cookie XSRF-TOKEN que Laravel ya
 * establece en cada request, tal como documenta Laravel para clientes fetch.
 */
export function leerCookie(nombre: string): string | null {
    const valor = document.cookie
        .split('; ')
        .find((fila) => fila.startsWith(`${nombre}=`));

    return valor
        ? decodeURIComponent(valor.split('=').slice(1).join('='))
        : null;
}

export async function getJson<T>(url: string): Promise<T> {
    const respuesta = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!respuesta.ok) {
        throw new Error(`Error ${respuesta.status} al solicitar ${url}`);
    }

    return respuesta.json() as Promise<T>;
}

export async function postJson<T>(url: string, cuerpo: unknown): Promise<T> {
    const respuesta = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
        },
        credentials: 'same-origin',
        body: JSON.stringify(cuerpo),
    });

    if (!respuesta.ok) {
        throw new Error(`Error ${respuesta.status} al solicitar ${url}`);
    }

    return respuesta.json() as Promise<T>;
}

/**
 * Igual que postJson(), pero para endpoints que devuelven un archivo binario
 * (ej. una vista previa PNG) en vez de JSON — regresa una blob: URL lista
 * para usarse como src de una imagen. El caller es responsable de revocarla
 * (URL.revokeObjectURL) cuando ya no la necesite.
 */
export async function postBlobUrl(url: string, cuerpo: unknown): Promise<string> {
    const respuesta = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
        },
        credentials: 'same-origin',
        body: JSON.stringify(cuerpo),
    });

    if (!respuesta.ok) {
        throw new Error(`Error ${respuesta.status} al solicitar ${url}`);
    }

    return URL.createObjectURL(await respuesta.blob());
}

/**
 * Error de una solicitud JSON con el mensaje legible que mandó el backend
 * (validación 422 de Laravel: primer error de `errors`, o `message`).
 */
export class ErrorSolicitud extends Error {
    constructor(
        mensaje: string,
        public readonly estado: number,
    ) {
        super(mensaje);
    }
}

/**
 * Como postJson(), pero con cualquier método y regresando el mensaje de
 * validación del servidor en vez de un error genérico.
 */
export async function enviarJson<T>(
    metodo: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    url: string,
    cuerpo?: unknown,
): Promise<T> {
    const respuesta = await fetch(url, {
        method: metodo,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
        },
        credentials: 'same-origin',
        body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
    });

    const datos = (await respuesta.json().catch(() => null)) as
        | (T & { message?: string; errors?: Record<string, string[]> })
        | null;

    if (!respuesta.ok) {
        const primerError = datos?.errors
            ? Object.values(datos.errors)[0]?.[0]
            : undefined;

        throw new ErrorSolicitud(
            primerError ?? datos?.message ?? `Error ${respuesta.status}`,
            respuesta.status,
        );
    }

    return datos as T;
}
