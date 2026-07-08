<script setup lang="ts">
/**
 * Tira compacta de "N etiqueta" con un punto de color, para mostrar junto
 * al título de un CRUD sin ocupar el espacio de CrudStats (que son tarjetas
 * completas). Pensada para 2-4 cifras cortas, p. ej. "12 activos · 3
 * inactivos".
 */
defineProps<{
    items: {
        etiqueta: string;
        valor: number;
        tono?: 'success' | 'warning' | 'destructive' | 'info' | 'secondary';
    }[];
}>();

const PUNTO: Record<string, string> = {
    success: 'bg-success',
    warning: 'bg-warning',
    destructive: 'bg-destructive',
    info: 'bg-[var(--brand-secondary)]',
    secondary: 'bg-muted-foreground/40',
};
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-muted-foreground"
    >
        <span
            v-for="(item, indice) in items"
            :key="indice"
            class="inline-flex items-center gap-1.5"
        >
            <span
                class="size-2 rounded-full"
                :class="PUNTO[item.tono ?? 'secondary']"
            />
            <strong class="font-medium text-foreground tabular-nums">{{
                item.valor
            }}</strong>
            {{ item.etiqueta }}
        </span>
    </div>
</template>
