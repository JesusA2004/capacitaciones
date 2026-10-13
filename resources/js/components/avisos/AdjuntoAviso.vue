<script setup lang="ts">
import { ExternalLink, FileText } from '@lucide/vue';
import { computed } from 'vue';
import type { AdjuntoAviso } from '@/types';

/**
 * Adjunto de un aviso: una imagen se ve como imagen (miniatura en listas,
 * completa en el detalle); un PDF, como tarjeta con nombre, tamaño y
 * «Ver PDF» (se abre inline por el endpoint autorizado, nunca una URL
 * pública del NAS).
 */
const props = withDefaults(
    defineProps<{
        archivo: AdjuntoAviso;
        url: string;
        variante?: 'miniatura' | 'completo';
    }>(),
    { variante: 'completo' },
);

const tamano = computed(() => {
    const bytes = props.archivo.tamano;

    if (!bytes) {
        return null;
    }

    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
});
</script>

<template>
    <a
        v-if="archivo.tipo === 'pdf'"
        :href="url"
        target="_blank"
        rel="noopener"
        class="flex w-full max-w-sm tarjeta-interactiva items-center gap-3 rounded-xl border bg-crema/60 p-3 text-left"
        :aria-label="`Ver PDF ${archivo.nombre ?? ''}`"
    >
        <span
            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-danger-soft text-danger"
        >
            <FileText class="size-5" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium">{{
                archivo.nombre ?? 'Documento.pdf'
            }}</span>
            <span class="block text-xs text-muted-foreground"
                >PDF<template v-if="tamano"> · {{ tamano }}</template></span
            >
        </span>
        <span
            class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary"
        >
            Ver PDF <ExternalLink class="size-3.5" />
        </span>
    </a>
    <img
        v-else-if="variante === 'miniatura'"
        :src="url"
        :alt="archivo.nombre ?? ''"
        loading="lazy"
        width="80"
        height="80"
        class="size-20 shrink-0 rounded-xl object-cover"
    />
    <a v-else :href="url" target="_blank" rel="noopener" class="block">
        <img
            :src="url"
            :alt="archivo.nombre ?? ''"
            loading="lazy"
            class="max-h-80 w-full rounded-xl object-cover"
        />
    </a>
</template>
