<script setup lang="ts">
import { computed } from 'vue';
import { formatearFecha, formatearPeriodo } from '@/lib/fechas';
import type { SaldoVacaciones } from '@/types/vacaciones';

/**
 * Fecha de ingreso, antigüedad, primer aniversario y vigencia del saldo de
 * vacaciones — la misma lectura en RH (expediente) y en Mi espacio. Todo
 * sale del saldo que calcula VacacionesService (nunca se recalcula aquí).
 */
const props = defineProps<{ saldo: SaldoVacaciones }>();

const cumpleAnio = computed(() => props.saldo.antiguedad_anios >= 1);
</script>

<template>
    <dl class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs text-muted-foreground">Fecha de ingreso</dt>
            <dd class="font-medium">
                <template v-if="saldo.fecha_ingreso">{{
                    formatearFecha(saldo.fecha_ingreso)
                }}</template>
                <span v-else class="text-amber-700"
                    >Pendiente de validación</span
                >
            </dd>
        </div>
        <div>
            <dt class="text-xs text-muted-foreground">Antigüedad</dt>
            <dd class="font-medium">
                {{ saldo.antiguedad_texto ?? `${saldo.antiguedad_anios} años` }}
            </dd>
        </div>
        <template v-if="saldo.fecha_ingreso && !cumpleAnio">
            <div>
                <dt class="text-xs text-muted-foreground">
                    Primer aniversario
                </dt>
                <dd class="font-medium">
                    {{ formatearFecha(saldo.primer_aniversario) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    Vacaciones disponibles desde
                </dt>
                <dd class="font-medium">
                    {{
                        formatearFecha(
                            saldo.fecha_disponible_desde ??
                                saldo.primer_aniversario,
                        )
                    }}
                </dd>
            </div>
        </template>
        <div v-else-if="cumpleAnio && saldo.vigencia_inicio">
            <dt class="text-xs text-muted-foreground">Vigencia</dt>
            <dd class="font-medium">
                {{
                    formatearPeriodo(saldo.vigencia_inicio, saldo.vigencia_fin)
                }}
            </dd>
        </div>
    </dl>
</template>
