<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    addMonths,
    eachDayOfInterval,
    endOfMonth,
    endOfWeek,
    format,
    isSameDay,
    isSameMonth,
    isToday,
    parseISO,
    startOfMonth,
    startOfWeek,
    subMonths,
} from 'date-fns';
import { es } from 'date-fns/locale';
import { computed } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { calendario as indexCalendario, dashboard } from '@/routes';
import type { EventoCalendarioItem } from '@/types';

const props = defineProps<{
    anio: number;
    mes: number;
    eventos: EventoCalendarioItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Calendario', href: indexCalendario.url() },
        ],
    },
});

const mesActual = computed(() => new Date(props.anio, props.mes - 1, 1));

const diasDelMes = computed(() => {
    const inicio = startOfWeek(startOfMonth(mesActual.value), {
        weekStartsOn: 1,
    });
    const fin = endOfWeek(endOfMonth(mesActual.value), { weekStartsOn: 1 });

    return eachDayOfInterval({ start: inicio, end: fin });
});

const nombreMes = computed(() =>
    format(mesActual.value, 'MMMM yyyy', { locale: es }),
);

const diasSemana = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

function eventosDelDia(dia: Date): EventoCalendarioItem[] {
    return props.eventos.filter((evento) =>
        isSameDay(parseISO(evento.fecha), dia),
    );
}

function irAMes(fecha: Date) {
    router.get(indexCalendario.url(), {
        anio: fecha.getFullYear(),
        mes: fecha.getMonth() + 1,
    });
}

function mesAnterior() {
    irAMes(subMonths(mesActual.value, 1));
}

function mesSiguiente() {
    irAMes(addMonths(mesActual.value, 1));
}

function abrirEvento(evento: EventoCalendarioItem) {
    router.visit(evento.url);
}
</script>

<template>
    <Head title="Calendario" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Calendario"
            descripcion="Fechas límite y sesiones en vivo."
            :icono="CalendarDays"
        >
            <Button variant="outline" @click="irAMes(new Date())">Hoy</Button>
            <div class="flex items-center gap-1">
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Mes anterior"
                    @click="mesAnterior"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" />
                </Button>
                <span
                    class="w-36 text-center text-sm font-semibold capitalize"
                    aria-live="polite"
                >
                    {{ nombreMes }}
                </span>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Mes siguiente"
                    @click="mesSiguiente"
                >
                    <ChevronRight class="size-4" aria-hidden="true" />
                </Button>
            </div>
        </CrudPageHeader>

        <div
            class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-full bg-primary" /> Sesión en vivo
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-full bg-oro" /> Fecha límite
            </span>
            <span class="ml-auto tabular-nums"
                >{{ eventos.length }} evento(s) este mes</span
            >
        </div>

        <div
            :key="nombreMes"
            class="grid entrada-suave grid-cols-7 gap-px overflow-hidden rounded-2xl border border-border/60 bg-border/60 text-xs shadow-sm"
        >
            <div
                v-for="dia in diasSemana"
                :key="dia"
                class="bg-crema px-1 py-2 text-center font-semibold text-bronce sm:px-2"
            >
                {{ dia }}
            </div>

            <div
                v-for="dia in diasDelMes"
                :key="dia.toISOString()"
                class="min-h-16 min-w-0 bg-card p-1 transicion-ui sm:min-h-28 sm:p-2"
                :class="{
                    'bg-crema/20 opacity-50': !isSameMonth(dia, mesActual),
                    'bg-salvia/30': isToday(dia),
                }"
            >
                <span
                    class="inline-flex size-6 items-center justify-center rounded-full text-xs tabular-nums"
                    :class="
                        isToday(dia)
                            ? 'bg-primary font-semibold text-primary-foreground'
                            : 'text-muted-foreground'
                    "
                >
                    {{ format(dia, 'd') }}
                </span>

                <div class="mt-1 flex flex-col gap-1">
                    <button
                        v-for="evento in eventosDelDia(dia)"
                        :key="evento.id"
                        type="button"
                        class="block w-full presionable truncate rounded-md border-l-2 px-1.5 py-1 text-left text-[11px] font-medium transicion-ui focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="
                            evento.tipo === 'sesion'
                                ? 'border-primary bg-salvia text-primary hover:bg-salvia/70'
                                : 'border-oro bg-warning-soft text-bronce hover:bg-warning-soft/70'
                        "
                        :title="evento.titulo"
                        @click="abrirEvento(evento)"
                    >
                        {{ evento.titulo }}
                    </button>
                </div>
            </div>
        </div>

        <EmptyState
            v-if="eventos.length === 0"
            :icono="CalendarDays"
            titulo="Mes sin pendientes"
            descripcion="No hay sesiones en vivo ni fechas límite en este mes."
        />
    </div>
</template>
