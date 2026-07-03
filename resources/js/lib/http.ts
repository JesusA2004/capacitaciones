/**
 * Cliente minimo para los pocos endpoints que no encajan en una visita
 * Inertia normal (por ejemplo, previsualizaciones que no deben navegar ni
 * reemplazar props de la pagina). Usa la cookie XSRF-TOKEN que Laravel ya
 * establece en cada request, tal como documenta Laravel para clientes fetch.
 */
function leerCookie(nombre: string): string | null {
    const valor = document.cookie
        .split('; ')
        .find((fila) => fila.startsWith(`${nombre}=`));

    return valor
        ? decodeURIComponent(valor.split('=').slice(1).join('='))
        : null;
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
