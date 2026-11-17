<script setup lang="ts">
import { ArrowDownUp } from '@lucide/vue';
import { computed, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { ESTILO_CUADRANTE, tonoPuntaje } from '@/lib/nineBox';
import { formatoMoneda, formatoPorcentaje, tonoMora } from '@/lib/seguimiento';
import { cn } from '@/lib/utils';
import type { CuadranteNineBox, PersonaNineBox } from '@/types';

/** Vista de tabla de la Nine Box (misma información que la cuadrícula). */
const props = defineProps<{
    personas: PersonaNineBox[];
    cuadrantes: CuadranteNineBox[];
}>();

const emit = defineEmits<{ elegirPersona: [persona: PersonaNineBox] }>();

type Orden = 'desempeno' | 'potencial' | 'nombre' | 'colocacion';
const orden = ref<Orden>('desempeno');

const nombreCuadrante = computed(() => new Map(props.cuadrantes.map((c) => [c.clave, c.etiqueta])));

const filas = computed(() =>
    [...props.personas].sort((a, b) => {
        switch (orden.value) {
            case 'nombre':
                return a.nombre.localeCompare(b.nombre, 'es');
            case 'potencial':
                return (b.potencial.puntaje ?? -1) - (a.potencial.puntaje ?? -1);
            case 'colocacion':
                return b.kpis.colocacion - a.kpis.colocacion;
            default:
                return (b.desempeno.puntaje ?? -1) - (a.desempeno.puntaje ?? -1);
        }
    }),
);

const ESTADOS: Record<PersonaNineBox['estado'], string> = {
    ubicado: '',
    sin_vincular: 'Sin vincular',
    sin_potencial: 'Por calibrar',
};
</script>

<template>
    <div class="tabla-scroll">
        <table class="w-full min-w-[880px] text-left text-sm">
            <thead class="text-xs text-muted-foreground">
                <tr class="border-b border-border">
                    <th v-for="col in [
                        { clave: 'nombre', texto: 'Gestor', clase: '' },
                        { clave: null, texto: 'Cuadrante', clase: '' },
                        { clave: 'desempeno', texto: 'Desempeño', clase: 'text-right' },
                        { clave: 'potencial', texto: 'Potencial', clase: 'text-right' },
                        { clave: null, texto: 'Seguimientos', clase: 'text-right' },
                        { clave: null, texto: 'Calibración', clase: '' },
                        { clave: 'colocacion', texto: 'Colocación', clase: 'text-right' },
                        { clave: null, texto: 'Mora', clase: 'text-right' },
                    ]" :key="col.texto" class="py-2 pr-3 font-medium" :class="col.clase">
                        <button
                            v-if="col.clave"
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1 hover:text-foreground"
                            :class="orden === col.clave && 'text-foreground'"
                            @click="orden = col.clave as Orden"
                        >
                            {{ col.texto }} <ArrowDownUp class="size-3" />
                        </button>
                        <span v-else>{{ col.texto }}</span>
                    </th>
                </tr>
            </thead>
            <ListaAnimada tag="tbody" :cantidad="filas.length">
                <tr
                    v-for="p in filas"
                    :key="p.id"
                    class="group cursor-pointer border-b border-border/60 transicion-ui last:border-0 hover:bg-crema/40"
                    tabindex="0"
                    @click="emit('elegirPersona', p)"
                    @keydown.enter="emit('elegirPersona', p)"
                >
                    <td class="py-2 pr-3">
                        <div class="flex items-center gap-2.5">
                            <ColaboradorAvatar :nombre="p.nombre" :foto-url="p.foto_url" tamano="xs" />
                            <div class="min-w-0">
                                <p class="truncate font-medium group-hover:text-primary">{{ p.nombre }}</p>
                                <p class="truncate text-[11px] text-muted-foreground">
                                    {{ [p.puesto, p.sucursal ?? p.branch].filter(Boolean).join(' · ') }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="py-2 pr-3">
                        <span
                            v-if="p.cuadrante"
                            :class="cn('inline-flex rounded-full px-2 py-0.5 text-xs font-medium', ESTILO_CUADRANTE[p.cuadrante].fondo, ESTILO_CUADRANTE[p.cuadrante].acento)"
                            >{{ nombreCuadrante.get(p.cuadrante) }}</span
                        >
                        <span v-else class="inline-flex rounded-full bg-warning-soft px-2 py-0.5 text-xs text-warning">{{
                            ESTADOS[p.estado]
                        }}</span>
                    </td>
                    <td class="py-2 pr-3 text-right font-semibold tabular-nums" :class="tonoPuntaje(p.desempeno.puntaje)">
                        {{ p.desempeno.puntaje === null ? '—' : Math.round(p.desempeno.puntaje) }}
                    </td>
                    <td class="py-2 pr-3 text-right font-semibold tabular-nums" :class="tonoPuntaje(p.potencial.puntaje)">
                        {{ p.potencial.puntaje === null ? '—' : Math.round(p.potencial.puntaje) }}
                    </td>
                    <td class="py-2 pr-3 text-right tabular-nums">
                        {{ p.potencial.evaluaciones === null ? '—' : Math.round(p.potencial.evaluaciones) }}
                    </td>
                    <td class="py-2 pr-3 text-xs">
                        {{ p.potencial.calibracion?.etiqueta ?? '—' }}
                        <span v-if="p.potencial.calibracion?.heredada" class="text-muted-foreground">
                            ({{ p.potencial.calibracion.periodo }})</span
                        >
                    </td>
                    <td class="py-2 pr-3 text-right tabular-nums">{{ formatoMoneda(p.kpis.colocacion) }}</td>
                    <td class="py-2 pr-3 text-right">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium tabular-nums" :class="tonoMora(p.kpis.mora)">{{
                            formatoPorcentaje(p.kpis.mora)
                        }}</span>
                    </td>
                </tr>
            </ListaAnimada>
        </table>
    </div>
</template>
