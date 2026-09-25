<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PartyPopper } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import { getJson } from '@/lib/http';
import { hoy as celebracionesHoy } from '@/routes/celebraciones';

/**
 * Aviso discreto en el inicio cuando hoy hay celebraciones visibles para
 * el usuario (docs/CELEBRACIONES.md). Uno: tarjeta con "Ver y felicitar";
 * varios: "Hoy celebramos a N personas" con lista compacta. Si no hay
 * nada, no ocupa espacio.
 */
type Item = {
    id: number;
    titulo: string;
    url: string;
    es_mia: boolean;
    homenajeado: { nombre: string; foto_url: string | null };
};

const items = ref<Item[]>([]);
const { getInitials } = useInitials();

onMounted(async () => {
    try {
        items.value = (await getJson<{ data: Item[] }>(celebracionesHoy.url())).data;
    } catch {
        items.value = [];
    }
});
</script>

<template>
    <section
        v-if="items.length > 0"
        class="flex flex-col gap-3 rounded-2xl border border-pink-400/30 bg-gradient-to-br from-pink-400/10 via-amber-300/10 to-emerald-400/10 p-4"
    >
        <p class="flex items-center gap-2 font-semibold">
            <PartyPopper class="size-5 text-pink-500" />
            {{ items.length === 1 ? 'Hoy celebramos' : `Hoy celebramos a ${items.length} personas` }}
        </p>
        <ul class="flex flex-col gap-2">
            <li v-for="item in items.slice(0, 5)" :key="item.id" class="flex flex-wrap items-center gap-3">
                <Avatar class="size-9 shrink-0">
                    <AvatarImage v-if="item.homenajeado.foto_url" :src="item.homenajeado.foto_url" :alt="item.homenajeado.nombre" />
                    <AvatarFallback>{{ getInitials(item.homenajeado.nombre) }}</AvatarFallback>
                </Avatar>
                <span class="min-w-0 flex-1 text-sm">{{ item.es_mia ? '¡Hoy es tu día! Mira tu tarjeta y tus mensajes.' : item.titulo }}</span>
                <Button as-child size="sm" variant="outline">
                    <Link :href="item.url">{{ item.es_mia ? 'Ver' : 'Ver y felicitar' }}</Link>
                </Button>
            </li>
        </ul>
    </section>
</template>
