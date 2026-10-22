<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Bell, BellOff, Check, CheckCheck, ExternalLink } from '@lucide/vue';
import { computed } from 'vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Button } from '@/components/ui/button';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { dashboard } from '@/routes';
import {
    abrir,
    index,
    leerTodas,
    leida,
} from '@/routes/administracion/notificaciones-sistema';

/**
 * Módulo «Notificaciones»: tus avisos con «Marcar como leída», «Marcar
 * todas como leídas» e «Ir» (abre el registro para darle seguimiento).
 * Los mismos avisos llegan a la campana web (worker de notificaciones +
 * Reverb) y a la app como push. Con «De todo el equipo» se consultan los
 * de los demás, solo lectura.
 */
type Fila = {
    id: string;
    propia: boolean;
    destinatario: string | null;
    titulo: string;
    mensaje: string;
    tipo: string | null;
    leida: boolean;
    creada_en: string | null;
};

const props = defineProps<{
    notificaciones: {
        data: Fila[];
        current_page: number;
        last_page: number;
        per_page: number;
        from?: number | null;
        to?: number | null;
        total: number;
        links?: { url: string | null; label: string; active: boolean }[];
    };
    filtros: { ver: 'mias' | 'todas'; estado: 'todas' | 'no_leidas' };
    puedeVerTodas: boolean;
    noLeidas: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Notificaciones', href: '/administracion/notificaciones' },
        ],
    },
});

const viendoMias = computed(() => props.filtros.ver === 'mias');

function filtrar(cambios: Partial<{ ver: string; estado: string }>) {
    router.get(
        index.url(),
        { ...parametrosPorPaginaActuales(), ...props.filtros, ...cambios },
        { preserveScroll: true, replace: true },
    );
}

function marcarLeida(fila: Fila) {
    router.post(leida.url(fila.id), {}, { preserveScroll: true });
}

function marcarTodas() {
    router.post(leerTodas.url(), {}, { preserveScroll: true });
}

function ir(fila: Fila) {
    router.post(abrir.url(fila.id));
}

function hace(valor: string | null): string {
    if (!valor) {
        return '';
    }

    const minutos = Math.round(
        (Date.now() - new Date(valor).getTime()) / 60000,
    );

    if (minutos < 1) {
        return 'Justo ahora';
    }

    if (minutos < 60) {
        return `Hace ${minutos} min`;
    }

    if (minutos < 60 * 24) {
        return `Hace ${Math.round(minutos / 60)} h`;
    }

    return new Date(valor).toLocaleString('es-MX', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Notificaciones" />

    <div class="pagina-ancha flex flex-col gap-5">
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="flex items-center gap-3">
                <span
                    class="flex size-11 items-center justify-center rounded-xl bg-crema text-[var(--brand-primary)]"
                >
                    <Bell class="size-5" />
                </span>
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">
                        Notificaciones
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="noLeidas > 0"
                            >Tienes
                            <strong class="text-foreground">{{
                                noLeidas
                            }}</strong>
                            sin leer.</template
                        >
                        <template v-else>Estás al día.</template>
                    </p>
                </div>
            </div>

            <Button
                v-if="noLeidas > 0"
                class="gap-2"
                data-accion="marcar-todas"
                @click="marcarTodas"
            >
                <CheckCheck class="size-4" />
                Marcar todas como leídas
            </Button>
        </header>

        <nav class="flex flex-wrap gap-2" aria-label="Filtrar notificaciones">
            <div class="inline-flex rounded-lg border bg-card p-1">
                <button
                    v-for="opcion in [
                        { valor: 'todas', texto: 'Todas' },
                        { valor: 'no_leidas', texto: 'No leídas' },
                    ]"
                    :key="opcion.valor"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="
                        filtros.estado === opcion.valor
                            ? 'bg-[var(--brand-primary)] text-white'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="filtrar({ estado: opcion.valor })"
                >
                    {{ opcion.texto }}
                </button>
            </div>

            <div
                v-if="puedeVerTodas"
                class="inline-flex rounded-lg border bg-card p-1"
            >
                <button
                    v-for="opcion in [
                        { valor: 'mias', texto: 'Mías' },
                        { valor: 'todas', texto: 'De todo el equipo' },
                    ]"
                    :key="opcion.valor"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="
                        filtros.ver === opcion.valor
                            ? 'bg-[var(--brand-primary)] text-white'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="filtrar({ ver: opcion.valor })"
                >
                    {{ opcion.texto }}
                </button>
            </div>
        </nav>

        <div
            v-if="notificaciones.data.length === 0"
            class="flex flex-col items-center gap-2 rounded-xl border border-dashed bg-card px-6 py-14 text-center"
        >
            <BellOff class="size-8 text-muted-foreground" />
            <p class="font-medium">Sin notificaciones</p>
            <p class="text-sm text-muted-foreground">
                {{
                    filtros.estado === 'no_leidas'
                        ? 'No tienes avisos pendientes de leer.'
                        : 'Aquí aparecerán los avisos de tus trámites y pendientes.'
                }}
            </p>
        </div>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="fila in notificaciones.data"
                :key="fila.id"
                class="flex flex-col gap-3 rounded-xl border p-4 transition-colors sm:flex-row sm:items-center"
                :class="
                    fila.leida
                        ? 'bg-card'
                        : 'border-[var(--brand-primary)]/30 bg-crema'
                "
                :data-leida="fila.leida"
            >
                <span
                    class="mt-1 size-2.5 shrink-0 self-start rounded-full sm:mt-0 sm:self-center"
                    :class="
                        fila.leida
                            ? 'bg-transparent'
                            : 'bg-[var(--brand-primary)]'
                    "
                    aria-hidden="true"
                />

                <div class="min-w-0 flex-1">
                    <p
                        class="text-sm text-pretty"
                        :class="fila.leida ? 'font-medium' : 'font-semibold'"
                    >
                        {{ fila.titulo }}
                    </p>
                    <p class="text-sm text-pretty text-muted-foreground">
                        {{ fila.mensaje }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ hace(fila.creada_en) }}
                        <span v-if="!viendoMias && fila.destinatario">
                            · Para {{ fila.destinatario }}</span
                        >
                    </p>
                </div>

                <div
                    v-if="fila.propia"
                    class="flex shrink-0 flex-wrap gap-2 sm:justify-end"
                >
                    <Button
                        v-if="!fila.leida"
                        variant="outline"
                        size="sm"
                        class="gap-1.5"
                        @click="marcarLeida(fila)"
                    >
                        <Check class="size-4" />
                        Marcar como leída
                    </Button>
                    <Button size="sm" class="gap-1.5" @click="ir(fila)">
                        <ExternalLink class="size-4" />
                        Ir
                    </Button>
                </div>
            </li>
        </ul>

        <PaginacionTabla :datos="notificaciones" />
    </div>
</template>
