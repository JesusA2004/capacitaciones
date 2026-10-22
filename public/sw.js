/*
 * MR. LANA PEOPLE — Service Worker SOLO de notificaciones (Web Push).
 *
 * No intercepta peticiones (sin handler `fetch`, sin caché offline): su único
 * trabajo es mostrar como notificación del sistema (centro de notificaciones
 * de Windows/macOS/Android) cada aviso que el servidor envía, aunque la
 * pestaña de PEOPLE esté cerrada. El payload lo arma
 * App\Services\WebPush\WebPushNotifier::payload().
 */

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
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
