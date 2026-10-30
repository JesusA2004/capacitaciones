<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AgendaCalendario from '@/components/agenda/AgendaCalendario.vue';
import AgendaEventoDialog from '@/components/agenda/AgendaEventoDialog.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    empalmes as empalmesRuta,
    index as agendaIndex,
    store as agendaStore,
} from '@/routes/agenda';
import { excel, pdf } from '@/routes/agenda/exportar';
import type {
    AgendaEvento,
    AgendaFiltros,
    AgendaIndicadores,
    AgendaRecord,
    TipoAgenda,
    VistaAgenda,
} from '@/types/agenda';

const props = defineProps<{
    filtros: AgendaFiltros;
    eventos: AgendaEvento[];
    indicadores: AgendaIndicadores;
    record: AgendaRecord[];
    eventoAbierto: AgendaEvento | null;
    sucursales: { id: number; nombre: string }[];
    candidatos: {
        id: number;
        nombre: string;
        puesto: string | null;
        sucursal_id: number | null;
    }[];
    puestos: { id: number; nombre: string }[];
    motivosNoViable: { value: string; etiqueta: string }[];
    estados: { value: string; etiqueta: string }[];
    puede: { agendar: boolean; alta_candidato: boolean; exportar: boolean };
}>();

const seleccionado = ref<AgendaEvento | null>(props.eventoAbierto);
const busqueda = ref(props.filtros.busqueda ?? '');
const altaRapida = ref(false);
const empalmes = ref<string[]>([]);

watch(
    () => props.eventoAbierto,
    (abierto) => {
        if (abierto !== null) {
            seleccionado.value = abierto;
        }
    },
);

function claveNueva(): string {
    return crypto.randomUUID();
}

const formulario = useForm<{
    candidato_id: number | null;
    candidato_nuevo: {
        nombre: string;
        apellidos: string;
        telefono: string;
        puesto_objetivo_id: number | null;
    } | null;
    sucursal_id: number | null;
    tipo: TipoAgenda;
    fecha: string;
    hora: string;
    notas: string;
    domicilio: string;
    cv: File | null;
    clave_idempotencia: string;
}>({
    candidato_id: null,
    candidato_nuevo: null,
    sucursal_id: props.filtros.sucursal_id,
    tipo: 'entrevista',
    fecha: '',
    hora: '',
    notas: '',
    domicilio: '',
    cv: null,
    clave_idempotencia: claveNueva(),
});

const candidatoNuevo = ref({
    nombre: '',
    apellidos: '',
    telefono: '',
    puesto_objetivo_id: null as number | null,
});

const opcionesCandidato = computed(() =>
    props.candidatos
        .filter(
            (c) =>
                formulario.sucursal_id === null ||
                c.sucursal_id === null ||
                c.sucursal_id === formulario.sucursal_id,
        )
        .map((c) => ({
            value: c.id,
            label: c.nombre,
            descripcion: c.puesto ?? undefined,
        })),
);
const opcionesSucursal = computed(() =>
    props.sucursales.map((s) => ({ value: s.id, label: s.nombre })),
);
const opcionesPuesto = computed(() =>
    props.puestos.map((p) => ({ value: p.id, label: p.nombre })),
);
const sucursalElegida = computed(
    () =>
        props.sucursales.find((s) => s.id === formulario.sucursal_id)?.nombre ??
        null,
);

watch(
    () => [formulario.sucursal_id, formulario.fecha, formulario.hora] as const,
    async ([sucursal, fecha, hora]) => {
        empalmes.value = [];

        if (sucursal === null || fecha === '' || hora === '') {
            return;
        }

        const respuesta = await fetch(
            empalmesRuta.url({ query: { sucursal_id: sucursal, fecha, hora } }),
            { headers: { Accept: 'application/json' } },
        );

        if (respuesta.ok) {
            const datos = (await respuesta.json()) as { empalmes: string[] };
            empalmes.value = datos.empalmes;
        }
    },
);

function elegirCv(evento: Event): void {
    formulario.cv = (evento.target as HTMLInputElement).files?.[0] ?? null;
}

