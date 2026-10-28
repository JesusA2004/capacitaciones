import { router, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { useNotificacionesTiempoReal } from '@/composables/useNotificacionesTiempoReal';
import {
    alRecibirWebPush,
    useWebPush,
    webPushActivo,
} from '@/composables/useWebPush';
import {
    marcarPendientesEnPestana,
    notificacionDeSistema,
    pestanaAlFrente,
    reclamarAviso,
    sonarAviso,
} from '@/lib/avisosNavegador';
import { postJson } from '@/lib/http';
import {
    abrir as rutaAbrir,
    index,
    marcarLeida,
    marcarTodasLeidas,
} from '@/routes/notificaciones';
import type { Auth } from '@/types';

export type NotificacionItem = {
    id: string;
    tipo: string | null;
    emoji: string;
    color: string;
    titulo: string;
    mensaje: string;
    url: string | null;
    leida: boolean;
    creada_en: string | null;
};

/** Respuesta de POST /notificaciones/{id}/abrir (NotificacionesService::abrir). */
export type DestinoNotificacion = {
    url: string | null;
    atendida: boolean | null;
    estado_recurso: string | null;
    mensaje_estado: string | null;
    no_leidas: number;
};

/**
 * Estado compartido (singleton a nivel de módulo) de la campana: la campana
 * del encabezado, "Mis notificaciones" y cualquier otra vista leen y
 * actualizan el MISMO contador, así que marcar una como leída en cualquier
 * lugar descuenta el numerito al instante en todos.
 */
const noLeidas = ref(0);
const recientes = ref<NotificacionItem[]>([]);
/** Sube cada vez que llega algo nuevo: la campana lo usa para «sonar». */
const llegadas = ref(0);
let consumidores = 0;
let intervalo: ReturnType<typeof setInterval> | undefined;
let dejarDeEscucharPush: (() => void) | undefined;

// Ids ya vistos en esta pestaña: lo que aparezca fuera de este conjunto
// después de la primera carga es un aviso NUEVO.
const vistas = new Set<string>();
let primeraCarga = true;

/**
 * Único punto que avisa de lo nuevo, llegue por polling, Reverb o Web Push
 * (así nunca se duplica): toast en la página, notificación de Chrome si
 * la pestaña no está al frente, tono y contador en la pestaña — como
 * WhatsApp Web.
 */
function avisarNuevas(lista: NotificacionItem[]): void {
    const nuevas = lista.filter((n) => !n.leida && !vistas.has(n.id));
    lista.forEach((n) => vistas.add(n.id));

    if (primeraCarga) {
        primeraCarga = false;

        return;
    }

    const propias = nuevas.filter((n) => reclamarAviso(n.id));

    if (propias.length === 0) {
        return;
    }

    llegadas.value++;
    sonarAviso();

    // Varias de golpe: un solo aviso resumido, no una ráfaga.
    const mostrar = propias.length > 3 ? [] : propias;

    if (propias.length > 3) {
        toast.info(`Tienes ${propias.length} notificaciones nuevas`, {
            action: {
                label: 'Ver todas',
                onClick: () => router.visit('/notificaciones/centro'),
            },
        });
    }

    for (const n of mostrar) {
        toast.info(`${n.emoji} ${n.titulo}`.trim(), {
            description: n.mensaje,
            action: {
                label: 'Ver',
                onClick: () => void abrirNotificacion(n.id),
            },
        });
    }

    // Con Web Push activo la notificación del sistema ya la pinta
    // public/sw.js (incluso con la pestaña cerrada): no se duplica.
    if (!pestanaAlFrente() && !webPushActivo()) {
        if (propias.length > 3) {
            notificacionDeSistema(
                'resumen',
                'MR. LANA PEOPLE',
                `Tienes ${propias.length} notificaciones nuevas`,
                () => router.visit('/notificaciones/centro'),
            );
        } else {
            for (const n of mostrar) {
                notificacionDeSistema(
                    n.id,
                    n.titulo,
                    n.mensaje,
                    () => void abrirNotificacion(n.id),
                    n.url?.startsWith('/') ? n.url : undefined,
                );
            }
        }
    }
}

watch(noLeidas, (total) => marcarPendientesEnPestana(total));

async function cargar(): Promise<void> {
    const respuesta = await fetch(index.url(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!respuesta.ok) {
        return;
    }

    const datos = (await respuesta.json()) as {
        no_leidas: number;
        recientes: NotificacionItem[];
    };

    noLeidas.value = datos.no_leidas;
    recientes.value = datos.recientes;
    avisarNuevas(datos.recientes);
}

/** Marca leída en la copia local sin esperar al servidor. */
function marcarLocal(id: string): void {
    const local = recientes.value.find((n) => n.id === id);

    if (local && !local.leida) {
        local.leida = true;
        noLeidas.value = Math.max(0, noLeidas.value - 1);
    }
}

async function marcarComoLeida(id: string): Promise<void> {
    marcarLocal(id);
    await postJson(marcarLeida.url(id), {});
    await cargar();
}

async function marcarTodasComoLeidas(): Promise<void> {
    recientes.value = recientes.value.map((n) => ({ ...n, leida: true }));
    noLeidas.value = 0;
    await postJson(marcarTodasLeidas.url(), {});
    await cargar();
}

function avisarEstado(destino: DestinoNotificacion): void {
    if (!destino.mensaje_estado) {
        return;
    }

    if (destino.atendida === true) {
        toast.success('Ya fue atendida', {
            description: destino.mensaje_estado,
        });
    } else if (destino.atendida === false) {
        toast.warning('Pendiente de atender', {
            description: destino.mensaje_estado,
        });
    } else {
        toast.info(destino.mensaje_estado);
    }
}

/**
 * Clic en una notificación (campana, lista o toast en tiempo real): la
 * marca como leída (descuenta el contador de inmediato), lleva a la
 * pantalla exacta del recurso y, al llegar, avisa si lo que notificaba ya
 * fue atendido — con el estado ACTUAL, aunque se haya resuelto desde la app.
 */
async function abrirNotificacion(
    id: string,
): Promise<DestinoNotificacion | null> {
    marcarLocal(id);

    let destino: DestinoNotificacion;

    try {
        destino = await postJson<DestinoNotificacion>(rutaAbrir.url(id), {});
    } catch {
        toast.error('No se pudo abrir la notificación. Intenta de nuevo.');
        await cargar();

        return null;
    }

    noLeidas.value = destino.no_leidas;

    if (!destino.url) {
        avisarEstado(destino);

        return destino;
    }

    // Enlaces externos (p. ej. la liga de una reunión) se abren aparte.
    if (!destino.url.startsWith('/')) {
        window.open(destino.url, '_blank', 'noopener');
        avisarEstado(destino);

        return destino;
    }

    router.visit(destino.url, {
        onSuccess: () => avisarEstado(destino),
    });

    return destino;
}

// Al volver a la pestaña se refresca de inmediato (sin esperar el polling).
function alVolverALaPestana(): void {
    if (document.visibilityState === 'visible') {
        void cargar();
    }
}

export function useNotificaciones() {
    const page = usePage<{ auth: Auth }>();
    const webPush = useWebPush();

    onMounted(() => {
        consumidores++;

        if (consumidores > 1) {
            return;
        }

        void cargar();
        // El polling (20 s) queda como respaldo (reconexion de red, Echo no
        // disponible); lo real-time viene de useNotificacionesTiempoReal,
        // que refresca al instante en cuanto llega un evento por Reverb.
        intervalo = setInterval(() => void cargar(), 20000);
        document.addEventListener('visibilitychange', alVolverALaPestana);

        const userId = page.props.auth.user?.id;

        if (userId) {
            // Notificaciones de escritorio: si ya dio permiso, re-sincroniza
            // la suscripción de este navegador con su cuenta.
            void webPush.sincronizar();

            // Web Push o Reverb con la pestaña abierta: refrescan la campana
            // al instante y avisarNuevas() muestra el toast (una sola vez).
            dejarDeEscucharPush = alRecibirWebPush(() => void cargar());

            useNotificacionesTiempoReal(userId, () => void cargar());
        }
    });

    onUnmounted(() => {
        consumidores = Math.max(0, consumidores - 1);

        if (consumidores === 0) {
            clearInterval(intervalo);
            dejarDeEscucharPush?.();
            document.removeEventListener(
                'visibilitychange',
                alVolverALaPestana,
            );
        }
    });

    return {
        noLeidas,
        recientes,
        llegadas,
        cargar,
        marcarComoLeida,
        marcarTodasComoLeidas,
        abrirNotificacion,
    };
}
