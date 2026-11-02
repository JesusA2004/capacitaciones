<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarPlus,
    CalendarX,
    ChevronLeft,
    ChevronRight,
    FileSpreadsheet,
    FileText,
    Search,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AgendaCalendario from '@/components/agenda/AgendaCalendario.vue';
import AgendaEventoDialog from '@/components/agenda/AgendaEventoDialog.vue';
import AgendarEventoDialog from '@/components/agenda/AgendarEventoDialog.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import EnlaceRegreso from '@/components/flujos/EnlaceRegreso.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import { Input } from '@/components/ui/input';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import { vTablaTarjetas } from '@/lib/tablaTarjetas';
import type { Tono } from '@/lib/tonos';
import { index as agendaIndex } from '@/routes/agenda';
import { excel, pdf } from '@/routes/agenda/exportar';
import type { EnlaceFlujo } from '@/types';
import type {
    AgendaCandidatoOpcion,
    AgendaEvento,
    AgendaFiltros,
    AgendaIndicadores,
    AgendaRecord,
    EstadoAgenda,
    VistaAgenda,
} from '@/types/agenda';

const props = defineProps<{
    filtros: AgendaFiltros;
    eventos: AgendaEvento[];
    indicadores: AgendaIndicadores;
    record: AgendaRecord[];
    eventoAbierto: AgendaEvento | null;
    candidatoInicial?: { id: number; sucursal_id: number | null } | null;
    regreso?: EnlaceFlujo | null;
    sucursales: { id: number; nombre: string }[];
    candidatos: AgendaCandidatoOpcion[];
    puestos: { id: number; nombre: string }[];
    motivosNoViable: { value: string; etiqueta: string }[];
    estados: { value: string; etiqueta: string }[];
    puede: {
        agendar: boolean;
        alta_candidato: boolean;
        exportar: boolean;
        bandeja_cv?: boolean;
    };
    cvConfig?: { max_mb: number; extensiones: string[] } | null;
}>();

const seleccionado = ref<AgendaEvento | null>(props.eventoAbierto);
const busqueda = ref(props.filtros.busqueda ?? '');

watch(
    () => props.eventoAbierto,
    (abierto) => {
        if (abierto !== null) {
            seleccionado.value = abierto;
        }
    },
);

// Desde la ficha (?candidato=) abre directo con el candidato elegido: a eso venía.
const agendarAbierto = ref(!!props.candidatoInicial);

function consultaActual(
    cambios: Record<string, unknown> = {},
): Record<string, string | number> {
    const base: Record<string, unknown> = {
        vista: props.filtros.vista,
        fecha: props.filtros.ancla,
        sucursal: props.filtros.sucursal_id,
        tipo: props.filtros.tipo,
        estado: props.filtros.estado,
        busqueda: busqueda.value,
        ...cambios,
    };

    return Object.fromEntries(
        Object.entries(base).filter(
            ([, v]) => v !== null && v !== undefined && v !== '',
        ),
    ) as Record<string, string | number>;
}

