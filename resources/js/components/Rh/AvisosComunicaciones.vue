<script setup lang="ts">
import { BellRing, CheckCheck, Globe2, MailOpen, UserRound } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';

/**
 * «Avisos / Comunicaciones» del expediente: todos los avisos que le
 * aplican a la persona (generales y personales), si ya los leyó, cuándo se
 * enviaron, quién los envió y su imagen.
 */
export type AvisoComunicacion = {
    id: number;
    titulo: string;
    mensaje: string;
    alcance: 'todos' | 'colaborador' | string;
    creado_por: { name: string; apellidos: string | null } | null;
    enviado_en: string | null;
    leido?: boolean;
    leido_en?: string | null;
    imagen_url: string | null;
};

defineProps<{ avisos: AvisoComunicacion[] }>();

const fecha = (valor: string | null) =>
    valor ? new Date(valor).toLocaleString('es-MX', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
</script>

<template>
    <EmptyState
        v-if="avisos.length === 0"
        :icono="BellRing"
        titulo="Sin avisos todavía"
        descripcion="Aquí aparecen los avisos generales y los que RH le envíe a esta persona."
    />

    <ul v-else class="grid gap-3 lg:grid-cols-2">
        <li
            v-for="aviso in avisos"
            :key="aviso.id"
            class="flex gap-4 overflow-hidden rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
        >
            <img
                v-if="aviso.imagen_url"
                :src="aviso.imagen_url"
                alt=""
                loading="lazy"
                class="size-20 shrink-0 rounded-xl object-cover"
            />
            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :class="aviso.alcance === 'todos' ? 'bg-primary/10 text-primary' : 'bg-warning-soft text-warning'"
                    >
                        <component :is="aviso.alcance === 'todos' ? Globe2 : UserRound" class="size-3" />
                        {{ aviso.alcance === 'todos' ? 'General' : 'Personal' }}
                    </span>
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :class="aviso.leido ? 'bg-success-soft/60 text-success' : 'bg-muted text-muted-foreground'"
                    >
                        <component :is="aviso.leido ? CheckCheck : MailOpen" class="size-3" />
                        {{ aviso.leido ? (aviso.leido_en ? `Leído el ${fecha(aviso.leido_en)}` : 'Leído') : 'Sin leer' }}
                    </span>
                </div>
                <p class="truncate font-semibold">{{ aviso.titulo }}</p>
                <p class="line-clamp-3 text-sm text-muted-foreground">{{ aviso.mensaje }}</p>
                <p class="mt-auto text-xs text-muted-foreground">
                    Enviado {{ fecha(aviso.enviado_en) }}
                    <template v-if="aviso.creado_por"> por {{ aviso.creado_por.name }} {{ aviso.creado_por.apellidos ?? '' }}</template>
                </p>
            </div>
        </li>
    </ul>
</template>
