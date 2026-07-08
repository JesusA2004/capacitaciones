<script setup lang="ts">
import { AlertTriangle, ArrowRight, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

/**
 * "Asignaciones vencidas": card de alerta suave (no agresiva) con CTA que
 * lleva directo a la lista de "Usuarios con pendientes críticos" más abajo
 * en la misma página, en vez de solo mostrar un número rojo.
 */
const props = defineProps<{
    vencidas: number;
}>();

const hayVencidas = computed(() => props.vencidas > 0);
</script>

<template>
    <div
        class="flex flex-col justify-between gap-4 rounded-2xl border p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg"
        :class="
            hayVencidas
                ? 'border-destructive/20 bg-destructive/5 hover:border-destructive/40'
                : 'border-border/60 bg-card hover:border-primary/40'
        "
    >
        <div class="flex items-center justify-between gap-3">
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                :class="
                    hayVencidas
                        ? 'bg-destructive/10 text-destructive'
                        : 'bg-success/10 text-success'
                "
            >
                <AlertTriangle v-if="hayVencidas" class="size-4.5" />
                <ShieldCheck v-else class="size-4.5" />
            </span>
            <Badge :variant="hayVencidas ? 'destructive' : 'success'">
                {{ hayVencidas ? 'Atención requerida' : 'Todo al día' }}
            </Badge>
        </div>

        <div>
            <p
                class="text-2xl font-semibold tabular-nums"
                :class="hayVencidas ? 'text-destructive' : ''"
            >
                {{ vencidas }} vencida{{ vencidas === 1 ? '' : 's' }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{
                    hayVencidas
                        ? 'Requieren seguimiento cercano.'
                        : 'Ningún colaborador tiene asignaciones vencidas.'
                }}
            </p>
        </div>

        <a
            v-if="hayVencidas"
            href="#atencion-requerida"
            class="group inline-flex items-center gap-1 text-xs font-medium text-destructive transition-colors hover:text-destructive/80"
        >
            Ver pendientes críticos
            <ArrowRight
                class="size-3.5 transition-transform duration-200 group-hover:translate-x-0.5"
            />
        </a>
    </div>
</template>