function navegar(cambios: Record<string, unknown>): void {
    router.get(agendaIndex.url(), consultaActual(cambios), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function limpiarBusqueda(): void {
    busqueda.value = '';
    navegar({ busqueda: null });
}

function moverPeriodo(pasos: number): void {
    const base = new Date(`${props.filtros.ancla}T12:00:00`);

    if (props.filtros.vista === 'semana') {
        base.setDate(base.getDate() + pasos * 7);
    } else {
        base.setDate(1);
        base.setMonth(base.getMonth() + pasos);
    }

    const mes = String(base.getMonth() + 1).padStart(2, '0');
    const dia = String(base.getDate()).padStart(2, '0');
    navegar({ fecha: `${base.getFullYear()}-${mes}-${dia}` });
}

function irAHoy(): void {
    navegar({ fecha: null });
}

const vistas: { valor: VistaAgenda; etiqueta: string }[] = [
    { valor: 'semana', etiqueta: 'Semana' },
    { valor: 'mes', etiqueta: 'Mes' },
    { valor: 'lista', etiqueta: 'Lista' },
];

const vistaActual = computed<VistaAgenda>({
    get: () => props.filtros.vista,
    set: (vista) => navegar({ vista }),
});

const opcionesSucursal = computed(() =>
    props.sucursales.map((s) => ({ value: s.id, label: s.nombre })),
);
const opcionesTipo = [
    { value: 'entrevista', label: 'Entrevistas' },
    { value: 'socioeconomico', label: 'Socioeconómicos' },
];
const opcionesEstado = computed(() =>
    props.estados.map((e) => ({ value: e.value, label: e.etiqueta })),
);

const hayFiltros = computed(
    () =>
        !!props.filtros.sucursal_id ||
        !!props.filtros.tipo ||
        !!props.filtros.estado ||
        !!props.filtros.busqueda,
);

function quitarFiltros(): void {
    busqueda.value = '';
    navegar({ sucursal: null, tipo: null, estado: null, busqueda: null });
}

const tituloPeriodo = computed(() => {
    const formato = new Intl.DateTimeFormat('es-MX', {
        day: 'numeric',
        month: 'long',
    });
    const desde = new Date(`${props.filtros.desde}T12:00:00`);
    const hasta = new Date(`${props.filtros.hasta}T12:00:00`);

    if (props.filtros.vista === 'semana') {
        return `Semana del ${formato.format(desde)} al ${formato.format(hasta)}`;
    }

    return new Intl.DateTimeFormat('es-MX', {
        month: 'long',
        year: 'numeric',
    }).format(desde);
});

const formatoDia = new Intl.DateTimeFormat('es-MX', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});

function diaCorto(fecha: string): string {
    return formatoDia.format(new Date(`${fecha}T12:00:00`));
}

function hora12(hora: string): string {
    const [h, m] = hora.split(':').map(Number);
    const sufijo = h < 12 ? 'a. m.' : 'p. m.';

    return `${h % 12 === 0 ? 12 : h % 12}:${String(m).padStart(2, '0')} ${sufijo}`;
}

const TONO_ESTADO: Record<EstadoAgenda, Tono> = {
    programado: 'info',
    realizado: 'exito',
    no_asistio: 'peligro',
    reprogramado: 'alerta',
    cancelado: 'neutro',
};

function tonoResultado(resultado: string | null): Tono {
    return resultado === 'viable' || resultado === 'favorable'
        ? 'exito'
        : 'peligro';
}

function pct(valor: number | null): string {
    return valor === null ? 'Sin datos' : `${valor}%`;
}

const consultaExportar = computed(() => ({ query: consultaActual() }));
</script>

<template>
    <Head title="Agenda de entrevistas" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader titulo="Agenda de entrevistas" :icono="CalendarClock">
            <template v-if="puede.exportar">
                <Button variant="outline" size="sm" as-child>
                    <a :href="excel.url(consultaExportar)">
                        <FileSpreadsheet class="size-4" /> Excel
                    </a>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <a :href="pdf.url(consultaExportar)">
                        <FileText class="size-4" /> PDF
                    </a>
                </Button>
            </template>
            <Button v-if="puede.agendar" @click="agendarAbierto = true">
                <CalendarPlus class="size-4" /> Agendar evento
            </Button>
        </CrudPageHeader>
        <EnlaceRegreso :regreso="regreso" />

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <TarjetaMetrica
                etiqueta="Entrevistas agendadas"
                :valor="indicadores.agendadas"
                tono="primario"
                detalle="En el mes"
            />
            <TarjetaMetrica
                etiqueta="Realizadas"
                :valor="indicadores.realizadas"
                tono="info"
                :detalle="`${pct(indicadores.pct_realizadas)} de las agendadas`"
            />
            <TarjetaMetrica
                etiqueta="Candidato no asistió"
                :valor="indicadores.no_asistio"
                tono="peligro"
                :detalle="`${pct(indicadores.pct_inasistencia)} de inasistencia`"
            />
            <TarjetaMetrica
                etiqueta="Viables"
                :valor="`${indicadores.viables} de ${indicadores.realizadas}`"
                tono="exito"
                :detalle="`${pct(indicadores.pct_viabilidad)} de las realizadas`"
            />
            <TarjetaMetrica
                etiqueta="Agendadas por Reclutamiento"
                :valor="`${indicadores.agendadas_reclutamiento} de ${indicadores.agendadas}`"
                tono="oro"
                :detalle="pct(indicadores.pct_reclutamiento)"
            />
        </div>

        <AgendarEventoDialog
            v-if="puede.agendar"
            v-model:open="agendarAbierto"
            :sucursales="sucursales"
            :candidatos="candidatos"
            :puestos="puestos"
            :puede="puede"
            :cv-config="cvConfig"
            :candidato-inicial-id="candidatoInicial?.id ?? null"
            :sucursal-sugerida="filtros.sucursal_id"
        />

        <section
            class="flex min-w-0 flex-col gap-4 rounded-2xl border border-border bg-card p-4 sm:p-5"
        >
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Periodo anterior"
                        @click="moverPeriodo(-1)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                    <h2
                        class="min-w-0 text-base font-semibold first-letter:uppercase"
                    >
                        {{ tituloPeriodo }}
                    </h2>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Periodo siguiente"
                        @click="moverPeriodo(1)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                    <Button variant="outline" size="sm" @click="irAHoy">
                        Hoy
                    </Button>
                </div>
                <SelectorVista
                    v-model="vistaActual"
                    :opciones="vistas"
                    etiqueta="Vista de la agenda"
                    class="ml-auto"
                />
            </div>

            <div
                class="grid gap-2 rounded-2xl bg-crema/50 p-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.4fr)_repeat(3,minmax(0,1fr))_auto]"
            >
                <form
                    class="relative"
                    role="search"
                    @submit.prevent="navegar({})"
                >
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="busqueda"
                        class="bg-card pr-9 pl-9"
                        placeholder="Buscar candidato y presiona Enter"
                        aria-label="Buscar candidato"
                    />
                    <button
                        v-if="busqueda"
                        type="button"
                        class="absolute top-1/2 right-2 flex size-6 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label="Limpiar búsqueda"
                        @click="limpiarBusqueda"
                    >
                        <X class="size-3.5" />
                    </button>
                </form>
                <ComboboxBuscable
                    :model-value="filtros.sucursal_id"
                    :opciones="opcionesSucursal"
                    numerico
                    limpiable
                    clase-boton="bg-card"
                    placeholder="Todas las sucursales"
                    aria-label="Filtrar por sucursal"
                    @update:model-value="
                        (valor) => navegar({ sucursal: valor })
                    "
                />
                <SelectSimple
                    :model-value="filtros.tipo"
                    :opciones="opcionesTipo"
                    opcion-vacia="Todos los tipos"
                    placeholder="Todos los tipos"
                    aria-label="Filtrar por tipo"
                    class="bg-card"
                    @update:model-value="(valor) => navegar({ tipo: valor })"
                />
                <SelectSimple
                    :model-value="filtros.estado"
                    :opciones="opcionesEstado"
                    opcion-vacia="Todos los estatus"
                    placeholder="Todos los estatus"
                    aria-label="Filtrar por estatus"
                    class="bg-card"
                    @update:model-value="(valor) => navegar({ estado: valor })"
                />
                <Button
                    v-if="hayFiltros"
                    variant="ghost"
                    size="sm"
                    class="h-9"
                    @click="quitarFiltros"
                >
                    <X class="size-4" /> Quitar filtros
                </Button>
            </div>

            <AgendaCalendario
                v-if="filtros.vista !== 'lista'"
                :vista="filtros.vista"
                :ancla="filtros.ancla"
                :eventos="eventos"
                @abrir="(evento) => (seleccionado = evento)"
            />

            <template v-else>
                <div v-if="eventos.length" class="tabla-scroll">
                    <table
                        v-tabla-tarjetas
                        class="w-full min-w-[760px] text-sm"
                    >
                        <thead
                            class="bg-crema text-left text-xs font-semibold text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="rounded-l-xl px-3 py-2.5">Cuándo</th>
                                <th class="px-3 py-2.5">Candidato</th>
                                <th class="px-3 py-2.5">Tipo</th>
                                <th class="px-3 py-2.5">Sucursal</th>
                                <th class="px-3 py-2.5">Estatus</th>
                                <th class="px-3 py-2.5">Resultado</th>
                                <th class="rounded-r-xl px-3 py-2.5">Origen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="evento in eventos"
                                :key="evento.id"
                                class="tarjeta-interactiva border-b border-border last:border-0"
                                tabindex="0"
                                @click="seleccionado = evento"
                                @keydown.enter="seleccionado = evento"
                            >
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <span
                                        class="block font-medium first-letter:uppercase"
                                        >{{ diaCorto(evento.fecha) }}</span
                                    >
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ hora12(evento.hora) }}</span
                                    >
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <ColaboradorAvatar
                                            :nombre="evento.candidato.nombre"
                                            tamano="sm"
                                        />
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">
                                                {{ evento.candidato.nombre }}
                                            </p>
                                            <p
                                                v-if="evento.candidato.puesto"
                                                class="truncate text-xs text-muted-foreground"
                                            >
                                                {{ evento.candidato.puesto }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <span
                                        class="inline-flex items-center gap-1.5"
                                    >
                                        <span
                                            class="size-2 rounded-full"
                                            :class="
                                                evento.tipo === 'entrevista'
                                                    ? 'bg-primary'
                                                    : 'bg-bronce'
                                            "
                                        />
                                        {{ evento.tipo_etiqueta }}
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    {{ evento.sucursal.nombre }}
                                </td>
                                <td class="px-3 py-3">
                                    <ChipEstado
                                        :etiqueta="evento.estado_etiqueta"
                                        :tono="TONO_ESTADO[evento.estado]"
                                    />
                                </td>
                                <td class="px-3 py-3">
                                    <ChipEstado
                                        v-if="evento.resultado_etiqueta"
                                        :etiqueta="evento.resultado_etiqueta"
                                        :tono="tonoResultado(evento.resultado)"
                                    />
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-3 py-3 text-muted-foreground">
                                    {{ evento.origen_etiqueta }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState
                    v-else
                    :icono="CalendarX"
                    titulo="Sin eventos en este periodo"
                    :descripcion="
                        hayFiltros
                            ? 'Prueba quitando los filtros o cambia de periodo.'
                            : 'Cuando agendes una entrevista o un estudio aparecerá aquí.'
                    "
                />
            </template>

            <div class="flex flex-wrap gap-3 text-xs text-muted-foreground">
                <span class="inline-flex items-center gap-1">
                    <span class="size-2 rounded-full bg-primary" />
                    Entrevista
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="size-2 rounded-full bg-bronce" /> Estudio
                    socioeconómico
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="size-2 rounded-full bg-warning" />
                    Reprogramado
                </span>
            </div>
        </section>

        <section
            class="flex min-w-0 flex-col gap-3 rounded-2xl border border-border bg-card p-4 sm:p-5"
        >
            <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold">Récord por sucursal</h2>
                <Button
                    v-if="puede.exportar"
                    variant="ghost"
                    size="sm"
                    as-child
                    class="ml-auto"
                >
                    <a
                        :href="
                            excel.url({
                                query: {
                                    ...consultaActual(),
                                    vista: 'record',
                                },
                            })
                        "
                    >
                        <FileSpreadsheet class="size-4" /> Excel
                    </a>
                </Button>
            </div>
            <div class="tabla-scroll">
                <table v-tabla-tarjetas class="w-full min-w-[720px] text-sm">
                    <thead
                        class="bg-crema text-left text-xs font-semibold text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="rounded-l-xl px-3 py-2.5">Sucursal</th>
                            <th class="px-3 py-2.5 text-center">Agendadas</th>
                            <th class="px-3 py-2.5 text-center">Realizadas</th>
                            <th class="px-3 py-2.5 text-center">No asistió</th>
                            <th class="px-3 py-2.5 text-center">Viables</th>
                            <th class="px-3 py-2.5 text-center">
                                Socioeconómicos
                            </th>
                            <th class="px-3 py-2.5 text-center">
                                Días a resultado
                            </th>
                            <th class="px-3 py-2.5 text-center">Por RH</th>
                            <th class="rounded-r-xl px-3 py-2.5 text-center">
                                Mes anterior
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="fila in record"
                            :key="fila.sucursal_id"
                            class="border-b border-border last:border-0"
                        >
                            <td class="px-3 py-2.5 font-medium">
                                {{ fila.sucursal }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ fila.agendadas }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ fila.realizadas }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-center text-destructive tabular-nums"
                            >
                                {{ fila.no_asistio }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-center text-success tabular-nums"
                            >
                                {{ fila.viables }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ fila.socioeconomicos_realizados }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ fila.dias_cita_resultado ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ pct(fila.pct_reclutamiento) }}
                            </td>
                            <td class="px-3 py-2.5 text-center tabular-nums">
                                {{ fila.agendadas_mes_anterior }}
                            </td>
                        </tr>
                        <tr v-if="record.length === 0">
                            <td
                                colspan="9"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                Sin eventos en este mes.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <AgendaEventoDialog
        :evento="seleccionado"
        :motivos="motivosNoViable"
        @cerrar="seleccionado = null"
    />
</template>
