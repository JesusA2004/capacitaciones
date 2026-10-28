import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { notificacionDeSistema } from '@/lib/avisosNavegador';
import { enviarJson } from '@/lib/http';

type EstadoWebPush =
    'no-soportado' | 'sin-configurar' | 'bloqueado' | 'pendiente' | 'activo';

const RUTA_SW = '/sw.js';

// Estado compartido por la campana, «Mis notificaciones» y el aviso de
// activación: un solo registro del Service Worker por pestaña.
const permiso = ref<NotificationPermission | 'no-soportado'>('default');
const suscrito = ref(false);
const procesando = ref(false);
let registroSw: Promise<ServiceWorkerRegistration | null> | null = null;

function soportado(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.isSecureContext &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

function aBytes(base64Url: string): Uint8Array<ArrayBuffer> {
    const relleno = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const base64 = (base64Url + relleno).replace(/-/g, '+').replace(/_/g, '/');
    const binario = window.atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(binario.length));

    for (let i = 0; i < binario.length; i++) {
        bytes[i] = binario.charCodeAt(i);
    }

    return bytes;
}

/**
 * Registra public/sw.js (solo notificaciones, sin caché) y desregistra
 * cualquier otro Service Worker residual de otro proyecto servido antes en
 * el mismo host:puerto (el origen del aviso «Fetch event handler is
 * recognized as no-op» en localhost).
 */
export function registrarServiceWorker(): Promise<ServiceWorkerRegistration | null> {
    if (registroSw) {
        return registroSw;
    }

    if (!soportado()) {
        registroSw = Promise.resolve(null);

        return registroSw;
    }

    registroSw = navigator.serviceWorker
        .getRegistrations()
        .then((registros) =>
            Promise.all(
                registros
                    .filter(
                        (r) =>
                            !(
                                r.active ??
                                r.installing ??
                                r.waiting
                            )?.scriptURL.endsWith(RUTA_SW),
                    )
                    .map((r) => r.unregister()),
            ),
        )
        .then(() => navigator.serviceWorker.register(RUTA_SW, { scope: '/' }))
        .catch(() => null);

    return registroSw;
}

/**
 * Notificaciones de escritorio (Web Push): con ellas, todo lo que llega a la
 * app móvil llega también como notificación del sistema (Windows, macOS,
 * Android), aunque la pestaña de PEOPLE esté cerrada — igual que YouTube o
 * Facebook. El servidor replica cada push en App\Services\WebPush\WebPushNotifier.
 */
