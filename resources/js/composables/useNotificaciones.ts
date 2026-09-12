import { usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { useNotificacionesTiempoReal } from '@/composables/useNotificacionesTiempoReal';
import { postJson } from '@/lib/http';
import { index, marcarLeida, marcarTodasLeidas } from '@/routes/notificaciones';
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

/**
 * Sondeo simple (cada 30s) para la campana de notificaciones del layout. No
 * usa Inertia (no debe navegar ni reemplazar props de la pagina actual),
 * asi que reutiliza el mismo cliente fetch minimo que la vista previa de
 * asignaciones masivas (resources/js/lib/http.ts).
 */
export function useNotificaciones() {
    const page = usePage<{ auth: Auth }>();
    const noLeidas = ref(0);
    const recientes = ref<NotificacionItem[]>([]);

    async function cargar() {
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

    async function marcarComoLeida(id: string) {
        await postJson(marcarLeida.url(id), {});
        await cargar();
    }

    async function marcarTodasComoLeidas() {
        await postJson(marcarTodasLeidas.url(), {});
        await cargar();
    }

    let intervalo: ReturnType<typeof setInterval> | undefined;

    onMounted(() => {
        cargar();
        // El polling de 30s queda como respaldo (reconexion de red, Echo no
        // disponible); lo real-time viene de useNotificacionesTiempoReal,
        // que refresca al instante en cuanto llega un evento por Reverb.
        intervalo = setInterval(cargar, 30000);

        const userId = page.props.auth.user?.id;

        if (userId) {
            useNotificacionesTiempoReal(userId, () => cargar());
        }
    });

    onUnmounted(() => {
        clearInterval(intervalo);
    });

    return {
        noLeidas,
        recientes,
        cargar,
        marcarComoLeida,
        marcarTodasComoLeidas,
    };
}
