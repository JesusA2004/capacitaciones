<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useInitials } from '@/composables/useInitials';
import { MESES, subtituloPersona } from '@/lib/celebraciones';
import type { EventoCelebracion } from '@/types';

/**
 * Calendario mensual de celebraciones (cumpleaños y aniversarios): solo
 * PRESENTA los eventos del mes que ya calculó el backend — nunca pide
 * datos por celda ni conoce reglas (29/feb, edad, años de servicio).
 *
 * Cada día con celebraciones es un <button> que abre un popover con la
 * lista (clic, tap o teclado; no depende de hover). Se adapta al ancho del
 * contenedor: en angosto los días muestran una sola foto + contador; en
 * ancho, hasta 3 fotos y "+N".
 */
const props = defineProps<{
    anio: number;
    mes: number;
    eventos: EventoCelebracion[];
    /** Fecha de hoy (Y-m-d) según el backend. */
    hoy: string;
    etiquetaEvento: string;
}>();

const emit = defineEmits<{ navegar: [anio: number, mes: number] }>();

const DIAS_SEMANA = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const { getInitials } = useInitials();

type Celda = { dia: number | null; fecha: string | null; eventos: EventoCelebracion[] };

const porFecha = computed(() => {
    const mapa = new Map<string, EventoCelebracion[]>();

    for (const evento of props.eventos) {
        mapa.set(evento.fecha, [...(mapa.get(evento.fecha) ?? []), evento]);
    }

    return mapa;
});

