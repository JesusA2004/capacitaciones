<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarX,
    CopyCheck,
    Link2,
    ListX,
    Loader2,
    ShieldCheck,
    Shuffle,
    Tags,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PeopleConfirmDialog from '@/components/people/PeopleConfirmDialog.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import { etiquetaMes, numero, useNavegacionRotacion } from '@/lib/rotacion';
import { vTablaTarjetas } from '@/lib/tablaTarjetas';
import {
    estado as estadoHistoricaRuta,
    vincular as vincularRuta,
} from '@/routes/reportes/rotacion/tablero/conciliacion';
import type {
    CalidadRotacion,
    FiltroRapidoRotacion,
    PosibleDuplicadoRotacion,
} from '@/types';

/**
 * Calidad del dato: pendientes por resolver (cada uno filtra el histórico),
 * descuadres entre el Excel y las bajas individuales y, para quien puede
 * corregir, la conciliación de posibles duplicados (ligar o excluir con
 * comentario; nunca se borra nada).
 */
const props = defineProps<{
    calidad: CalidadRotacion;
    conciliacion: PosibleDuplicadoRotacion[] | undefined;
    puedeCorregir: boolean;
    rapidoActivo: string | null;
}>();

const nav = useNavegacionRotacion();

const tarjetas = computed(
    () =>
        [
            {
                etiqueta: 'Sin causal',
                valor: props.calidad.sin_causal,
                rapido: 'sin_causal',
                icono: ListX,
                tono: 'peligro',
            },
            {
                etiqueta: 'Sin homologar',
                valor: props.calidad.sin_homologar,
                rapido: 'sin_homologar',
                icono: Tags,
                tono: 'alerta',
            },
            {
                etiqueta: 'Sin fecha de ingreso',
                valor: props.calidad.sin_fecha_ingreso,
                rapido: 'sin_fecha',
                icono: CalendarX,
                tono: 'alerta',
            },
            {
                etiqueta: 'Fechas inconsistentes',
                valor: props.calidad.fecha_inconsistente,
                rapido: 'inconsistente',
                icono: AlertTriangle,
                tono: 'peligro',
            },
        ] as const,
);

function filtrar(rapido: FiltroRapidoRotacion): void {
    nav.actualizar({ rapido: props.rapidoActivo === rapido ? null : rapido });
}

/* --- Conciliación ------------------------------------------------------ */

const cargandoConciliacion = ref(false);

function cargarConciliacion(): void {
    router.reload({
        only: ['conciliacion'],
        onStart: () => {
            cargandoConciliacion.value = true;
        },
        onFinish: () => {
            cargandoConciliacion.value = false;
        },
    });
}

type Pendiente = {
    tipo: 'ligar' | 'excluir';
    par: PosibleDuplicadoRotacion;
};

const pendiente = ref<Pendiente | null>(null);
const comentario = ref('');
const errorComentario = ref('');
const enviando = ref(false);

const dialogoAbierto = computed({
    get: () => pendiente.value !== null,
    set: (v: boolean) => {
        if (!v) {
            pendiente.value = null;
        }
    },
});

function pedir(tipo: Pendiente['tipo'], par: PosibleDuplicadoRotacion): void {
    pendiente.value = { tipo, par };
    comentario.value = '';
    errorComentario.value = '';
}

function confirmar(): void {
    const p = pendiente.value;

    if (!p) {
        return;
    }

    const url =
        p.tipo === 'ligar' ? vincularRuta.url() : estadoHistoricaRuta.url();
    const datos =
        p.tipo === 'ligar'
            ? {
                  historica_id: p.par.historica_id,
                  baja_id: p.par.baja_id,
                  comentario: comentario.value,
              }
            : {
                  historica_id: p.par.historica_id,
                  estado: 'descartada',
                  comentario: comentario.value,
              };

    router.post(url, datos, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            enviando.value = true;
        },
        onFinish: () => {
            enviando.value = false;
        },
        onError: (errores) => {
            errorComentario.value =
                errores.comentario ??
                Object.values(errores)[0] ??
                'No se pudo guardar.';
        },
        onSuccess: () => {
            pendiente.value = null;
            cargarConciliacion();
        },
    });
}
</script>

