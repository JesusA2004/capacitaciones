import { onMounted, onUnmounted, ref } from 'vue';
import { postJson } from '@/lib/http';
import { index, marcarLeida, marcarTodasLeidas } from '@/routes/notificaciones';

export type NotificacionItem = {
    id: string;
    tipo: string | null;
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
        intervalo = setInterval(cargar, 30000);
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
