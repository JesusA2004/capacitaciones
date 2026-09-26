<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Award, Cake, PartyPopper, Settings2 } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import CelebracionAcciones from '@/components/Celebraciones/CelebracionAcciones.vue';
import CelebracionCalendario from '@/components/Celebraciones/CelebracionCalendario.vue';
import CelebracionesTabsNav from '@/components/Celebraciones/CelebracionesTabsNav.vue';
import CelebracionFiltros from '@/components/Celebraciones/CelebracionFiltros.vue';
import CelebracionLista from '@/components/Celebraciones/CelebracionLista.vue';
import TarjetaCelebracionPersona from '@/components/Celebraciones/TarjetaCelebracionPersona.vue';
import { Button } from '@/components/ui/button';
import { useCelebracion } from '@/composables/useCelebracion';
import {
    MESES,
    subtituloPersona,
    TEXTOS_CELEBRACION,
} from '@/lib/celebraciones';
import type {
    EventoCelebracion,
    FiltrosCelebracion,
    OpcionCelebracion,
    PermisosCelebracion,
    TipoCelebracion,
} from '@/types';

/**
 * Pantalla completa de Celebraciones, IDÉNTICA para Cumpleaños y
 * Aniversarios (docs/CELEBRACIONES.md). Las páginas Rh/Cumpleanos/Index y
 * Rh/Aniversarios/Index solo le pasan datos y traducen `navegar` a su ruta.
 *
 *   [Cumpleaños | Aniversarios]        [Buscar] [Filtros] [Configurar]
 *   HOY (tarjetas con el flujo completo de la tarjeta)
 *   CALENDARIO                          PRÓXIMOS
 *   LISTADO DEL MES
 *
 * Calendario y Próximos van lado a lado solo cuando el ANCHO DEL CONTENIDO
 * (container query, no el viewport: el sidebar puede estar abierto o no)
 * deja al menos ~75 px por día; si no, se apilan.
 */
export type NavegacionCelebraciones = {
    anio: number;
    mes: number;
    filtros: FiltrosCelebracion;
    rango: { desde: string; hasta: string };
};

const props = defineProps<{
    tipo: TipoCelebracion;
    /** Fecha de hoy (Y-m-d, America/Mexico_City) según el backend. */
    fechaHoy: string;
    anio: number;
    mes: number;
    hoy: EventoCelebracion[];
    delMes: EventoCelebracion[];
    proximos: EventoCelebracion[];
    rango: { desde: string; hasta: string };
    filtros: FiltrosCelebracion;
    catalogos: {
        empresas?: OpcionCelebracion[];
        sucursales: OpcionCelebracion[];
        departamentos: OpcionCelebracion[];
        colaboradores?: OpcionCelebracion[];
        estatus?: { valor: string; etiqueta: string }[];
    };
    permisos: PermisosCelebracion;
    configuracionUrl?: string | null;
}>();

const emit = defineEmits<{ navegar: [navegacion: NavegacionCelebraciones] }>();

const textos = computed(() => TEXTOS_CELEBRACION[props.tipo]);
const icono = computed(() => (props.tipo === 'cumpleanos' ? Cake : Award));
const { celebrar } = useCelebracion();

onMounted(() => {
    if (props.hoy.length > 0) {
        celebrar();
    }
});

function navegar(cambios: Partial<NavegacionCelebraciones>) {
    emit('navegar', {
        anio: props.anio,
        mes: props.mes,
        filtros: props.filtros,
        rango: props.rango,
        ...cambios,
    });
}
</script>

