<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Bell, Check, CheckCheck } from '@lucide/vue';
import { ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { postJson } from '@/lib/http';
import { colorClaseNotificacion } from '@/lib/notificacionColor';
import { dashboard } from '@/routes';
import {
    index as indexPortal,
    notificaciones as rutaNotificaciones,
} from '@/routes/portal';
import type { NotificacionPortalItem } from '@/types';

const props = defineProps<{
    notificaciones: NotificacionPortalItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi portal', href: indexPortal() },
            { title: 'Mis notificaciones', href: rutaNotificaciones.url() },
        ],
    },
});

// Copia local editable: marcar como leída actualiza aquí sin recargar la
// página (fetch directo al endpoint JSON de la campana, nunca con <Link> —
// esa es la ruta que causaba "All Inertia requests must receive a valid
// Inertia response" al navegar ahí en vez de solo hacer fetch).
const lista = ref<NotificacionPortalItem[]>([...props.notificaciones]);
const marcandoTodas = ref(false);

async function marcarLeida(notificacion: NotificacionPortalItem) {
    if (notificacion.leida) {
        return;
    }

    notificacion.leida = true;
    await postJson(`/notificaciones/${notificacion.id}/leida`, {});
}

async function marcarTodas() {
    marcandoTodas.value = true;

    try {
        await postJson('/notificaciones/leer-todas', {});
        lista.value = lista.value.map((n) => ({ ...n, leida: true }));
    } finally {
        marcandoTodas.value = false;
    }
}
</script>

<template>
    <Head title="Mis notificaciones" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Mis notificaciones"
            descripcion="Avisos de tus solicitudes, documentos y más."
            :icono="Bell"
        >
            <Button
                v-if="lista.some((n) => !n.leida)"
                variant="outline"
                size="sm"
                :disabled="marcandoTodas"
                @click="marcarTodas"
            >
                <CheckCheck class="size-4" />
                Marcar todas como leídas
            </Button>
        </CrudPageHeader>

        <div
            v-if="!lista.length"
            class="rounded-2xl border border-dashed border-border/60 p-10 text-center text-sm text-muted-foreground"
        >
            No tienes notificaciones todavía.
        </div>

        <div v-else class="flex flex-col gap-2">
            <button
                v-for="notificacion in lista"
                :key="notificacion.id"
                type="button"
                class="flex items-start gap-3 rounded-2xl border border-border/60 bg-card p-4 text-left shadow-sm transition-colors hover:bg-muted/40"
                :class="!notificacion.leida && 'border-[var(--brand-primary)]/30 bg-[var(--brand-primary)]/[0.03]'"
                @click="marcarLeida(notificacion)"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-full text-lg"
                    :class="colorClaseNotificacion(notificacion.color)"
                >
                    {{ notificacion.emoji }}
                </span>
                <span
                    class="mt-1.5 size-2 shrink-0 rounded-full"
                    :class="!notificacion.leida ? 'bg-[var(--brand-primary)]' : 'bg-transparent'"
                />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ notificacion.titulo }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ notificacion.mensaje }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ notificacion.creada_en }}
                    </p>
                </div>
                <Check v-if="notificacion.leida" class="size-4 shrink-0 text-muted-foreground" />
            </button>
        </div>
    </div>
</template>
