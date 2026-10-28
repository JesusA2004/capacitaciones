<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    CircleCheck,
    ClipboardCheck,
    Eye,
    Hourglass,
    ListFilter,
    MessageSquareText,
    PlayCircle,
    RotateCcw,
    Search,
    Send,
    Trash2,
    UserPlus,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import AsignarInduccionDialog from '@/components/onboarding/AsignarInduccionDialog.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatearFecha } from '@/lib/fechas';
import type { Tono } from '@/lib/tonos';
import { vistaColaborador } from '@/routes/rh/onboarding/induccion';
import { destroy as retirarAsignacion } from '@/routes/rh/onboarding/induccion/asignaciones';
import type {
    ColaboradorBusqueda,
    EstadoAsignacionInduccion,
    FilaAsignacionInduccion,
} from '@/types';

/**
 * Asignaciones de la Inducción Mr. Lana (RH): resumen por estado, una
 * tarjeta por persona con sus pasos, y «Asignar», «Reasignar», «Retirar» y
 * «Ver como colaborador». La regla vive en AsignacionInduccionService.
 */
const props = defineProps<{
    filas: FilaAsignacionInduccion[];
    material: 'audio' | 'video' | null;
}>();

const ESTADOS: Record<
    EstadoAsignacionInduccion,
    { tono: Tono; icono: Component }
> = {
    sin_asignar: { tono: 'neutro', icono: Hourglass },
    retirada: { tono: 'neutro', icono: Trash2 },
    asignada: { tono: 'oro', icono: Send },
    viendo: { tono: 'primario', icono: PlayCircle },
    material_visto: { tono: 'info', icono: Eye },
    evaluacion_pendiente: { tono: 'alerta', icono: ClipboardCheck },
    en_revision: { tono: 'info', icono: Hourglass },
    reforzamiento: { tono: 'peligro', icono: MessageSquareText },
    aprobada: { tono: 'exito', icono: CircleCheck },
};

type Filtro = 'todas' | 'por_ver' | 'evaluacion' | 'en_revision' | 'aprobada';

const filtros: {
    valor: Filtro;
    texto: string;
    tono: Tono;
    icono: Component;
    estados: EstadoAsignacionInduccion[];
}[] = [
    {
        valor: 'todas',
        texto: 'Todas',
        tono: 'primario',
        icono: ListFilter,
        estados: [],
    },
    {
        valor: 'por_ver',
        texto: 'Por ver o viendo',
        tono: 'oro',
        icono: PlayCircle,
        estados: ['asignada', 'viendo', 'material_visto'],
    },
    {
        valor: 'evaluacion',
        texto: 'Evaluación pendiente',
        tono: 'alerta',
        icono: ClipboardCheck,
        estados: ['evaluacion_pendiente', 'reforzamiento'],
    },
    {
        valor: 'en_revision',
        texto: 'En revisión de RH',
        tono: 'info',
        icono: Hourglass,
        estados: ['en_revision'],
    },
    {
        valor: 'aprobada',
        texto: 'Aprobadas',
        tono: 'exito',
        icono: CircleCheck,
        estados: ['aprobada'],
    },
];

const filtro = ref<Filtro>('todas');
const busqueda = ref('');

function coincide(f: FilaAsignacionInduccion, valor: Filtro): boolean {
    const def = filtros.find((x) => x.valor === valor);

    return (
        valor === 'todas' ||
        (def?.estados.includes(f.induccion.estado_rh) ?? false)
    );
}

