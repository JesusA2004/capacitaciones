<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    apariencia,
    jerarquia,
    notificaciones,
    parametrosRh,
} from '@/routes/administracion/configuracion';
import type { SeccionConfiguracion } from '@/types';

/**
 * Pestañas de Administración → Configuración: solo las secciones que el
 * backend dice que el usuario puede abrir (permisos configuracion.*).
 */
defineProps<{ secciones: SeccionConfiguracion[]; actual: string }>();

const rutas: Record<string, () => { url: string }> = {
    jerarquia: () => jerarquia(),
    notificaciones: () => notificaciones(),
    'parametros-rh': () => parametrosRh(),
    apariencia: () => apariencia(),
};
</script>

<template>
    <nav
        data-tour="configuracion-secciones"
        class="flex gap-1 overflow-x-auto border-b border-[var(--mrl-borde)]"
        aria-label="Secciones de configuración"
    >
        <Link
            v-for="s in secciones"
            :key="s.clave"
            :href="rutas[s.clave]().url"
            class="shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-colors"
            :class="
                s.clave === actual
                    ? 'border-[var(--mrl-primary)] text-[var(--mrl-primary)]'
                    : 'border-transparent text-[var(--mrl-texto-suave)] hover:text-[var(--mrl-texto)]'
            "
            :aria-current="s.clave === actual ? 'page' : undefined"
        >
            {{ s.titulo }}
        </Link>
    </nav>
</template>