export function useWebPush() {
    const page = usePage();
    const clavePublica = computed(
        () => page.props.webPush?.clavePublica ?? null,
    );

    if (soportado()) {
        permiso.value = Notification.permission;
    } else {
        permiso.value = 'no-soportado';
    }

    /**
     * Sin llaves VAPID en el servidor no hay Web Push, pero las
     * notificaciones de Chrome siguen funcionando con PEOPLE abierto (como
     * WhatsApp Web): basta el permiso del navegador (lib/avisosNavegador).
     */
    const modoLocal = computed(() => !clavePublica.value);

    const estado = computed<EstadoWebPush>(() => {
        if (permiso.value === 'no-soportado') {
            return 'no-soportado';
        }

        if (permiso.value === 'denied') {
            return 'bloqueado';
        }

        if (modoLocal.value) {
            return permiso.value === 'granted' ? 'activo' : 'pendiente';
        }

        return permiso.value === 'granted' && suscrito.value
            ? 'activo'
            : 'pendiente';
    });

    async function suscribirEnNavegador(): Promise<boolean> {
        const registro = await registrarServiceWorker();

        if (!registro || !clavePublica.value) {
            return false;
        }

        await navigator.serviceWorker.ready;

        let suscripcion = await registro.pushManager.getSubscription();

        if (suscripcion) {
            // Llaves VAPID distintas (servidor cambió): se rehace.
            const actual = suscripcion.options.applicationServerKey;
            const nueva = aBytes(clavePublica.value);

            if (
                actual &&
                !new Uint8Array(actual).every((b, i) => b === nueva[i])
            ) {
                await suscripcion.unsubscribe();
                suscripcion = null;
            }
        }

        suscripcion ??= await registro.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: aBytes(clavePublica.value),
        });

        await enviarJson('POST', '/notificaciones/web-push', {
            ...suscripcion.toJSON(),
            contentEncoding:
                (
                    PushManager as unknown as {
                        supportedContentEncodings?: string[];
                    }
                ).supportedContentEncodings?.[0] ?? 'aes128gcm',
        });

        suscrito.value = true;

        return true;
    }

    /** Botón «Activar»: pide permiso (solo con un clic del usuario) y suscribe. */
    async function activar(): Promise<boolean> {
        if (!soportado() || procesando.value) {
            return false;
        }

        procesando.value = true;

        try {
            permiso.value = await Notification.requestPermission();

            if (permiso.value !== 'granted') {
                return false;
            }

            if (modoLocal.value) {
                return true;
            }

            return await suscribirEnNavegador();
        } catch {
            return false;
        } finally {
            procesando.value = false;
        }
    }

    async function desactivar(): Promise<void> {
        const registro = await registrarServiceWorker();
        const suscripcion = await registro?.pushManager.getSubscription();

        if (suscripcion) {
            await enviarJson('DELETE', '/notificaciones/web-push', {
                endpoint: suscripcion.endpoint,
            }).catch(() => undefined);
            await suscripcion.unsubscribe().catch(() => undefined);
        }

        suscrito.value = false;
    }

    async function probar(): Promise<void> {
        if (modoLocal.value) {
            notificacionDeSistema(
                'prueba',
                'MR. LANA PEOPLE',
                'Así te avisaremos cuando llegue algo nuevo.',
                () => undefined,
            );

            return;
        }

        await enviarJson('POST', '/notificaciones/web-push/prueba');
    }

    /**
     * Al cargar cualquier página con sesión: si el permiso ya está concedido,
     * re-sincroniza la suscripción con el servidor (cubre cambio de cuenta en
     * el mismo navegador y llaves VAPID nuevas). Nunca pide permiso solo.
     */
    async function sincronizar(): Promise<void> {
        if (!soportado() || Notification.permission !== 'granted') {
            return;
        }

        await suscribirEnNavegador().catch(() => undefined);
    }

    return {
        estado,
        modoLocal,
        procesando,
        activar,
        desactivar,
        probar,
        sincronizar,
    };
}

/**
 * ¿Este navegador ya recibe Web Push? Entonces la notificación del sistema
 * la pinta public/sw.js y el tiempo real (Reverb) no debe duplicarla.
 */
export function webPushActivo(): boolean {
    return suscrito.value;
}

/**
 * Avisos que llegan por Web Push con la pestaña abierta: public/sw.js le
 * avisa a cada pestaña para refrescar la campana al instante.
 */
export function alRecibirWebPush(
    callback: (aviso: { titulo: string; cuerpo: string; url: string }) => void,
): () => void {
    if (!soportado()) {
        return () => undefined;
    }

    const manejador = (evento: MessageEvent) => {
        const datos = evento.data as
            | { tipo?: string; titulo?: string; cuerpo?: string; url?: string }
            | undefined;

        if (datos?.tipo === 'people:notificacion-nueva') {
            callback({
                titulo: datos.titulo ?? '',
                cuerpo: datos.cuerpo ?? '',
                url: datos.url ?? '/notificaciones/centro',
            });
        }
    };

    navigator.serviceWorker.addEventListener('message', manejador);

    return () =>
        navigator.serviceWorker.removeEventListener('message', manejador);
}

/**
 * Al cerrar sesión: el navegador deja de recibir los avisos de esa cuenta
 * (la siguiente persona que entre se suscribe con su propia sesión).
 */
export async function olvidarSuscripcionAlSalir(): Promise<void> {
    if (!soportado()) {
        return;
    }

    try {
        const registro = await navigator.serviceWorker.getRegistration('/');
        const suscripcion = await registro?.pushManager.getSubscription();

        if (suscripcion) {
            // En paralelo: aunque el servidor ya hubiera cerrado la sesión,
            // al desuscribir el navegador el endpoint deja de existir y el
            // siguiente envío lo depura (SendWebPushJob, 410).
            await Promise.allSettled([
                enviarJson('DELETE', '/notificaciones/web-push', {
                    endpoint: suscripcion.endpoint,
                }),
                suscripcion.unsubscribe(),
            ]);
        }
    } catch {
        // Sin Service Worker: nada que limpiar.
    }

    suscrito.value = false;
}
