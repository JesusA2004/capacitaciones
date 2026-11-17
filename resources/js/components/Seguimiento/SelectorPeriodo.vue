<script setup lang="ts">
import { CalendarDays, CalendarRange } from '@lucide/vue';
import { computed } from 'vue';
import { rangoSemana } from '@/lib/seguimiento';
import type { MesSeguimiento, SemanaSeguimiento } from '@/types';

/**
 * Selector de periodo de Seguimiento, fiel al modelo de Reportes: el MES
 * agrupa 4 semanas (component_period_ids). Arriba los meses (los que aún no
 * tienen radiografía se marcan), abajo «Mes completo» o una de sus semanas.
 */
const props = defineProps<{
    meses: MesSeguimiento[];
    mesId: number | null;
    semanas: SemanaSeguimiento[];
    semanaId: number | null;
    deshabilitado?: boolean;
}>();

const emit = defineEmits<{
    cambiar: [mes: number, semana: number | null];
}>();

const mesesOrdenados = computed(() => [...props.meses].reverse());

function elegirMes(mes: MesSeguimiento): void {
    if (mes.id !== props.mesId) {
        emit('cambiar', mes.id, null);
    }
}

function elegirSemana(semana: number | null): void {
    if (props.mesId !== null && semana !== props.semanaId) {
        emit('cambiar', props.mesId, semana);
    }
}
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-3 sm:p-4"
        aria-label="Periodo"
    >
        <div class="flex items-center gap-2 text-xs font-medium text-muted-foreground">
            <CalendarDays class="size-4" aria-hidden="true" />
            Mes de Reportes
        </div>
        <div class="scroll-x-limpio flex gap-2 pb-1" role="radiogroup" aria-label="Mes">
            <button
                v-for="mes in mesesOrdenados"
                :key="mes.id"
                type="button"
                role="radio"
                :aria-checked="mes.id === mesId"
                :disabled="deshabilitado"
                class="flex min-w-28 shrink-0 cursor-pointer flex-col items-start rounded-xl border px-3 py-2 text-left transicion-ui disabled:cursor-wait"
                :class="
                    mes.id === mesId
                        ? 'border-primary bg-salvia text-foreground shadow-sm'
                        : 'border-border bg-card hover:border-primary/50 hover:bg-crema/60'
                "
                @click="elegirMes(mes)"
            >
                <span class="text-sm font-semibold">{{ mes.etiqueta }}</span>
                <span
                    class="text-[11px]"
                    :class="mes.radiografia ? 'text-muted-foreground' : 'text-warning'"
                >
                    {{ mes.radiografia ? `${mes.semanas_ids.length || 4} semanas` : 'Sin radiografía' }}
                </span>
            </button>
        </div>

        <div
            v-if="semanas.length"
            class="flex flex-col gap-2 border-t border-border/70 pt-3"
        >
            <div class="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                <CalendarRange class="size-4" aria-hidden="true" />
                Semanas del mes
            </div>
            <div
                class="scroll-x-limpio flex w-fit max-w-full gap-1 rounded-xl bg-muted/60 p-1"
                role="radiogroup"
                aria-label="Semana"
            >
                <button
                    type="button"
                    role="radio"
                    :aria-checked="semanaId === null"
                    :disabled="deshabilitado"
                    class="inline-flex min-h-10 shrink-0 cursor-pointer flex-col items-start justify-center rounded-lg px-3 text-sm font-medium transicion-ui"
                    :class="
                        semanaId === null
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="elegirSemana(null)"
                >
                    Mes completo
                </button>
                <button
                    v-for="semana in semanas"
                    :key="semana.id"
                    type="button"
                    role="radio"
                    :aria-checked="semanaId === semana.id"
                    :title="semana.nombre"
                    :disabled="deshabilitado"
                    class="inline-flex min-h-10 shrink-0 cursor-pointer flex-col items-start justify-center rounded-lg px-3 text-left transicion-ui"
                    :class="
                        semanaId === semana.id
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="elegirSemana(semana.id)"
                >
                    <span class="text-sm font-medium">{{ semana.etiqueta }}</span>
                    <span class="text-[11px] text-muted-foreground">{{
                        rangoSemana(semana.inicio, semana.fin)
                    }}</span>
                </button>
            </div>
        </div>
    </section>
</template>
