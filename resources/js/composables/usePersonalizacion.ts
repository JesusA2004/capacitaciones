import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { personalizacion as actualizarPersonalizacion } from '@/routes';
import type { Auth } from '@/types';

export type TemaColorId =
    | 'verde'
    | 'azul'
    | 'morado'
    | 'naranja'
    | 'rosa'
    | 'gris';

export type PreferenciasUi = {
    tema_color: TemaColorId;
    avatar_color: string;
    animaciones: boolean;
};

export const TEMAS_COLOR: {
    id: TemaColorId;
    nombre: string;
    primario: string;
    secundario: string;
}[] = [
    { id: 'verde', nombre: 'Verde MR. LANA', primario: '#64d64b', secundario: '#2dc7d3' },
    { id: 'azul', nombre: 'Azul', primario: '#3b82f6', secundario: '#06b6d4' },
    { id: 'morado', nombre: 'Morado', primario: '#8b5cf6', secundario: '#d946ef' },
    { id: 'naranja', nombre: 'Naranja', primario: '#f97316', secundario: '#f59e0b' },
    { id: 'rosa', nombre: 'Rosa', primario: '#ec4899', secundario: '#f472b6' },
    { id: 'gris', nombre: 'Grafito', primario: '#64748b', secundario: '#475569' },
];

export const AVATAR_COLORES: { id: string; hex: string }[] = [
    { id: 'verde', hex: '#64d64b' },
    { id: 'azul', hex: '#3b82f6' },
    { id: 'morado', hex: '#8b5cf6' },
    { id: 'naranja', hex: '#f97316' },
    { id: 'rosa', hex: '#ec4899' },
    { id: 'rojo', hex: '#ef4444' },
    { id: 'amarillo', hex: '#eab308' },
    { id: 'gris', hex: '#64748b' },
];

export const PREFERENCIAS_UI_DEFAULT: PreferenciasUi = {
    tema_color: 'verde',
    avatar_color: '#64d64b',
    animaciones: true,
};

export function aplicarTema(id: TemaColorId): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.setAttribute('data-tema-color', id);
}

export function aplicarAnimaciones(activas: boolean): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle('sin-animaciones', !activas);
}

/**
 * Aplica el tema/animaciones guardados ANTES de que Vue monte, leyendo el
 * payload inicial de Inertia directamente del <script data-page> (el mismo
 * truco que usa Inertia por dentro): evita un parpadeo del tema por defecto
 * mientras el store de Vue termina de inicializarse.
 */
export function initializePersonalizacion(): void {
    if (typeof document === 'undefined') {
        return;
    }

    const nodo = document.querySelector('script[data-page]');

    if (!nodo?.textContent) {
        return;
    }

    try {
        const pagina = JSON.parse(nodo.textContent) as {
            props?: { auth?: Auth };
        };
        const prefs = pagina.props?.auth?.user?.preferencias_ui;

        aplicarTema(
            (prefs?.tema_color as TemaColorId) ??
                PREFERENCIAS_UI_DEFAULT.tema_color,
        );
        aplicarAnimaciones(prefs?.animaciones ?? PREFERENCIAS_UI_DEFAULT.animaciones);
    } catch {
        aplicarTema(PREFERENCIAS_UI_DEFAULT.tema_color);
        aplicarAnimaciones(PREFERENCIAS_UI_DEFAULT.animaciones);
    }
}

export function usePersonalizacion() {
    const page = usePage<{ auth: Auth }>();

    const preferencias = computed<PreferenciasUi>(() => {
        const prefs = page.props.auth.user?.preferencias_ui;

        return prefs
            ? {
                  tema_color: prefs.tema_color as TemaColorId,
                  avatar_color: prefs.avatar_color,
                  animaciones: prefs.animaciones,
              }
            : PREFERENCIAS_UI_DEFAULT;
    });

    function guardar(cambios: Partial<PreferenciasUi>) {
        const nuevas = { ...preferencias.value, ...cambios };

        aplicarTema(nuevas.tema_color);
        aplicarAnimaciones(nuevas.animaciones);

        router.patch(actualizarPersonalizacion.url(), nuevas, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    return { preferencias, guardar };
}