<template>
    <section
        class="flex min-w-0 flex-col gap-4 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
        aria-labelledby="titulo-calidad"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2
                    id="titulo-calidad"
                    class="flex items-center gap-2 font-semibold"
                >
                    <ShieldCheck
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />
                    Calidad del dato
                </h2>
                <p class="text-xs text-muted-foreground">
                    Del periodo y alcance completos. Toca un pendiente para
                    filtrar el histórico.
                </p>
            </div>
            <p
                v-if="calidad.duplicadas_ligadas > 0"
                class="text-xs text-muted-foreground"
            >
                {{ numero(calidad.duplicadas_ligadas) }} histórica(s) ya ligadas
                a una baja del sistema (no cuentan doble).
            </p>
        </div>

        <div class="grid grid-cols-2 gap-2.5 md:grid-cols-4">
            <TarjetaMetrica
                v-for="t in tarjetas"
                :key="t.rapido"
                :etiqueta="t.etiqueta"
                :valor="t.valor"
                :icono="t.icono"
                :tono="t.valor > 0 ? t.tono : 'neutro'"
                clicable
                :activa="rapidoActivo === t.rapido"
                @click="filtrar(t.rapido)"
            />
        </div>

        <!-- Descuadres -->
        <div v-if="calidad.discrepancias.length" class="flex flex-col gap-2">
            <h3 class="flex items-center gap-1.5 text-sm font-medium">
                <Shuffle class="size-4 text-warning" aria-hidden="true" />
                Descuadres entre el Excel y las bajas individuales
            </h3>
            <div class="tabla-scroll">
                <table v-tabla-tarjetas class="w-full text-left text-xs">
                    <thead class="text-muted-foreground">
                        <tr class="border-b border-border/60">
                            <th class="px-2 py-1.5 font-medium">Mes</th>
                            <th class="px-2 py-1.5 font-medium">Sucursal</th>
                            <th class="px-2 py-1.5 text-right font-medium">
                                Excel
                            </th>
                            <th class="px-2 py-1.5 text-right font-medium">
                                Individuales
                            </th>
                            <th class="px-2 py-1.5 text-right font-medium">
                                Diferencia
                            </th>
                            <th class="px-2 py-1.5 font-medium">
                                <span class="sr-only">Acción</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in calidad.discrepancias"
                            :key="`${d.mes}-${d.sucursal_id}`"
                            class="border-b border-border/40"
                        >
                            <td class="px-2 py-1.5">
                                {{ etiquetaMes(d.mes) }}
                            </td>
                            <td class="px-2 py-1.5">{{ d.sucursal }}</td>
                            <td class="px-2 py-1.5 text-right tabular-nums">
                                {{ numero(d.agregado) }}
                            </td>
                            <td class="px-2 py-1.5 text-right tabular-nums">
                                {{ numero(d.individuales) }}
                            </td>
                            <td
                                class="px-2 py-1.5 text-right font-semibold tabular-nums"
                                :class="
                                    d.diferencia > 0
                                        ? 'text-warning'
                                        : 'text-destructive'
                                "
                            >
                                {{ d.diferencia > 0 ? '+' : ''
                                }}{{ numero(d.diferencia) }}
                            </td>
                            <td class="px-2 py-1.5 text-right">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="
                                        nav.verHistorico({
                                            d_mes: d.mes,
                                            d_sucursal: d.sucursal_id,
                                        })
                                    "
                                    >Ver bajas</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-[11px] text-muted-foreground">
                Diferencia positiva: el Excel trae más bajas que las registradas
                una por una (se cuentan como «sin desglose»).
            </p>
        </div>

        <!-- Conciliación -->
        <div
            v-if="puedeCorregir"
            class="flex flex-col gap-2 rounded-2xl bg-crema/30 p-3"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="flex items-center gap-1.5 text-sm font-medium">
                    <CopyCheck class="size-4 text-bronce" aria-hidden="true" />
                    Posibles duplicados
                    <span
                        class="rounded-full bg-oro/15 px-1.5 text-xs text-bronce tabular-nums"
                        >{{ calidad.posibles_duplicados }}</span
                    >
                </h3>
                <Button
                    v-if="conciliacion === undefined"
                    size="sm"
                    variant="outline"
                    :disabled="
                        cargandoConciliacion ||
                        calidad.posibles_duplicados === 0
                    "
                    @click="cargarConciliacion"
                >
                    <Loader2
                        v-if="cargandoConciliacion"
                        class="size-4 animate-spin"
                    />
                    Revisar
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                Misma persona con menos de 8 días de diferencia entre una baja
                histórica y una del sistema. Nunca se ligan solas.
            </p>
            <template v-if="conciliacion !== undefined">
                <p
                    v-if="!conciliacion.length"
                    class="text-xs text-muted-foreground"
                >
                    No quedan posibles duplicados.
                </p>
                <ul v-else class="flex flex-col gap-2">
                    <li
                        v-for="p in conciliacion"
                        :key="`${p.historica_id}-${p.baja_id}`"
                        class="flex flex-col gap-2 rounded-xl border border-border/60 bg-card p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">{{ p.nombre }}</p>
                            <p class="text-xs text-muted-foreground">
                                Histórico:
                                {{ formatearFecha(p.fecha_historica) }} ·
                                Sistema: {{ formatearFecha(p.fecha_sistema) }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <Button size="sm" @click="pedir('ligar', p)">
                                <Link2 class="size-4" />Ligar
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                @click="pedir('excluir', p)"
                            >
                                <XCircle class="size-4" />Excluir histórica
                            </Button>
                        </div>
                    </li>
                </ul>
            </template>
        </div>

        <PeopleConfirmDialog
            v-model:open="dialogoAbierto"
            :titulo="
                pendiente?.tipo === 'ligar'
                    ? 'Ligar con la baja del sistema'
                    : 'Excluir la baja histórica'
            "
            :descripcion="
                pendiente?.tipo === 'ligar'
                    ? `La baja histórica de ${pendiente?.par.nombre} se marca como duplicada y deja de contarse. La del sistema se conserva.`
                    : `La baja histórica de ${pendiente?.par.nombre} deja de contarse. No se borra: queda en la bitácora y se puede restablecer.`
            "
            pedir-comentario
            comentario-label="Comentario (queda en la bitácora)"
            v-model:comentario-model-value="comentario"
            :comentario-error="errorComentario"
            :comentario-maximo="1000"
            :destructivo="pendiente?.tipo === 'excluir'"
            :cargando="enviando"
            :texto-confirmar="pendiente?.tipo === 'ligar' ? 'Ligar' : 'Excluir'"
            @confirm="confirmar"
        />
    </section>
</template>
