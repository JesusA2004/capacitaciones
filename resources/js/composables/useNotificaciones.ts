import { router, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { useNotificacionesTiempoReal } from '@/composables/useNotificacionesTiempoReal';
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
let consumidores = 0;
let intervalo: ReturnType<typeof setInterval> | undefined;

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

export function useNotificaciones() {
    const page = usePage<{ auth: Auth }>();

    onMounted(() => {
        consumidores++;

        if (consumidores > 1) {
            return;
        }

        void cargar();
        // El polling de 30s queda como respaldo (reconexion de red, Echo no
        // disponible); lo real-time viene de useNotificacionesTiempoReal,
        // que refresca al instante en cuanto llega un evento por Reverb.
        intervalo = setInterval(() => void cargar(), 30000);

        const userId = page.props.auth.user?.id;

        if (userId) {
            useNotificacionesTiempoReal(
                userId,
                () => void cargar(),
                (id) => void abrirNotificacion(id),
            );
        }
    });

    onUnmounted(() => {
        consumidores = Math.max(0, consumidores - 1);

        if (consumidores === 0) {
            clearInterval(intervalo);
        }
    });

    return {
        noLeidas,
        recientes,
        cargar,
        marcarComoLeida,
        marcarTodasComoLeidas,
        abrirNotificacion,
    };
}
