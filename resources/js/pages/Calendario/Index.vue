<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
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
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
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

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Calendario"
                description="Fechas límite y sesiones en vivo próximas"
            />
            <div class="flex items-center gap-2">
                <Button variant="outline" size="icon" @click="mesAnterior">
                    <ChevronLeft class="size-4" />
                </Button>
                <span class="w-36 text-center text-sm font-medium capitalize">
                    {{ nombreMes }}
                </span>
                <Button variant="outline" size="icon" @click="mesSiguiente">
                    <ChevronRight class="size-4" />
                </Button>
            </div>
        </div>

        <div
            class="grid grid-cols-7 gap-px overflow-hidden rounded-lg border bg-border text-xs"
        >
            <div
                v-for="dia in diasSemana"
                :key="dia"
                class="bg-muted/50 px-2 py-1 text-center font-medium"
            >
                {{ dia }}
            </div>

            <div
                v-for="dia in diasDelMes"
                :key="dia.toISOString()"
                class="min-h-24 bg-background p-1.5"
                :class="{ 'opacity-40': !isSameMonth(dia, mesActual) }"
            >
                <span
                    class="inline-flex size-5 items-center justify-center rounded-full text-xs"
                    :class="{
                        'bg-[var(--brand-primary)] text-white': isToday(dia),
                    }"
                >
                    {{ format(dia, 'd') }}
                </span>

                <div class="mt-1 flex flex-col gap-1">
                    <button
                        v-for="evento in eventosDelDia(dia)"
                        :key="evento.id"
                        type="button"
                        class="block w-full text-left"
                        @click="abrirEvento(evento)"
                    >
                        <Badge
                            :variant="
                                evento.tipo === 'sesion'
                                    ? 'default'
                                    : 'secondary'
                            "
                            class="w-full justify-start truncate text-[10px]"
                        >
                            {{ evento.titulo }}
                        </Badge>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