function normalizar(t: string): string {
    return t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const visibles = computed(() => {
    const termino = normalizar(busqueda.value.trim());

    return props.filas.filter(
        (f) =>
            coincide(f, filtro.value) &&
            (termino === '' ||
                normalizar(
                    [
                        f.colaborador.nombre,
                        f.colaborador.numero_empleado,
                        f.colaborador.puesto,
                        f.colaborador.sucursal,
                    ]
                        .filter(Boolean)
                        .join(' '),
                ).includes(termino)),
    );
});

const dialogo = ref(false);
const preseleccion = ref<ColaboradorBusqueda[]>([]);

function asignar(fila?: FilaAsignacionInduccion) {
    preseleccion.value = fila ? [{ ...fila.colaborador }] : [];
    dialogo.value = true;
}

function retirar(fila: FilaAsignacionInduccion) {
    const motivo = window.prompt(
        `¿Retirar la inducción asignada a ${fila.colaborador.nombre}? Dejará de verla en Mi espacio${fila.induccion.es_reinduccion ? ' y se cancela su reinducción' : ''}. Motivo (opcional):`,
        '',
    );

    if (motivo === null) {
        return;
    }

    router.delete(retirarAsignacion.url(fila.colaborador.id), {
        data: { motivo: motivo || null },
        preserveScroll: true,
    });
}

defineExpose({ asignar });
</script>

<template>
    <section
        class="flex flex-col gap-4"
        aria-label="Asignaciones de la inducción"
    >
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">
                    ¿Quién tiene la inducción asignada?
                </h2>
                <p class="text-sm text-muted-foreground">
                    Cada persona la ve por su cuenta en «Mi inducción». Aquí ves
                    en qué paso va.
                </p>
            </div>
            <Button class="w-full sm:w-auto" @click="asignar()">
                <UserPlus class="size-4" /> Asignar inducción
            </Button>
        </div>

        <div
            class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3 xl:grid-cols-5"
        >
            <TarjetaMetrica
                v-for="f in filtros"
                :key="f.valor"
                :etiqueta="f.texto"
                :valor="filas.filter((x) => coincide(x, f.valor)).length"
                :icono="f.icono"
                :tono="f.tono"
                clicable
                :activa="filtro === f.valor"
                @click="filtro = filtro === f.valor ? 'todas' : f.valor"
            />
        </div>

        <div class="relative sm:max-w-sm">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="busqueda"
                class="h-10 pl-9"
                placeholder="Buscar colaborador, puesto o sucursal"
            />
        </div>

        <EmptyState
            v-if="visibles.length === 0"
            :icono="UserPlus"
            titulo="Nadie con este filtro"
            descripcion="Asigna la inducción a una persona, a una sucursal o a todo tu alcance con «Asignar inducción»."
        />

        <ul v-else class="rejilla-auto" style="--rejilla-min: 21rem">
            <li
                v-for="fila in visibles"
                :key="fila.colaborador.id"
                class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
            >
                <div class="flex items-start gap-3 p-4">
                    <ColaboradorAvatar
                        :nombre="fila.colaborador.nombre"
                        :foto-url="fila.colaborador.foto_url"
                        tamano="lg"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">
                            {{ fila.colaborador.nombre }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{
                                [
                                    fila.colaborador.numero_empleado,
                                    fila.colaborador.puesto,
                                    fila.colaborador.sucursal,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <ChipEstado
                                :etiqueta="fila.induccion.estado_rh_etiqueta"
                                :tono="ESTADOS[fila.induccion.estado_rh].tono"
                                :icono="ESTADOS[fila.induccion.estado_rh].icono"
                            />
                            <ChipEstado
                                v-if="fila.induccion.es_reinduccion"
                                etiqueta="Reinducción"
                                tono="oro"
                                :icono="RotateCcw"
                            />
                            <ChipEstado
                                v-if="fila.induccion.asignacion?.vencida"
                                etiqueta="Fecha límite vencida"
                                tono="peligro"
                            />
                        </div>
                    </div>
                    <span
                        class="shrink-0 text-xl font-bold text-primary tabular-nums"
                        >{{ fila.induccion.porcentaje }}%</span
                    >
                </div>

                <ol class="grid grid-cols-4 gap-1 px-4 pb-3" aria-label="Pasos">
                    <li
                        v-for="paso in fila.induccion.pasos"
                        :key="paso.clave"
                        class="flex flex-col gap-1"
                        :title="`${paso.titulo}: ${paso.detalle}`"
                    >
                        <span
                            class="h-1.5 rounded-full"
                            :class="{
                                'bg-success': paso.estado === 'completado',
                                'bg-primary': paso.estado === 'actual',
                                'bg-destructive': paso.estado === 'detenido',
                                'bg-muted': paso.estado === 'pendiente',
                                'bg-muted/40': paso.estado === 'omitido',
                            }"
                        />
                        <span
                            class="truncate text-[11px]"
                            :class="
                                paso.estado === 'omitido'
                                    ? 'text-muted-foreground line-through'
                                    : 'text-muted-foreground'
                            "
                            >{{ paso.titulo }}</span
                        >
                    </li>
                </ol>

                <p
                    v-if="
                        fila.induccion.asignacion &&
                        !fila.induccion.asignacion.automatica
                    "
                    class="px-4 pb-3 text-xs text-muted-foreground"
                >
                    Asignada
                    {{
                        fila.induccion.asignacion.asignada_en
                            ? formatearFecha(
                                  fila.induccion.asignacion.asignada_en,
                              )
                            : ''
                    }}
                    <template v-if="fila.induccion.asignacion.asignada_por"
                        >por
                        {{ fila.induccion.asignacion.asignada_por }}</template
                    >
                    <template v-if="fila.induccion.asignacion.fecha_limite">
                        · límite
                        {{
                            formatearFecha(
                                fila.induccion.asignacion.fecha_limite,
                            )
                        }}</template
                    >
                </p>

                <footer
                    class="mt-auto flex flex-col gap-2 border-t border-border/50 bg-[var(--mrl-fondo)]/60 p-3 sm:flex-row sm:flex-wrap"
                >
                    <Button
                        as-child
                        variant="outline"
                        class="h-11 flex-1 sm:h-9"
                    >
                        <Link :href="vistaColaborador.url(fila.colaborador.id)"
                            ><Eye class="size-4" /> Ver como colaborador</Link
                        >
                    </Button>
                    <Button
                        variant="ghost"
                        class="h-11 flex-1 sm:h-9"
                        @click="asignar(fila)"
                    >
                        <RotateCcw class="size-4" /> Reasignar
                    </Button>
                    <Button
                        v-if="fila.puede_retirar"
                        variant="ghost"
                        class="h-11 flex-1 text-destructive sm:h-9"
                        @click="retirar(fila)"
                    >
                        <Trash2 class="size-4" /> Retirar
                    </Button>
                </footer>
            </li>
        </ul>

        <AsignarInduccionDialog
            v-model:open="dialogo"
            :material="material"
            :preseleccion="preseleccion"
        />
    </section>
</template>
