<script setup lang="ts">
import { CakeSlice, Info, Sparkles } from '@lucide/vue';
import DashboardSection from '@/components/Dashboard/DashboardSection.vue';
import RotacionPersonal from '@/components/Dashboard/RotacionPersonal.vue';
import type { DashboardRhProps } from '@/types';

// rotacion/sucursalesFiltro/departamentosFiltro son opcionales: este
// componente también lo usa Reportes/Index.vue (hub unificado), que hoy no
// trae esos datos. Cuando falten, la sección de rotación simplemente no se
// muestra ahí.
defineProps<
    Omit<DashboardRhProps, 'rotacion' | 'sucursalesFiltro' | 'departamentosFiltro'> &
        Partial<Pick<DashboardRhProps, 'rotacion' | 'sucursalesFiltro' | 'departamentosFiltro'>>
>();

const TONO_ALERTA: Record<string, string> = {
    warning: 'border-warning/30 bg-warning/10 text-warning',
    danger: 'border-destructive/30 bg-destructive/10 text-destructive',
    info: 'border-[var(--brand-secondary)]/30 bg-[var(--brand-secondary)]/10 text-[var(--brand-secondary)]',
};
</script>

<template>
    <div class="flex flex-col gap-8">
        <DashboardSection
            v-if="rotacion"
            titulo="Rotación de personal"
            descripcion="Altas, bajas, plantilla y cumplimiento en tiempo real — filtra por sucursal, departamento y periodo."
            :columnas="1"
        >
            <RotacionPersonal
                :datos-iniciales="rotacion"
                :sucursales="sucursalesFiltro ?? []"
                :departamentos="departamentosFiltro ?? []"
            />
        </DashboardSection>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-border/60 bg-card p-5 lg:col-span-2">
                <div class="mb-3 flex items-center gap-2">
                    <CakeSlice class="size-4 text-[var(--brand-primary)]" />
                    <h3 class="text-sm font-semibold">
                        Próximos aniversarios laborales
                    </h3>
                </div>

                <p
                    v-if="!proximosAniversarios.length"
                    class="rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
                >
                    Sin aniversarios en los próximos 30 días.
                </p>

                <div v-else class="flex flex-col divide-y divide-border/60">
                    <div
                        v-for="item in proximosAniversarios"
                        :key="item.id"
                        class="flex items-center justify-between gap-3 rounded-xl px-2 py-3 transition-colors hover:bg-muted/40"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[var(--brand-primary)]/10 text-sm font-semibold text-[var(--brand-primary)]"
                            >
                                {{ item.anios }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ item.nombre }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ item.anios }}
                                    {{ item.anios === 1 ? 'año' : 'años' }} en
                                    la empresa
                                </p>
                            </div>
                        </div>
                        <span
                            class="shrink-0 rounded-full px-3 py-1 text-xs font-medium"
                            :class="
                                item.dias <= 7
                                    ? 'bg-success/10 text-success'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ item.dias === 0 ? 'Hoy' : `en ${item.dias}d` }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-border/60 bg-card p-5">
                <div class="mb-3 flex items-center gap-2">
                    <Info class="size-4 text-[var(--brand-secondary)]" />
                    <h3 class="text-sm font-semibold">Alertas RH</h3>
                </div>
                <p
                    v-if="!alertas.length"
                    class="flex items-center gap-2 rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
                >
                    <Sparkles class="size-4 shrink-0" />
                    Sin alertas por ahora, todo en orden.
                </p>
                <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="(alerta, indice) in alertas"
                        :key="indice"
                        class="rounded-xl border px-3 py-2 text-xs transition-colors"
                        :class="TONO_ALERTA[alerta.tono]"
                    >
                        {{ alerta.mensaje }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