<template>
    <div
        class="mx-auto flex w-full max-w-screen-2xl min-w-0 flex-col gap-4 p-3 sm:p-4 lg:px-6"
    >
        <CelebracionesTabsNav
            :activa="tipo === 'cumpleanos' ? 'cumpleanos' : 'aniversarios'"
        >
            <CelebracionFiltros
                :filtros="filtros"
                :rango="rango"
                :catalogos="catalogos"
                @aplicar="(f, r) => navegar({ filtros: f, rango: r })"
            />
            <Button
                v-if="configuracionUrl"
                as-child
                variant="outline"
                size="sm"
                class="shrink-0"
            >
                <Link :href="configuracionUrl" aria-label="Configurar tarjeta">
                    <Settings2 class="size-4" />
                    <span class="hidden md:inline">Configurar</span>
                </Link>
            </Button>
        </CelebracionesTabsNav>

        <slot name="avisos" />

        <section
            v-if="hoy.length > 0"
            aria-labelledby="celebraciones-hoy"
            class="flex flex-col gap-2"
        >
            <h2
                id="celebraciones-hoy"
                class="flex items-center gap-2 text-sm font-semibold"
            >
                <PartyPopper class="size-4 text-amber-500" />
                {{ textos.hoy }}
                <span
                    class="rounded-full bg-amber-400/15 px-2 text-xs text-amber-700 tabular-nums dark:text-amber-300"
                    >{{ hoy.length }}</span
                >
            </h2>
            <!-- Máximo 2 columnas (cada tarjeta lleva 6 acciones); con número impar
                 la última ocupa la fila completa: nunca queda una huérfana. -->
            <div class="@container">
                <div
                    class="grid gap-3 @4xl:grid-cols-2 @4xl:[&>*:last-child:nth-child(odd)]:col-span-2"
                >
                    <TarjetaCelebracionPersona
                        v-for="evento in hoy"
                        :key="evento.colaborador_id"
                        :nombre="evento.nombre"
                        :subtitulo="subtituloPersona(evento)"
                        :detalle="evento.detalle"
                        :foto-url="evento.foto_url"
                        destacado
                    >
                        <template #icono
                            ><component
                                :is="icono"
                                class="mt-0.5 size-4 shrink-0 text-amber-500"
                                aria-hidden="true"
                        /></template>
                        <template #acciones>
                            <CelebracionAcciones
                                :evento="evento"
                                :tipo="tipo"
                                :permisos="permisos"
                            >
                                <template #dialogo
                                    ><slot name="dialogo" :evento="evento"
                                /></template>
                            </CelebracionAcciones>
                        </template>
                    </TarjetaCelebracionPersona>
                </div>
            </div>
        </section>
        <p v-else class="flex items-center gap-2 text-sm text-muted-foreground">
            <component :is="icono" class="size-4" aria-hidden="true" />
            {{
                tipo === 'cumpleanos'
                    ? 'Hoy nadie cumple años.'
                    : 'Hoy nadie cumple aniversario.'
            }}
        </p>

        <div class="@container min-w-0">
            <div
                class="grid gap-4"
                :class="
                    permisos.calendario &&
                    '@4xl:grid-cols-[minmax(0,1fr)_22rem] @6xl:grid-cols-[minmax(0,1fr)_26rem]'
                "
            >
                <CelebracionCalendario
                    v-if="permisos.calendario"
                    :anio="anio"
                    :mes="mes"
                    :eventos="delMes"
                    :hoy="fechaHoy"
                    :etiqueta-evento="
                        tipo === 'cumpleanos' ? 'cumpleaños' : 'aniversarios'
                    "
                    @navegar="(a, m) => navegar({ anio: a, mes: m })"
                />
                <CelebracionLista
                    :titulo="textos.proximos"
                    :eventos="proximos"
                    :hoy="fechaHoy"
                    :vacio="textos.vacioProximos"
                    :limite="6"
                />
            </div>
        </div>

        <CelebracionLista
            :titulo="`${textos.periodo} ${MESES[mes - 1].toLowerCase()} ${anio}`"
            :eventos="delMes"
            :hoy="fechaHoy"
            :vacio="textos.vacioPeriodo"
            :limite="12"
        />
    </div>
</template>
