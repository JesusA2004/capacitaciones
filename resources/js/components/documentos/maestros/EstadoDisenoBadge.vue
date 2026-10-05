<script setup lang="ts">
import { AlertTriangle, ShieldAlert, ShieldCheck, XCircle } from '@lucide/vue';
import type { EstadoDisenoMaster } from '@/types';

/**
 * ✓ Diseño validado / ⚠ Falta validar diseño / ✗ Diseño no validado /
 * activada por excepción. Nada para documentos de referencia.
 */
defineProps<{ diseno: EstadoDisenoMaster; compacto?: boolean }>();
</script>

<template>
    <span
        v-if="diseno !== 'no_aplica'"
        class="inline-flex items-center gap-1 text-xs font-medium"
        :class="{
            'text-[var(--mrl-verde)]': diseno === 'validado',
            'text-amber-700 dark:text-amber-300':
                diseno === 'sin_validar' || diseno === 'excepcion',
            'text-destructive': diseno === 'fallido',
        }"
    >
        <ShieldCheck v-if="diseno === 'validado'" class="size-3.5" />
        <AlertTriangle v-else-if="diseno === 'sin_validar'" class="size-3.5" />
        <ShieldAlert v-else-if="diseno === 'excepcion'" class="size-3.5" />
        <XCircle v-else class="size-3.5" />
        <template v-if="!compacto">
            {{
                diseno === 'validado'
                    ? 'Diseño validado'
                    : diseno === 'sin_validar'
                      ? 'Falta validar diseño'
                      : diseno === 'excepcion'
                        ? 'Activado por excepción'
                        : 'Diseño no validado'
            }}
        </template>
    </span>
</template>
