<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BadgeCheck,
    CalendarClock,
    ClipboardCheck,
    FileSignature,
    Hourglass,
    RotateCcw,
} from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/evaluaciones';
import type { RespuestaPaginada } from '@/types';

/**
 * Evaluación de capacitación inicial: se abre 15 días antes de que venza
 * el contrato de capacitación; el gerente la captura, RH la autoriza y, si
 * acredita, se genera el contrato indeterminado (una sola vez).
 */
type Evaluacion = {
    id: number;
    colaborador: { id: number; nombre: string; numero_empleado: string | null };
    puesto: string | null;
    sucursal: string | null;
    estado: 'pendiente' | 'capturada' | 'devuelta' | 'autorizada';
    estado_etiqueta: string;
    fecha_limite: string | null;
    dias_restantes: number | null;
    calificacion: string | number | null;
    resultado: string | null;
    decision_renovar: boolean | null;
    contrato_renovacion_id: number | null;
    url: string;
};

const props = defineProps<{
    evaluaciones: RespuestaPaginada<Evaluacion>;
    estados: { value: string; label: string }[];
    filtros: { estado: string | null };
    puedeAutorizar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Evaluación de capacitación inicial', href: index() },
        ],
    },
});

const ESTILO: Record<Evaluacion['estado'], { icono: typeof Hourglass; clase: string; accion: string }> = {
    pendiente: { icono: Hourglass, clase: 'bg-[#e9d6b0]/50 text-[#754711] dark:text-[#e9d6b0]', accion: 'Capturar evaluación' },
    devuelta: { icono: RotateCcw, clase: 'bg-destructive/10 text-destructive', accion: 'Corregir captura' },
    capturada: { icono: ClipboardCheck, clase: 'bg-primary/10 text-primary', accion: 'Revisar y autorizar' },
    autorizada: { icono: BadgeCheck, clase: 'bg-[#2f5937]/10 text-[#2f5937] dark:text-[#a9d6b1]', accion: 'Ver resultado y contrato' },
};

const conteo = computed(() => ({
    total: props.evaluaciones.total,
    urgentes: props.evaluaciones.data.filter((e) => e.estado !== 'autorizada' && (e.dias_restantes ?? 99) <= 5).length,
}));

function filtrar(estado: string | null) {
    router.get(index.url(), estado ? { estado } : {}, { preserveScroll: true });
}

const fecha = (valor: string | null) =>
    valor
        ? new Date(`${valor}T12:00:00`).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' })
        : '—';
</script>

<template>
    <Head title="Evaluación de capacitación inicial" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Evaluación de capacitación inicial"
            descripcion="Se abre 15 días antes de que termine la capacitación. Si acredita, se prepara el contrato indeterminado."
            :icono="CalendarClock"
        />

        <div class="scroll-x-limpio flex gap-2">
            <button
                type="button"
                class="shrink-0 rounded-full border px-4 py-1.5 text-sm transition"
                :class="!filtros.estado ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card hover:bg-muted'"
                @click="filtrar(null)"
            >
                Todas · {{ conteo.total }}
            </button>
            <button
                v-for="estado in estados"
                :key="estado.value"
                type="button"
                class="shrink-0 rounded-full border px-4 py-1.5 text-sm transition"
                :class="filtros.estado === estado.value ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card hover:bg-muted'"
                @click="filtrar(estado.value)"
            >
                {{ estado.label }}
            </button>
        </div>

        <p v-if="conteo.urgentes" class="rounded-2xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm">
            <strong>{{ conteo.urgentes }}</strong> evaluación(es) vencen en 5 días o menos.
        </p>

        <EmptyState
            v-if="evaluaciones.data.length === 0"
            :icono="CalendarClock"
            titulo="Sin evaluaciones por ahora"
            descripcion="Aparecen solas 15 días antes de que termine el contrato de capacitación de cada colaborador."
        />

        <div v-else class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
            <Link
                v-for="evaluacion in evaluaciones.data"
                :key="evaluacion.id"
                :href="evaluacion.url"
                class="group flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ evaluacion.colaborador.nombre }}</p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ evaluacion.puesto ?? 'Sin puesto' }} · {{ evaluacion.sucursal ?? 'Sin sucursal' }}
                        </p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium" :class="ESTILO[evaluacion.estado].clase">
                        <component :is="ESTILO[evaluacion.estado].icono" class="size-3.5" />
                        {{ evaluacion.estado_etiqueta }}
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-xl bg-muted/60 p-2">
                        <p class="text-sm font-semibold">{{ fecha(evaluacion.fecha_limite) }}</p>
                        <p class="text-[11px] text-muted-foreground">Fecha límite</p>
                    </div>
                    <div class="rounded-xl bg-muted/60 p-2">
                        <p
                            class="text-lg font-bold tabular-nums"
                            :class="evaluacion.estado !== 'autorizada' && (evaluacion.dias_restantes ?? 99) <= 5 ? 'text-destructive' : ''"
                        >
                            {{ evaluacion.dias_restantes ?? '—' }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">Días</p>
                    </div>
                    <div class="rounded-xl bg-muted/60 p-2">
                        <p class="text-lg font-bold tabular-nums">{{ evaluacion.calificacion ?? '—' }}</p>
                        <p class="text-[11px] text-muted-foreground">Calificación</p>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-border/60 pt-3 text-sm">
                    <span
                        v-if="evaluacion.contrato_renovacion_id"
                        class="inline-flex items-center gap-1.5 text-[#2f5937] dark:text-[#a9d6b1]"
                    >
                        <FileSignature class="size-4" /> Contrato indeterminado listo
                    </span>
                    <span v-else-if="evaluacion.decision_renovar === false" class="text-muted-foreground">No se renueva</span>
                    <span v-else class="text-muted-foreground">&nbsp;</span>
                    <span class="font-medium text-primary group-hover:underline">{{ ESTILO[evaluacion.estado].accion }} →</span>
                </div>
            </Link>
        </div>
    </div>
</template>
