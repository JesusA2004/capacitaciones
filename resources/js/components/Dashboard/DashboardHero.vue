<script setup lang="ts">
import CompletionDonutCard from '@/components/Dashboard/CompletionDonutCard.vue';
import ComplianceHeroCard from '@/components/Dashboard/ComplianceHeroCard.vue';
import OverdueAlertCard from '@/components/Dashboard/OverdueAlertCard.vue';
import type { ResumenCumplimiento } from '@/types';

/**
 * Bloque "hero" del dashboard: el primer pantallazo al entrar. Sustituye a
 * las 3 tarjetas planas originales (ResumenCumplimientoCards) por un panel
 * con fondo degradado suave de marca que agrupa el anillo de cumplimiento
 * general, el avance de asignaciones y las vencidas como una sola pieza
 * visual, no tres cards sueltas.
 */
defineProps<{
    resumen: ResumenCumplimiento;
}>();
</script>

<template>
    <div
        class="rounded-3xl border border-border/60 bg-gradient-to-br from-primary/10 via-card to-[var(--brand-secondary)]/10 p-4 shadow-sm sm:p-6"
    >
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <ComplianceHeroCard
                class="lg:col-span-5"
                :porcentaje="resumen.porcentaje_cumplimiento"
                :completadas="resumen.completadas"
                :total="resumen.total_asignaciones"
            />
            <CompletionDonutCard
                class="lg:col-span-4"
                :completadas="resumen.completadas"
                :total="resumen.total_asignaciones"
                :porcentaje="resumen.porcentaje_cumplimiento"
            />
            <OverdueAlertCard
                class="lg:col-span-3"
                :vencidas="resumen.vencidas"
            />
        </div>
    </div>
</template>
