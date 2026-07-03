<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, XCircle } from '@lucide/vue';

defineProps<{
    folio: string;
    valido: boolean;
    certificado: { nombre: string; curso: string; emitido_en: string } | null;
}>();
</script>

<template>
    <Head title="Verificar constancia" />

    <div class="flex min-h-screen items-center justify-center bg-muted/30 p-4">
        <div
            class="w-full max-w-md rounded-xl border bg-background p-8 text-center shadow-sm"
        >
            <h1 class="mb-1 text-lg font-semibold">
                Mr. Lana — Portal de Capacitación
            </h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Verificación de constancia
            </p>

            <template v-if="valido && certificado">
                <CheckCircle2
                    class="mx-auto mb-3 size-12 text-[var(--success)]"
                />
                <p class="text-sm font-medium">Constancia válida</p>
                <div class="mt-4 space-y-1 text-sm">
                    <p>
                        <strong>{{ certificado.nombre }}</strong>
                    </p>
                    <p>Curso: {{ certificado.curso }}</p>
                    <p>Fecha: {{ certificado.emitido_en }}</p>
                    <p class="text-xs text-muted-foreground">
                        Folio: {{ folio }}
                    </p>
                </div>
            </template>
            <template v-else>
                <XCircle class="mx-auto mb-3 size-12 text-destructive" />
                <p class="text-sm font-medium">
                    No se encontró ninguna constancia con el folio «{{
                        folio
                    }}».
                </p>
            </template>
        </div>
    </div>
</template>
