/*
 * MR. LANA PEOPLE — Service Worker (PWA + Web Push).
 *
 * 1) Notificaciones (Web Push): muestra como notificación del sistema
 *    (Windows/macOS/Android) cada aviso que manda el servidor, aunque la
 *    pestaña de PEOPLE esté cerrada. El payload lo arma
 *    App\Services\WebPush\WebPushNotifier::payload(). NO cambiar sin revisar
 *    resources/js/composables/useWebPush.ts.
 *
 * 2) App instalable (PWA) — caché MÍNIMA y segura (ver docs/PWA.md):
 *    - Nunca toca peticiones que no sean GET (subidas de documentos, formularios,
 *      cierre de sesión), las de Inertia (encabezado X-Inertia), /api/*,
 *      /broadcasting, /sanctum, ni nada de otro origen (Vite en desarrollo).
 *    - /build/assets/* (archivos con hash de Vite): primero caché, luego red.
 *    - Páginas (navegación): SIEMPRE a la red. Solo si no hay conexión se
 *      muestra /offline.html. Nunca se guarda HTML de una página con sesión,
 *      así que otra persona en el mismo equipo jamás ve datos ajenos.
 *
 * Al cambiar algo de la caché, sube VERSION: al activarse, borra las anteriores.
 */

const VERSION = 'v1';
const PREFIJO = 'people-';
const CACHE_ESTATICOS = `${PREFIJO}estaticos-${VERSION}`;
const CACHE_OFFLINE = `${PREFIJO}offline-${VERSION}`;
const PAGINA_OFFLINE = '/offline.html';
const PRECARGA_OFFLINE = [PAGINA_OFFLINE, '/branding/icono-192.png'];
const MAXIMO_ESTATICOS = 150;

// Rutas que el Service Worker nunca intercepta (ni siquiera GET).
const RUTAS_EXCLUIDAS = [
    '/api/',
    '/broadcasting',
    '/sanctum',
    '/logout',
    '/up',
    '/sw.js',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_OFFLINE)
            .then((cache) => cache.addAll(PRECARGA_OFFLINE))
            .catch(() => undefined)
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        Promise.all([
            caches.keys().then((nombres) =>
                Promise.all(
                    nombres
                        .filter(
                            (nombre) =>
                                nombre.startsWith(PREFIJO) &&
                                nombre !== CACHE_ESTATICOS &&
                                nombre !== CACHE_OFFLINE,
                        )
                        .map((nombre) => caches.delete(nombre)),
                ),
            ),
            // Navegación más rápida: la petición de la página sale en
            // paralelo al arranque del Service Worker.
            self.registration.navigationPreload
                ? self.registration.navigationPreload.enable().catch(() => undefined)
                : Promise.resolve(),
        ]).then(() => self.clients.claim()),
    );
});

function excluida(url) {
    return RUTAS_EXCLUIDAS.some(
        (ruta) => url.pathname === ruta || url.pathname.startsWith(ruta.endsWith('/') ? ruta : `${ruta}/`),
    );
}

async function recortarCache(cache) {
    const claves = await cache.keys();

    if (claves.length > MAXIMO_ESTATICOS) {
        await Promise.all(
            claves.slice(0, claves.length - MAXIMO_ESTATICOS).map((clave) => cache.delete(clave)),
        );
    }
}

async function primeroCache(request) {
    const cache = await caches.open(CACHE_ESTATICOS);
    const guardada = await cache.match(request);

    if (guardada) {
        return guardada;
    }

    const respuesta = await fetch(request);

    if (respuesta.ok && respuesta.type === 'basic') {
        await cache.put(request, respuesta.clone());
        recortarCache(cache).catch(() => undefined);
    }

    return respuesta;
}

async function navegacion(event) {
    try {
        const precargada = await event.preloadResponse;

        if (precargada) {
            return precargada;
        }

        return await fetch(event.request);
    } catch {
        const offline = await caches.match(PAGINA_OFFLINE);

        return (
            offline ||
            new Response('Sin conexión a internet.', {
                status: 503,
                headers: { 'Content-Type': 'text/plain; charset=utf-8' },
            })
        );
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    let url;

    try {
        url = new URL(request.url);
    } catch {
        return;
    }

    if (url.origin !== self.location.origin || excluida(url)) {
        return;
    }

    // Visitas de Inertia (XHR con JSON de la página): siempre a la red.
    if (request.headers.has('X-Inertia')) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(navegacion(event));

        return;
    }

    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(primeroCache(request));
    }
    // Todo lo demás (archivos del expediente, PDFs, imágenes privadas,
    // descargas): sin intervenir, directo a la red como siempre.
});

self.addEventListener('push', (event) => {
    let datos = {};

    try {
        datos = event.data ? event.data.json() : {};
    } catch {
        datos = { titulo: 'MR. LANA PEOPLE', cuerpo: event.data ? event.data.text() : '' };
    }

    const titulo = datos.titulo || 'MR. LANA PEOPLE';
    const opciones = {
        body: datos.cuerpo || '',
        icon: datos.icono || '/apple-touch-icon.png',
        badge: datos.insignia || undefined,
        tag: datos.tag || undefined,
        renotify: Boolean(datos.tag),
        data: { url: datos.url || '/notificaciones/centro' },
        requireInteraction: false,
    };

    event.waitUntil(
        Promise.all([
            self.registration.showNotification(titulo, opciones),
            // Pestañas abiertas: que la campana se actualice al instante.
            self.clients
                .matchAll({ type: 'window', includeUncontrolled: true })
                .then((ventanas) =>
                    ventanas.forEach((ventana) =>
                        ventana.postMessage({
                            tipo: 'people:notificacion-nueva',
                            titulo,
                            cuerpo: opciones.body,
                            url: opciones.data.url,
                        }),
                    ),
                ),
        ]),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const destino = new URL(
        (event.notification.data && event.notification.data.url) || '/notificaciones/centro',
        self.location.origin,
    ).href;

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((ventanas) => {
                // Reutiliza una pestaña de PEOPLE si ya hay una abierta.
                const abierta = ventanas.find((ventana) =>
                    ventana.url.startsWith(self.location.origin),
                );

                if (abierta) {
                    // navigate() solo funciona en pestañas que este SW
                    // controla; si no, se abre una nueva.
                    return abierta
                        .focus()
                        .then((ventana) => (ventana || abierta).navigate(destino))
                        .catch(() => self.clients.openWindow(destino));
                }

                return self.clients.openWindow(destino);
            }),
    );
});
