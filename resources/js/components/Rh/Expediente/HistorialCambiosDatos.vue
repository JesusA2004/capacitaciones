<script setup lang="ts">
import { ArrowRight, History, Lock, MessageSquareText, ShieldAlert } from '@lucide/vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { formatearFechaHora } from '@/lib/fechas';
import type { HistorialCambioExpediente } from '@/types';

/**
 * «Historial de cambios» de los datos de la persona: quién, cuándo, desde
 * qué módulo, por qué y el antes → después de cada dato
 * (HistorialCambiosDatosService). Los datos médicos solo se ven con su
 * permiso; sin él salen como «Confidencial».
 */
defineProps<{ historial: HistorialCambioExpediente[] }>();
</script>

<template>
    <div
        v-if="historial.length === 0"
        class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-border/70 bg-card px-4 py-10 text-center"
    >
        <History class="size-8 text-muted-foreground/60" />
        <p class="text-sm font-medium">Sin cambios registrados</p>
        <p class="max-w-sm text-xs text-muted-foreground">
            Cuando RH edite un dato o aplique una corrección, aquí verás el
            antes y el después.
        </p>
    </div>
    <ol v-else class="relative flex flex-col gap-3" data-historial-cambios>
        <li
            v-for="registro in historial"
            :key="registro.id"
            class="rounded-2xl border border-border/60 bg-card p-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <ChipEstado :etiqueta="registro.modulo" tono="primario" />
                    <span class="text-sm font-semibold">
                        {{ registro.por ?? 'Sistema' }}
                    </span>
                    <span
                        v-if="registro.solicitud"
                        class="text-xs text-muted-foreground"
                        >· solicitud {{ registro.solicitud }}</span
                    >
                </div>
                <time class="text-xs text-muted-foreground tabular-nums">
                    {{ registro.fecha ? formatearFechaHora(registro.fecha) : '—' }}
                </time>
            </div>
            <p
                v-if="registro.motivo"
                class="mt-2 flex items-start gap-1.5 rounded-xl bg-crema px-3 py-2 text-xs"
            >
                <MessageSquareText class="mt-0.5 size-3.5 shrink-0 text-bronce" />
                <span><span class="font-semibold">Motivo:</span> {{ registro.motivo }}</span>
            </p>
            <ul class="mt-3 flex flex-col gap-2">
                <li
                    v-for="cambio in registro.cambios"
                    :key="cambio.campo"
                    class="grid grid-cols-1 gap-1 rounded-xl border border-border/40 px-3 py-2 sm:grid-cols-[minmax(9rem,14rem)_1fr]"
                >
                    <span class="flex items-center gap-1.5 text-xs font-medium">
                        <ShieldAlert
                            v-if="cambio.critico"
                            class="size-3.5 text-warning"
                            aria-label="Dato crítico"
                        />
                        <Lock
                            v-else-if="cambio.confidencial"
                            class="size-3.5 text-muted-foreground"
                            aria-label="Confidencial"
                        />
                        {{ cambio.etiqueta }}
                    </span>
                    <span
                        class="flex min-w-0 flex-wrap items-center gap-1.5 text-sm"
                    >
                        <span
                            class="rounded-md bg-danger-soft px-1.5 text-destructive line-through decoration-1 break-all"
                            >{{ cambio.antes ?? 'vacío' }}</span
                        >
                        <ArrowRight class="size-3.5 shrink-0 text-muted-foreground" />
                        <span
                            class="rounded-md bg-success-soft px-1.5 font-medium text-success break-all"
                            >{{ cambio.despues ?? 'vacío' }}</span
                        >
                    </span>
                </li>
            </ul>
        </li>
    </ol>
</template>
