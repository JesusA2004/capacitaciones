<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { AgendaEvento, VistaAgenda } from '@/types/agenda';

/**
 * Vista semanal (lunes a sábado por hora) y mensual de la agenda. Una cita
 * reprogramada o cancelada se ve atenuada: el historial no desaparece.
 */
const props = defineProps<{
    vista: Exclude<VistaAgenda, 'lista'>;
    ancla: string;
    eventos: AgendaEvento[];
}>();

const emit = defineEmits<{ abrir: [evento: AgendaEvento] }>();

const DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

function fecha(valor: string): Date {
    return new Date(`${valor}T12:00:00`);
}

function iso(valor: Date): string {
    const mes = String(valor.getMonth() + 1).padStart(2, '0');
    const dia = String(valor.getDate()).padStart(2, '0');

    return `${valor.getFullYear()}-${mes}-${dia}`;
}

function sumarDias(valor: Date, dias: number): Date {
    const copia = new Date(valor);
    copia.setDate(copia.getDate() + dias);

    return copia;
}

const lunes = computed(() => {
    const base = fecha(props.ancla);

    return sumarDias(base, -((base.getDay() + 6) % 7));
});

const diasSemana = computed(() =>
    Array.from({ length: 6 }, (_, i) => {
        const dia = sumarDias(lunes.value, i);

        return {
            iso: iso(dia),
            etiqueta: `${DIAS[i]} ${dia.getDate()}`,
        };
    }),
);

const horas = computed(() => {
    const conEvento = props.eventos.map((e) => Number(e.hora.slice(0, 2)));
    const conjunto = new Set([9, 10, 11, 12, 13, 16, 17, ...conEvento]);

    return [...conjunto].sort((a, b) => a - b);
});

const semanasMes = computed(() => {
    const base = fecha(props.ancla);
    const primero = new Date(base.getFullYear(), base.getMonth(), 1, 12);
    const inicio = sumarDias(primero, -((primero.getDay() + 6) % 7));

    return Array.from({ length: 6 }, (_, s) =>
        Array.from({ length: 7 }, (_, d) => {
            const dia = sumarDias(inicio, s * 7 + d);

            return {
                iso: iso(dia),
                numero: dia.getDate(),
                delMes: dia.getMonth() === base.getMonth(),
            };
        }),
    ).filter((semana) => semana.some((d) => d.delMes));
});

function enCelda(dia: string, hora: number): AgendaEvento[] {
    return props.eventos.filter(
        (e) => e.fecha === dia && Number(e.hora.slice(0, 2)) === hora,
    );
}

function delDia(dia: string): AgendaEvento[] {
    return props.eventos.filter((e) => e.fecha === dia);
}

function claseEvento(evento: AgendaEvento): string {
    return cn(
        'block w-full tarjeta-interactiva rounded-md px-2 py-1 text-left text-[11px] leading-tight',
        evento.tipo === 'entrevista'
            ? 'bg-primary text-primary-foreground'
            : 'bg-bronce text-white',
        evento.reprogramado_de_id !== null &&
            evento.estado === 'programado' &&
            'border-l-4 border-l-warning',
        ['cancelado', 'reprogramado'].includes(evento.estado) &&
            'line-through opacity-50',
        evento.estado === 'no_asistio' && 'opacity-70',
    );
}

function porQuien(evento: AgendaEvento): string {
    return evento.origen === 'reclutamiento' ? 'por RH' : 'por Gerente';
}
</script>

<template>
    <div v-if="vista === 'semana'" class="overflow-x-auto">
        <table
            class="w-full min-w-[720px] table-fixed border-separate border-spacing-1 text-xs"
        >
            <thead>
                <tr>
                    <th class="w-12" />
                    <th
                        v-for="dia in diasSemana"
                        :key="dia.iso"
                        class="rounded-md bg-crema px-2 py-1 font-semibold"
                    >
                        {{ dia.etiqueta }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="hora in horas" :key="hora">
                    <td class="align-top text-muted-foreground">
                        {{ String(hora).padStart(2, '0') }}:00
                    </td>
                    <td
                        v-for="dia in diasSemana"
                        :key="dia.iso"
                        class="h-12 rounded-md border border-dashed border-border align-top"
                    >
                        <div class="flex flex-col gap-1">
                            <button
                                v-for="evento in enCelda(dia.iso, hora)"
                                :key="evento.id"
                                type="button"
                                :class="claseEvento(evento)"
                                @click="emit('abrir', evento)"
                            >
                                <span class="block font-semibold">
                                    {{ evento.hora }} ·
                                    {{
                                        evento.tipo === 'entrevista'
                                            ? 'Entrevista'
                                            : 'Socioeconómico'
                                    }}
                                </span>
                                <span class="block truncate">{{
                                    evento.candidato.nombre
                                }}</span>
                                <span class="block truncate opacity-85">
                                    {{ evento.sucursal.nombre }} ·
                                    {{ porQuien(evento) }}
                                </span>
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-else class="overflow-x-auto">
        <div class="grid min-w-[720px] grid-cols-7 gap-1 text-xs">
            <div
                v-for="dia in DIAS"
                :key="dia"
                class="rounded-md bg-crema px-2 py-1 text-center font-semibold"
            >
                {{ dia }}
            </div>
            <template v-for="semana in semanasMes">
                <div
                    v-for="dia in semana"
                    :key="dia.iso"
                    :class="
                        cn(
                            'min-h-20 rounded-md border border-border p-1',
                            !dia.delMes && 'bg-muted/40 text-muted-foreground',
                        )
                    "
                >
                    <div class="mb-1 font-medium">{{ dia.numero }}</div>
                    <div class="flex flex-col gap-1">
                        <button
                            v-for="evento in delDia(dia.iso)"
                            :key="evento.id"
                            type="button"
                            :class="claseEvento(evento)"
                            @click="emit('abrir', evento)"
                        >
                            <span class="block truncate">
                                {{ evento.hora }} {{ evento.candidato.nombre }}
                            </span>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