const celdas = computed<Celda[]>(() => {
    const lista: Celda[] = [];
    const primerDia = new Date(props.anio, props.mes - 1, 1).getDay();
    const diasEnMes = new Date(props.anio, props.mes, 0).getDate();

    for (let i = 0; i < primerDia; i++) {
        lista.push({ dia: null, fecha: null, eventos: [] });
    }

    for (let dia = 1; dia <= diasEnMes; dia++) {
        const fecha = `${props.anio}-${String(props.mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
        lista.push({ dia, fecha, eventos: porFecha.value.get(fecha) ?? [] });
    }

    while (lista.length % 7 !== 0) {
        lista.push({ dia: null, fecha: null, eventos: [] });
    }

    return lista;
});

const esMesActual = computed(() => props.hoy.startsWith(`${props.anio}-${String(props.mes).padStart(2, '0')}`));

function mover(delta: number) {
    const fecha = new Date(props.anio, props.mes - 1 + delta, 1);
    emit('navegar', fecha.getFullYear(), fecha.getMonth() + 1);
}

function irAHoy() {
    const [anio, mes] = props.hoy.split('-').map(Number);
    emit('navegar', anio, mes);
}

function etiquetaDia(celda: Celda): string {
    const base = `${celda.dia} de ${MESES[props.mes - 1]}`;
    const hoy = celda.fecha === props.hoy ? ', hoy' : '';

    return celda.eventos.length > 0 ? `${base}${hoy}: ${celda.eventos.length} ${props.etiquetaEvento}` : `${base}${hoy}`;
}
</script>

<template>
    <section class="@container flex min-w-0 flex-col rounded-xl border bg-card" aria-label="Calendario">
        <header class="flex items-center justify-between gap-2 border-b px-2 py-1.5 sm:px-3">
            <div class="flex items-center gap-0.5">
                <Button variant="ghost" size="icon-sm" aria-label="Mes anterior" @click="mover(-1)">
                    <ChevronLeft class="size-4" />
                </Button>
                <h2 class="min-w-32 text-center text-sm font-semibold sm:text-base" aria-live="polite">{{ MESES[mes - 1] }} {{ anio }}</h2>
                <Button variant="ghost" size="icon-sm" aria-label="Mes siguiente" @click="mover(1)">
                    <ChevronRight class="size-4" />
                </Button>
            </div>
            <Button v-if="!esMesActual" variant="outline" size="sm" @click="irAHoy">Hoy</Button>
        </header>

        <div class="grid grid-cols-7 border-b text-center text-[11px] font-medium text-muted-foreground uppercase">
            <div v-for="d in DIAS_SEMANA" :key="d" class="py-1.5">{{ d }}</div>
        </div>

        <div class="grid flex-1 auto-rows-fr grid-cols-7">
            <template v-for="(celda, i) in celdas" :key="i">
                <div
                    v-if="celda.dia === null || celda.eventos.length === 0"
                    class="flex min-h-12 flex-col border-b border-border/60 p-1 @lg:min-h-18 @3xl:min-h-24 @3xl:p-1.5 [&:nth-child(7n)]:border-r-0 [&:nth-last-child(-n+7)]:border-b-0"
                    :class="[celda.dia === null ? 'bg-muted/30' : '', 'border-r']"
                >
                    <span
                        v-if="celda.dia !== null"
                        class="flex size-6 items-center justify-center rounded-full text-xs"
                        :class="celda.fecha === hoy ? 'bg-primary font-bold text-primary-foreground' : 'text-muted-foreground'"
                        :aria-label="etiquetaDia(celda)"
                    >
                        {{ celda.dia }}
                    </span>
                </div>

                <Popover v-else>
                    <PopoverTrigger as-child>
                        <button
                            type="button"
                            class="flex min-h-12 min-w-0 flex-col items-start gap-1 border-r border-b border-border/60 bg-amber-400/[0.06] p-1 text-left transition-colors outline-none hover:bg-amber-400/15 focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-inset data-[state=open]:bg-amber-400/20 @lg:min-h-18 @3xl:min-h-24 @3xl:p-1.5 [&:nth-child(7n)]:border-r-0 [&:nth-last-child(-n+7)]:border-b-0"
                            :aria-label="etiquetaDia(celda)"
                        >
                            <span
                                class="flex size-6 items-center justify-center rounded-full text-xs font-semibold"
                                :class="celda.fecha === hoy ? 'bg-primary text-primary-foreground' : 'text-foreground'"
                            >
                                {{ celda.dia }}
                            </span>

                            <!-- Angosto: una foto + contador numérico (no solo color). -->
                            <span class="relative @lg:hidden">
                                <Avatar class="size-6 ring-1 ring-background">
                                    <AvatarImage v-if="celda.eventos[0].foto_url" :src="celda.eventos[0].foto_url" alt="" />
                                    <AvatarFallback class="text-[9px]">{{ getInitials(celda.eventos[0].nombre) }}</AvatarFallback>
                                </Avatar>
                                <span
                                    v-if="celda.eventos.length > 1"
                                    class="absolute -right-2 -bottom-1 rounded-full bg-foreground px-1 text-[9px] leading-tight font-semibold text-background"
                                    >{{ celda.eventos.length }}</span
                                >
                            </span>

                            <!-- Ancho: hasta 3 fotos apiladas y +N. -->
                            <span class="hidden items-center @lg:flex">
                                <Avatar
                                    v-for="evento in celda.eventos.slice(0, 3)"
                                    :key="evento.colaborador_id"
                                    class="size-7 ring-2 ring-card not-first:-ml-2 @3xl:size-8"
                                >
                                    <AvatarImage v-if="evento.foto_url" :src="evento.foto_url" alt="" />
                                    <AvatarFallback class="text-[10px]">{{ getInitials(evento.nombre) }}</AvatarFallback>
                                </Avatar>
                                <span v-if="celda.eventos.length > 3" class="ml-1 text-[11px] font-semibold text-muted-foreground">+{{ celda.eventos.length - 3 }}</span>
                            </span>

                            <!-- Muy ancho: primer nombre visible, sin depender del popover. -->
                            <span v-if="celda.eventos.length === 1" class="hidden w-full text-[11px] leading-tight font-medium break-words @4xl:block">
                                {{ celda.eventos[0].nombre.split(' ')[0] }}
                            </span>
                        </button>
                    </PopoverTrigger>
                    <PopoverContent class="w-80 max-w-[calc(100vw-1.5rem)] p-0" align="center">
                        <p class="border-b px-3 py-2 text-sm font-semibold">{{ celda.dia }} de {{ MESES[mes - 1] }}</p>
                        <ul class="max-h-80 divide-y overflow-y-auto">
                            <li v-for="evento in celda.eventos" :key="evento.colaborador_id" class="flex items-center gap-3 px-3 py-2.5">
                                <Avatar class="size-10 shrink-0">
                                    <AvatarImage v-if="evento.foto_url" :src="evento.foto_url" :alt="`Foto de ${evento.nombre}`" />
                                    <AvatarFallback>{{ getInitials(evento.nombre) }}</AvatarFallback>
                                </Avatar>
                                <div class="min-w-0">
                                    <p class="text-sm leading-snug font-medium break-words">{{ evento.nombre }}</p>
                                    <p v-if="evento.detalle" class="text-xs font-medium text-amber-700 dark:text-amber-300">{{ evento.detalle }}</p>
                                    <p class="text-xs break-words text-muted-foreground">{{ subtituloPersona(evento) }}</p>
                                </div>
                            </li>
                        </ul>
                    </PopoverContent>
                </Popover>
            </template>
        </div>
    </section>
</template>