function agendar(): void {
    formulario
        .transform((datos) => ({
            ...datos,
            candidato_id: altaRapida.value ? null : datos.candidato_id,
            candidato_nuevo: altaRapida.value ? candidatoNuevo.value : null,
        }))
        .post(agendaStore.url(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                formulario.reset(
                    'candidato_id',
                    'fecha',
                    'hora',
                    'notas',
                    'domicilio',
                    'cv',
                );
                formulario.clave_idempotencia = claveNueva();
                candidatoNuevo.value = {
                    nombre: '',
                    apellidos: '',
                    telefono: '',
                    puesto_objetivo_id: null,
                };
                altaRapida.value = false;
            },
        });
}

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
        Object.entries(base).filter(([, v]) => v !== null && v !== ''),
    ) as Record<string, string | number>;
}

function navegar(cambios: Record<string, unknown>): void {
    router.get(agendaIndex.url(), consultaActual(cambios), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
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

const tiposEvento: { v: TipoAgenda; e: string }[] = [
    { v: 'entrevista', e: 'Entrevista' },
    { v: 'socioeconomico', e: 'Socioeconómico' },
];

const vistas: { valor: VistaAgenda; etiqueta: string }[] = [
    { valor: 'semana', etiqueta: 'Semana' },
    { valor: 'mes', etiqueta: 'Mes' },
    { valor: 'lista', etiqueta: 'Lista' },
];

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
                    <a :href="excel.url(consultaExportar)">Excel</a>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <a :href="pdf.url(consultaExportar)">PDF</a>
                </Button>
            </template>
        </CrudPageHeader>

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

        <div class="grid items-start gap-4 lg:grid-cols-[320px_minmax(0,1fr)]">
            <form
                v-if="puede.agendar"
                class="grid gap-3 rounded-xl border-2 border-oro bg-card p-4"
                @submit.prevent="agendar"
            >
                <div>
                    <h2 class="font-semibold">Agendar evento</h2>
                    <p class="text-xs text-muted-foreground">
                        Queda registrado quién lo agendó.
                    </p>
                </div>

                <div class="grid gap-1 text-sm">
                    <span class="font-medium">1 · Sucursal</span>
                    <ComboboxBuscable
                        v-model="formulario.sucursal_id"
                        :opciones="opcionesSucursal"
                        numerico
                        placeholder="Elige la sucursal"
                        aria-label="Sucursal"
                    />
                    <InputError :message="formulario.errors.sucursal_id" />
                    <p
                        v-if="sucursalElegida"
                        class="rounded-md bg-salvia px-2 py-1 text-xs"
                    >
                        Se notificará en la app a <strong>Gerente</strong> y
                        <strong>Subgerente</strong> de {{ sucursalElegida }}.
                    </p>
                </div>

                <div class="grid gap-1 text-sm">
                    <span class="font-medium">2 · Candidato</span>
                    <template v-if="!altaRapida">
                        <ComboboxBuscable
                            v-model="formulario.candidato_id"
                            :opciones="opcionesCandidato"
                            numerico
                            placeholder="Busca al candidato"
                            texto-vacio="Sin candidatos para agendar"
                            aria-label="Candidato"
                        />
                    </template>
                    <div v-else class="grid gap-2">
                        <Input
                            v-model="candidatoNuevo.nombre"
                            placeholder="Nombre(s)"
                            aria-label="Nombre"
                        />
                        <Input
                            v-model="candidatoNuevo.apellidos"
                            placeholder="Apellidos"
                            aria-label="Apellidos"
                        />
                        <Input
                            v-model="candidatoNuevo.telefono"
                            placeholder="Teléfono"
                            aria-label="Teléfono"
                        />
                        <ComboboxBuscable
                            v-model="candidatoNuevo.puesto_objetivo_id"
                            :opciones="opcionesPuesto"
                            numerico
                            limpiable
                            placeholder="Puesto"
                            aria-label="Puesto"
                        />
                        <InputError
                            :message="
                                (formulario.errors as Record<string, string>)[
                                    'candidato_nuevo.nombre'
                                ]
                            "
                        />
                    </div>
                    <InputError :message="formulario.errors.candidato_id" />
                    <button
                        v-if="puede.alta_candidato"
                        type="button"
                        class="text-left text-xs font-medium text-bronce underline"
                        @click="altaRapida = !altaRapida"
                    >
                        {{
                            altaRapida
                                ? 'Elegir de la lista'
                                : '+ Registrar candidato nuevo'
                        }}
                    </button>
                </div>

                <div class="grid gap-1 text-sm">
                    <span class="font-medium">3 · Tipo de evento</span>
                    <div
                        class="grid grid-cols-2 gap-1 rounded-lg border border-border p-1"
                    >
                        <button
                            v-for="opcion in tiposEvento"
                            :key="opcion.v"
                            type="button"
                            :aria-pressed="formulario.tipo === opcion.v"
                            :class="
                                cn(
                                    'rounded-md px-2 py-1 text-xs font-medium',
                                    formulario.tipo === opcion.v &&
                                        'bg-primary text-primary-foreground',
                                )
                            "
                            @click="formulario.tipo = opcion.v"
                        >
                            {{ opcion.e }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 text-sm">
                    <label class="grid gap-1 font-medium">
                        4 · Fecha
                        <Input v-model="formulario.fecha" type="date" />
                    </label>
                    <label class="grid gap-1 font-medium">
                        5 · Hora
                        <Input v-model="formulario.hora" type="time" />
                    </label>
                </div>
                <InputError :message="formulario.errors.fecha" />
                <InputError :message="formulario.errors.hora" />

                <p
                    v-for="aviso in empalmes"
                    :key="aviso"
                    class="flex gap-1 rounded-md bg-warning-soft px-2 py-1 text-xs"
                >
                    <TriangleAlert class="size-3 shrink-0" /> {{ aviso }}
                </p>

                <Input
                    v-if="formulario.tipo === 'socioeconomico'"
                    v-model="formulario.domicilio"
                    placeholder="Domicilio de la visita"
                    aria-label="Domicilio de la visita"
                />

                <label class="grid gap-1 text-sm font-medium">
                    6 · CV del candidato
                    <input
                        type="file"
                        class="text-xs font-normal"
                        @change="elegirCv"
                    />
                </label>
                <InputError :message="formulario.errors.cv" />

                <label class="grid gap-1 text-sm font-medium">
                    Notas para el gerente (opcional)
                    <Textarea v-model="formulario.notas" rows="2" />
                </label>

                <Button type="submit" :disabled="formulario.processing">
                    Agendar y notificar
                </Button>
            </form>

            <section
                class="flex min-w-0 flex-col gap-3 rounded-xl border border-border bg-card p-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Periodo anterior"
                        @click="moverPeriodo(-1)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                    <h2 class="font-semibold first-letter:uppercase">
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
                    <div class="ml-auto flex flex-wrap items-center gap-1">
                        <Button
                            v-for="vista in vistas"
                            :key="vista.valor"
                            size="sm"
                            :variant="
                                filtros.vista === vista.valor
                                    ? 'default'
                                    : 'outline'
                            "
                            @click="navegar({ vista: vista.valor })"
                        >
                            {{ vista.etiqueta }}
                        </Button>
                        <ComboboxBuscable
                            :model-value="filtros.sucursal_id"
                            :opciones="opcionesSucursal"
                            numerico
                            limpiable
                            size="sm"
                            class="w-48"
                            placeholder="Todas las sucursales"
                            aria-label="Filtrar por sucursal"
                            @update:model-value="
                                (valor) => navegar({ sucursal: valor })
                            "
                        />
                    </div>
                </div>

                <form class="max-w-sm" @submit.prevent="navegar({})">
                    <Input
                        v-model="busqueda"
                        placeholder="Buscar candidato…"
                        aria-label="Buscar candidato"
                    />
                </form>

                <AgendaCalendario
                    v-if="filtros.vista !== 'lista'"
                    :vista="filtros.vista"
                    :ancla="filtros.ancla"
                    :eventos="eventos"
                    @abrir="(evento) => (seleccionado = evento)"
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="bg-crema text-left text-xs uppercase">
                            <tr>
                                <th class="px-2 py-2">Fecha</th>
                                <th class="px-2 py-2">Tipo</th>
                                <th class="px-2 py-2">Candidato</th>
                                <th class="px-2 py-2">Sucursal</th>
                                <th class="px-2 py-2">Estatus</th>
                                <th class="px-2 py-2">Resultado</th>
                                <th class="px-2 py-2">Origen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="evento in eventos"
                                :key="evento.id"
                                class="tarjeta-interactiva cursor-pointer border-b border-border"
                                @click="seleccionado = evento"
                            >
                                <td class="px-2 py-2">
                                    {{ evento.fecha }} {{ evento.hora }}
                                </td>
                                <td class="px-2 py-2">
                                    {{ evento.tipo_etiqueta }}
                                </td>
                                <td class="px-2 py-2 font-medium">
                                    {{ evento.candidato.nombre }}
                                </td>
                                <td class="px-2 py-2">
                                    {{ evento.sucursal.nombre }}
                                </td>
                                <td class="px-2 py-2">
                                    {{ evento.estado_etiqueta }}
                                </td>
                                <td class="px-2 py-2">
                                    {{ evento.resultado_etiqueta ?? '—' }}
                                </td>
                                <td class="px-2 py-2">
                                    {{ evento.origen_etiqueta }}
                                </td>
                            </tr>
                            <tr v-if="eventos.length === 0">
                                <td
                                    colspan="7"
                                    class="px-2 py-6 text-center text-muted-foreground"
                                >
                                    Sin eventos en este periodo.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

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

                <div class="overflow-x-auto">
                    <div class="mb-2 flex items-center gap-2">
                        <h2 class="font-semibold">Récord por sucursal</h2>
                        <Button
                            v-if="puede.exportar"
                            variant="ghost"
                            size="sm"
                            as-child
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
                                >Excel</a
                            >
                        </Button>
                    </div>
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="bg-crema text-left text-xs uppercase">
                            <tr>
                                <th class="px-2 py-2">Sucursal</th>
                                <th class="px-2 py-2 text-center">Agendadas</th>
                                <th class="px-2 py-2 text-center">
                                    Realizadas
                                </th>
                                <th class="px-2 py-2 text-center">
                                    No asistió
                                </th>
                                <th class="px-2 py-2 text-center">Viables</th>
                                <th class="px-2 py-2 text-center">
                                    Socioeconómicos
                                </th>
                                <th class="px-2 py-2 text-center">
                                    Días a resultado
                                </th>
                                <th class="px-2 py-2 text-center">Por RH</th>
                                <th class="px-2 py-2 text-center">
                                    Mes anterior
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="fila in record"
                                :key="fila.sucursal_id"
                                class="border-b border-border"
                            >
                                <td class="px-2 py-2 font-medium">
                                    {{ fila.sucursal }}
                                </td>
                                <td class="px-2 py-2 text-center">
                                    {{ fila.agendadas }}
                                </td>
                                <td class="px-2 py-2 text-center">
                                    {{ fila.realizadas }}
                                </td>
                                <td
                                    class="px-2 py-2 text-center text-destructive"
                                >
                                    {{ fila.no_asistio }}
                                </td>
                                <td class="px-2 py-2 text-center text-success">
                                    {{ fila.viables }}
                                </td>
                                <td class="px-2 py-2 text-center">
                                    {{ fila.socioeconomicos_realizados }}
                                </td>
                                <td class="px-2 py-2 text-center">
                                    {{ fila.dias_cita_resultado ?? '—' }}
                                </td>
                                <td class="px-2 py-2 text-center">
                                    {{ pct(fila.pct_reclutamiento) }}
                                </td>
                                <td class="px-2 py-2 text-center">
                                    {{ fila.agendadas_mes_anterior }}
                                </td>
                            </tr>
                            <tr v-if="record.length === 0">
                                <td
                                    colspan="9"
                                    class="px-2 py-6 text-center text-muted-foreground"
                                >
                                    Sin eventos en este mes.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <AgendaEventoDialog
        :evento="seleccionado"
        :motivos="motivosNoViable"
        @cerrar="seleccionado = null"
    />
</template>
