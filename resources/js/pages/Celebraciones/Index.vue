<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Cake, MessageCircleHeart, PartyPopper } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';

/**
 * Muro de felicitaciones en web: las mismas celebraciones abiertas que ve
 * la app (cumpleaños y aniversarios de los últimos días). Cada tarjeta
 * lleva a la pantalla de la celebración, donde se deja el mensaje.
 */
type CelebracionMuro = {
    id: number;
    tipo: 'cumpleanos' | 'aniversario_laboral';
    tipo_etiqueta: string;
    fecha: string;
    es_hoy: boolean;
    titulo: string;
    homenajeado: {
        nombre: string;
        puesto: string | null;
        sucursal: string | null;
        foto_url: string | null;
    };
    recibe_mensajes: boolean;
    es_mia: boolean;
    puede_escribir: boolean;
    mi_mensaje: { id: number } | null;
    mensajes_count: number | null;
    url: string;
};

defineProps<{ celebraciones: CelebracionMuro[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Muro de felicitaciones', href: '' },
        ],
    },
});

const { getInitials } = useInitials();

function fechaLarga(fecha: string): string {
    return new Date(`${fecha}T12:00:00`).toLocaleDateString('es-MX', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

function estado(c: CelebracionMuro): string {
    if (c.es_mia) {
        return c.mensajes_count
            ? `${c.mensajes_count} mensaje(s) para ti`
            : 'Tu celebración';
    }

    if (c.mi_mensaje) {
        return 'Ya dejaste tu mensaje';
    }

    return c.recibe_mensajes && c.puede_escribir
        ? 'Déjale un mensaje'
        : 'Muro cerrado';
}
</script>

<template>
    <Head title="Muro de felicitaciones" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Muro de felicitaciones"
            descripcion="Cumpleaños y aniversarios de tus compañeros. Tu mensaje es privado: solo lo ve el homenajeado."
            :icono="PartyPopper"
        />

        <EmptyState
            v-if="celebraciones.length === 0"
            :icono="Cake"
            titulo="No hay celebraciones abiertas"
            descripcion="Cuando RH abra el muro de un cumpleaños o aniversario, aparecerá aquí para que dejes tu felicitación."
            class="rounded-2xl border border-dashed border-border/60 bg-card"
        />

        <div
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
        >
            <Link
                v-for="c in celebraciones"
                :key="c.id"
                :href="c.url"
                class="group flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 transition hover:border-primary/50 hover:shadow-md"
            >
                <div class="flex items-center justify-between gap-2">
                    <span
                        class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary"
                        >{{ c.tipo_etiqueta }}</span
                    >
                    <span
                        class="text-xs text-muted-foreground"
                        :class="{ 'font-semibold text-primary': c.es_hoy }"
                        >{{ c.es_hoy ? 'Hoy' : fechaLarga(c.fecha) }}</span
                    >
                </div>

                <div class="flex items-center gap-3">
                    <Avatar class="size-14">
                        <AvatarImage
                            v-if="c.homenajeado.foto_url"
                            :src="c.homenajeado.foto_url"
                            :alt="c.homenajeado.nombre"
                        />
                        <AvatarFallback class="text-base font-semibold">{{
                            getInitials(c.homenajeado.nombre)
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0">
                        <p class="truncate font-semibold">
                            {{ c.homenajeado.nombre }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{
                                [c.homenajeado.puesto, c.homenajeado.sucursal]
                                    .filter(Boolean)
                                    .join(' · ') || '—'
                            }}
                        </p>
                    </div>
                </div>

                <p class="text-sm">{{ c.titulo }}</p>

                <div
                    class="mt-auto flex items-center gap-2 text-sm font-medium text-primary group-hover:underline"
                >
                    <MessageCircleHeart class="size-4" />
                    {{ estado(c) }}
                </div>
            </Link>
        </div>
    </div>
</template>
