<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CloudOff, RefreshCw } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * Reportes no está configurado o no respondió: se explica el motivo (texto
 * de App\Services\Seguimiento\ReportesApiCliente) y se ofrece reintentar.
 */
defineProps<{ mensaje: string }>();

function reintentar(): void {
    router.reload({ only: ['datos'] });
}
</script>

<template>
    <div
        class="flex entrada-suave flex-col items-center justify-center gap-3 rounded-2xl border border-border bg-card px-6 py-12 text-center"
        role="alert"
    >
        <span
            class="flex size-16 aparece-zoom items-center justify-center rounded-3xl bg-crema text-bronce"
            aria-hidden="true"
        >
            <CloudOff class="size-8" />
        </span>
        <p class="text-base font-semibold">No pudimos traer los datos de Reportes</p>
        <p class="max-w-lg text-sm text-pretty text-muted-foreground">
            {{ mensaje }}
        </p>
        <Button variant="outline" @click="reintentar">
            <RefreshCw class="size-4" /> Reintentar
        </Button>
    </div>
</template>
