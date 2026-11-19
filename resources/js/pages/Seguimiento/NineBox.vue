<script setup lang="ts">
import { Deferred, Head, Link } from '@inertiajs/vue3';
import {
    CalendarRange,
    FileSpreadsheet,
    Grid3x3,
    Info,
    Lightbulb,
    Search,
    SlidersHorizontal,
    Sparkles,
    Star,
    UserCheck,
    UserX,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import AvisoReportes from '@/components/Seguimiento/AvisoReportes.vue';
import ConfiguracionNineBoxDialogo from '@/components/Seguimiento/NineBox/ConfiguracionNineBoxDialogo.vue';
import MatrizNineBox from '@/components/Seguimiento/NineBox/MatrizNineBox.vue';
import PanelPersonaNineBox from '@/components/Seguimiento/NineBox/PanelPersonaNineBox.vue';
import TablaNineBox from '@/components/Seguimiento/NineBox/TablaNineBox.vue';
import SelectorPeriodo from '@/components/Seguimiento/SelectorPeriodo.vue';
import TarjetaKpi from '@/components/Seguimiento/TarjetaKpi.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import { Input } from '@/components/ui/input';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useSeguimientoPeriodo } from '@/composables/useSeguimientoPeriodo';
import { ESTILO_CUADRANTE, normalizarTexto } from '@/lib/nineBox';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as indexSeguimiento } from '@/routes/seguimiento';
import { index as indexGestores } from '@/routes/seguimiento/gestores';
import { exportar, index as indexNineBox } from '@/routes/seguimiento/nine-box';
import type {
    ClaveCuadranteNineBox,
    ConfiguracionNineBox,
    FiltrosNineBox,
    MatrizNineBox as Matriz,
    MesSeguimiento,
    PersonaNineBox,
    RespuestaSeguimiento,
    TipoPeriodoNineBox,
} from '@/types';

const props = defineProps<{
    filtros: FiltrosNineBox;
    configurado: boolean;
    puedeConfigurar: boolean;
    puedeCalibrar: boolean;
    niveles: { value: string; label: string }[];
    configuracion: ConfiguracionNineBox | null;
    datos?: RespuestaSeguimiento<Matriz>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Seguimiento', href: indexSeguimiento() },
            { title: 'Nine Box', href: '' },
        ],
    },
});

const { cargando, ir } = useSeguimientoPeriodo(() => indexNineBox.url());

const matriz = computed(() => props.datos?.datos ?? null);
const periodo = computed(() => matriz.value?.periodo ?? null);
const tipo = computed<TipoPeriodoNineBox>(
    () => periodo.value?.tipo ?? props.filtros.tipo,
);

// ── Periodo ───────────────────────────────────────────────────────────────
const mesesSelector = computed<MesSeguimiento[]>(() =>
    (matriz.value?.periodos.meses ?? []).map((p) => ({
        id: p.meses_ids[0],
        codigo: p.clave,
        etiqueta: p.etiqueta,
        inicio: p.inicio,
        fin: p.fin,
        radiografia: true,
        semanas_ids: [],
    })),
);

const trimestres = computed(() =>
    [...(matriz.value?.periodos.trimestres ?? [])].reverse(),
);

function irA(
    nuevoTipo: TipoPeriodoNineBox,
    clave: string | null,
    sucursal = props.filtros.sucursal,
): void {
    ir({ tipo: nuevoTipo, periodo: clave, sucursal });
}

function cambiarTipo(nuevo: TipoPeriodoNineBox): void {
    if (nuevo !== tipo.value) {
        irA(nuevo, null);
    }
}

const tipoElegido = computed({
    get: () => tipo.value,
    set: (valor: TipoPeriodoNineBox) => cambiarTipo(valor),
});

function elegirMes(mesId: number): void {
    const clave =
        matriz.value?.periodos.meses.find((p) => p.meses_ids[0] === mesId)
            ?.clave ?? null;
    irA('mes', clave);
}

function cambiarSucursal(valor: number | string | null): void {
    irA(
        tipo.value,
        periodo.value?.clave ?? null,
        valor === null || valor === '' ? null : Number(valor),
    );
}

// ── Filtros locales ──────────────────────────────────────────────────────
const vista = ref<'matriz' | 'tabla'>('matriz');
const busqueda = ref('');
const seleccionado = ref<ClaveCuadranteNineBox | null>(null);

const coincidencias = computed<Set<number> | null>(() => {
    const termino = normalizarTexto(busqueda.value);

    if (termino === '') {
        return null;
    }

    return new Set(
        (matriz.value?.personas ?? [])
            .filter((p) =>
                normalizarTexto(
                    `${p.nombre} ${p.sucursal ?? ''} ${p.branch ?? ''} ${p.puesto ?? ''}`,
                ).includes(termino),
            )
            .map((p) => p.id),
    );
});

const ubicadas = computed(() =>
    (matriz.value?.personas ?? []).filter((p) => p.cuadrante !== null),
);
const fueraDeMatriz = computed(() =>
    (matriz.value?.personas ?? []).filter((p) => p.cuadrante === null),
);

const filasTabla = computed(() =>
    (matriz.value?.personas ?? []).filter(
        (p) =>
            (coincidencias.value === null || coincidencias.value.has(p.id)) &&
            (seleccionado.value === null || p.cuadrante === seleccionado.value),
    ),
);

const cuadranteSeleccionado = computed(
    () =>
        matriz.value?.cuadrantes.find((c) => c.clave === seleccionado.value) ??
        null,
);

const personasDelCuadrante = computed(() =>
    ubicadas.value.filter(
        (p) =>
            p.cuadrante === seleccionado.value &&
            (coincidencias.value === null || coincidencias.value.has(p.id)),
    ),
);

const distribucion = computed(() => {
    const total = Math.max(1, matriz.value?.resumen.ubicados ?? 0);

    return [...(matriz.value?.cuadrantes ?? [])]
        .map((c) => ({ ...c, cantidad: matriz.value?.conteos[c.clave] ?? 0 }))
        .sort((a, b) => b.cantidad - a.cantidad)
        .map((c) => ({
            ...c,
            porcentaje: Math.round((c.cantidad / total) * 100),
        }));
});

// ── Panel de persona ─────────────────────────────────────────────────────
const panelAbierto = ref(false);
const personaId = ref<number | null>(null);
const persona = computed<PersonaNineBox | null>(
    () => matriz.value?.personas.find((p) => p.id === personaId.value) ?? null,
);
const cuadrantePersona = computed(
    () =>
        matriz.value?.cuadrantes.find(
            (c) => c.clave === persona.value?.cuadrante,
        ) ?? null,
);

function abrirPersona(p: PersonaNineBox): void {
    personaId.value = p.id;
    panelAbierto.value = true;
}

const configAbierta = ref(false);

const urlExportar = computed(() =>
    exportar.url({
        query: {
            tipo: tipo.value,
            ...(periodo.value ? { periodo: periodo.value.clave } : {}),
            ...(props.filtros.sucursal
                ? { sucursal: props.filtros.sucursal }
                : {}),
        },
    }),
);
</script>

<template>
    <Head title="Nine Box" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Nine Box"
            descripcion="Desempeño con datos duros de Reportes × potencial (Seguimientos técnicos + calibración del jefe)."
            :icono="Grid3x3"
        >
            <Button variant="outline" as-child data-tour="ninebox-por-gestor">
                <Link :href="indexGestores()"
                    ><Users class="size-4" /> Por gestor</Link
                >
            </Button>
            <Button v-if="matriz?.periodo" variant="outline" as-child>
                <a :href="urlExportar"
                    ><FileSpreadsheet class="size-4" /> Exportar a Excel</a
                >
            </Button>
            <Button
                v-if="puedeConfigurar && configuracion"
                variant="outline"
                @click="configAbierta = true"
            >
                <SlidersHorizontal class="size-4" /> Configurar
            </Button>
        </CrudPageHeader>

        <div data-tour="ninebox-contenido" class="flex flex-col gap-5">
            <Deferred data="datos">
                <template #fallback>
                    <div
                        class="flex flex-col gap-5"
                        aria-busy="true"
                        aria-label="Cargando la Nine Box"
                    >
                        <Skeleton class="h-32 rounded-2xl" />
                        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                            <Skeleton
                                v-for="i in 5"
                                :key="i"
                                class="h-28 rounded-2xl"
                            />
                        </div>
                        <div
                            class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1fr)_22rem]"
                        >
                            <div
                                class="grid h-[clamp(480px,64vh,760px)] grid-cols-3 grid-rows-3 gap-2.5"
                            >
                                <Skeleton
                                    v-for="i in 9"
                                    :key="i"
                                    class="rounded-2xl"
                                />
                            </div>
                            <Skeleton class="h-96 rounded-2xl" />
                        </div>
                    </div>
                </template>

                <AvisoReportes v-if="datos?.error" :mensaje="datos.error" />

                <template v-else-if="matriz">
                    <!-- Periodo: mes de Reportes o trimestre -->
                    <div
                        data-tour="ninebox-periodo"
                        class="flex flex-col gap-3"
                    >
                        <SelectorVista
                            v-model="tipoElegido"
                            etiqueta="Tipo de periodo"
                            :opciones="[
                                { valor: 'mes', etiqueta: 'Mes de Reportes' },
                                { valor: 'trimestre', etiqueta: 'Trimestre' },
                            ]"
                        />
                        <SelectorPeriodo
                            v-if="tipo === 'mes'"
                            :meses="mesesSelector"
                            :mes-id="periodo?.meses_ids[0] ?? null"
                            :semanas="[]"
                            :semana-id="null"
                            :deshabilitado="cargando"
                            @cambiar="(mes: number) => elegirMes(mes)"
                        />
                        <section
                            v-else
                            class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-3 sm:p-4"
                            aria-label="Trimestre"
                        >
                            <div
                                class="flex items-center gap-2 text-xs font-medium text-muted-foreground"
                            >
                                <CalendarRange
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Trimestre (meses de Reportes por su código)
                            </div>
                            <div
                                class="scroll-x-limpio flex gap-2 pb-1"
                                role="radiogroup"
                                aria-label="Trimestre"
                            >
                                <button
                                    v-for="t in trimestres"
                                    :key="t.clave"
                                    type="button"
                                    role="radio"
                                    :aria-checked="t.clave === periodo?.clave"
                                    :disabled="cargando"
                                    class="flex min-w-36 shrink-0 cursor-pointer flex-col items-start rounded-xl border px-3 py-2 text-left transicion-ui disabled:cursor-wait"
                                    :class="
                                        t.clave === periodo?.clave
                                            ? 'border-primary bg-salvia shadow-sm'
                                            : 'border-border bg-card hover:border-primary/50 hover:bg-crema/60'
                                    "
                                    @click="
                                        t.clave !== periodo?.clave &&
                                        irA('trimestre', t.clave)
                                    "
                                >
                                    <span class="text-sm font-semibold">{{
                                        t.etiqueta
                                    }}</span>
                                    <span
                                        class="text-[11px]"
                                        :class="
                                            t.completo
                                                ? 'text-muted-foreground'
                                                : 'text-warning'
                                        "
                                    >
                                        {{ t.descripcion }} ·
                                        {{
                                            t.completo
                                                ? '3 meses'
                                                : `${t.meses.length} de 3 meses`
                                        }}
                                    </span>
                                </button>
                            </div>
                        </section>
                    </div>

                    <CambioSuave>
                        <EmptyState
                            v-if="!periodo || matriz.estado === 'sin_datos'"
                            key="sin-datos"
                            :icono="Grid3x3"
                            titulo="Reportes aún no tiene datos de este periodo"
                            descripcion="Cuando Reportes genere la radiografía del mes, aquí aparecerá la Nine Box."
                        />

                        <div v-else key="tablero" class="flex flex-col gap-5">
                            <section
                                data-tour="ninebox-kpis"
                                class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-5"
                            >
                                <TarjetaKpi
                                    etiqueta="En la matriz"
                                    :valor="matriz.resumen.ubicados"
                                    :icono="Grid3x3"
                                    tono="salvia"
                                    :nota="`de ${matriz.resumen.total} gestores · ${periodo.etiqueta}`"
                                />
                                <TarjetaKpi
                                    etiqueta="Estrellas"
                                    :valor="matriz.conteos.estrella ?? 0"
                                    :icono="Star"
                                    tono="menta"
                                    nota="Desempeño y potencial altos"
                                />
                                <TarjetaKpi
                                    etiqueta="Calibrados"
                                    :valor="matriz.resumen.calibrados"
                                    :icono="UserCheck"
                                    nota="Con potencial capturado por su jefe o RH"
                                />
                                <TarjetaKpi
                                    etiqueta="Por calibrar"
                                    :valor="matriz.resumen.sin_potencial"
                                    :icono="Sparkles"
                                    tono="crema"
                                    nota="Vinculados, sin Seguimientos ni calibración"
                                />
                                <TarjetaKpi
                                    class="col-span-2 lg:col-span-1"
                                    etiqueta="Sin vincular a PEOPLE"
                                    :valor="matriz.resumen.sin_vincular"
                                    :icono="UserX"
                                    nota="Solo tienen desempeño"
                                />
                            </section>

                            <!-- Barra de herramientas -->
                            <section
                                data-tour="ninebox-herramientas"
                                class="flex flex-wrap items-center gap-3 rounded-2xl border border-border bg-card p-3"
                            >
                                <div class="relative w-full sm:w-72">
                                    <Search
                                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        v-model="busqueda"
                                        class="pl-9"
                                        placeholder="Buscar gestor, sucursal o ruta…"
                                        aria-label="Buscar gestor"
                                    />
                                </div>
                                <ComboboxBuscable
                                    class="w-full sm:w-64"
                                    :model-value="filtros.sucursal"
                                    :opciones="matriz.opciones_sucursal"
                                    numerico
                                    limpiable
                                    clase-boton="bg-card"
                                    placeholder="Todas las sucursales"
                                    aria-label="Filtrar por sucursal"
                                    @update:model-value="cambiarSucursal"
                                />
                                <button
                                    v-if="seleccionado"
                                    type="button"
                                    class="inline-flex aparece-zoom cursor-pointer items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transicion-ui"
                                    :class="[
                                        ESTILO_CUADRANTE[seleccionado].fondo,
                                        ESTILO_CUADRANTE[seleccionado].acento,
                                    ]"
                                    @click="seleccionado = null"
                                >
                                    {{ cuadranteSeleccionado?.etiqueta }}
                                    <X class="size-3.5" />
                                </button>
                                <div class="ml-auto">
                                    <SelectorVista
                                        v-model="vista"
                                        etiqueta="Vista"
                                        :opciones="[
                                            {
                                                valor: 'matriz',
                                                etiqueta: 'Matriz',
                                            },
                                            {
                                                valor: 'tabla',
                                                etiqueta: 'Tabla',
                                            },
                                        ]"
                                    />
                                </div>
                            </section>

                            <CambioSuave>
                                <!-- Vista matriz -->
                                <section
                                    v-if="vista === 'matriz'"
                                    key="matriz"
                                    class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]"
                                >
                                    <div
                                        data-tour="ninebox-matriz"
                                        class="relative rounded-2xl border border-border bg-card p-4 transicion-ui"
                                        :class="cargando && 'opacity-70'"
                                        :aria-busy="cargando"
                                    >
                                        <div
                                            class="mb-3 flex flex-wrap items-baseline justify-between gap-2"
                                        >
                                            <h2 class="text-sm font-semibold">
                                                ↑ Potencial
                                                <span
                                                    class="font-normal text-muted-foreground"
                                                    >·
                                                    {{ periodo.etiqueta }}</span
                                                >
                                            </h2>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                Clic en una persona para ver su
                                                detalle y calibrar · clic en un
                                                cuadrante para filtrarlo
                                            </p>
                                        </div>
                                        <MatrizNineBox
                                            :personas="ubicadas"
                                            :cuadrantes="matriz.cuadrantes"
                                            :conteos="matriz.conteos"
                                            :seleccionado="seleccionado"
                                            :persona-activa="
                                                panelAbierto ? personaId : null
                                            "
                                            :coincidencias="coincidencias"
                                            @elegir-persona="abrirPersona"
                                            @elegir-cuadrante="
                                                (c) => (seleccionado = c)
                                            "
                                        />
                                    </div>

                                    <!-- Lateral: cuadrante elegido o distribución -->
                                    <aside class="flex flex-col gap-4">
                                        <CambioSuave>
                                            <div
                                                v-if="cuadranteSeleccionado"
                                                :key="
                                                    cuadranteSeleccionado.clave
                                                "
                                                class="flex flex-col gap-3 rounded-2xl border border-border p-4"
                                                :class="
                                                    ESTILO_CUADRANTE[
                                                        cuadranteSeleccionado
                                                            .clave
                                                    ].fondo
                                                "
                                            >
                                                <div
                                                    class="flex items-start justify-between gap-2"
                                                >
                                                    <div>
                                                        <p
                                                            class="text-lg font-semibold"
                                                            :class="
                                                                ESTILO_CUADRANTE[
                                                                    cuadranteSeleccionado
                                                                        .clave
                                                                ].acento
                                                            "
                                                        >
                                                            {{
                                                                cuadranteSeleccionado.etiqueta
                                                            }}
                                                        </p>
                                                        <p
                                                            class="text-xs text-muted-foreground"
                                                        >
                                                            {{
                                                                cuadranteSeleccionado.descripcion
                                                            }}
                                                        </p>
                                                    </div>
                                                    <span
                                                        class="text-3xl font-semibold tabular-nums"
                                                        :class="
                                                            ESTILO_CUADRANTE[
                                                                cuadranteSeleccionado
                                                                    .clave
                                                            ].acento
                                                        "
                                                    >
                                                        <NumeroAnimado
                                                            :valor="
                                                                personasDelCuadrante.length
                                                            "
                                                        />
                                                    </span>
                                                </div>
                                                <p
                                                    class="flex gap-2 rounded-xl bg-card/70 p-3 text-xs leading-relaxed"
                                                >
                                                    <Lightbulb
                                                        class="mt-0.5 size-4 shrink-0 text-oro"
                                                        aria-hidden="true"
                                                    />
                                                    {{
                                                        cuadranteSeleccionado.accion
                                                    }}
                                                </p>
                                                <ul
                                                    class="flex max-h-[26rem] entrada-escalonada flex-col gap-1 overflow-y-auto"
                                                >
                                                    <li
                                                        v-for="p in personasDelCuadrante"
                                                        :key="p.id"
                                                    >
                                                        <button
                                                            type="button"
                                                            class="group flex w-full cursor-pointer items-center gap-2.5 rounded-xl bg-card/60 p-2 text-left transicion-ui hover:bg-card hover:shadow-sm"
                                                            @click="
                                                                abrirPersona(p)
                                                            "
                                                        >
                                                            <ColaboradorAvatar
                                                                :nombre="
                                                                    p.nombre
                                                                "
                                                                :foto-url="
                                                                    p.foto_url
                                                                "
                                                                tamano="xs"
                                                            />
                                                            <span
                                                                class="min-w-0 flex-1"
                                                            >
                                                                <span
                                                                    class="block truncate text-sm font-medium"
                                                                    >{{
                                                                        p.nombre
                                                                    }}</span
                                                                >
                                                                <span
                                                                    class="block truncate text-[11px] text-muted-foreground"
                                                                    >{{
                                                                        p.sucursal ??
                                                                        p.branch
                                                                    }}</span
                                                                >
                                                            </span>
                                                            <span
                                                                class="text-[11px] text-muted-foreground tabular-nums"
                                                            >
                                                                D
                                                                {{
                                                                    Math.round(
                                                                        p
                                                                            .desempeno
                                                                            .puntaje ??
                                                                            0,
                                                                    )
                                                                }}
                                                                · P
                                                                {{
                                                                    Math.round(
                                                                        p
                                                                            .potencial
                                                                            .puntaje ??
                                                                            0,
                                                                    )
                                                                }}
                                                            </span>
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>

                                            <div
                                                v-else
                                                key="distribucion"
                                                class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4"
                                            >
                                                <p
                                                    class="text-sm font-semibold"
                                                >
                                                    Distribución
                                                </p>
                                                <ul class="flex flex-col gap-2">
                                                    <li
                                                        v-for="c in distribucion"
                                                        :key="c.clave"
                                                    >
                                                        <button
                                                            type="button"
                                                            class="flex w-full cursor-pointer flex-col gap-1 rounded-lg p-1 text-left transicion-ui hover:bg-crema/50"
                                                            @click="
                                                                seleccionado =
                                                                    c.clave
                                                            "
                                                        >
                                                            <span
                                                                class="flex items-center justify-between gap-2 text-xs"
                                                            >
                                                                <span
                                                                    class="flex items-center gap-2"
                                                                >
                                                                    <span
                                                                        class="size-2.5 rounded-full"
                                                                        :class="
                                                                            ESTILO_CUADRANTE[
                                                                                c
                                                                                    .clave
                                                                            ]
                                                                                .punto
                                                                        "
                                                                    />
                                                                    {{
                                                                        c.etiqueta
                                                                    }}
                                                                </span>
                                                                <span
                                                                    class="text-muted-foreground tabular-nums"
                                                                    >{{
                                                                        c.cantidad
                                                                    }}
                                                                    ·
                                                                    {{
                                                                        c.porcentaje
                                                                    }}
                                                                    %</span
                                                                >
                                                            </span>
                                                            <span
                                                                class="h-1.5 overflow-hidden rounded-full bg-muted"
                                                            >
                                                                <span
                                                                    class="block h-full rounded-full transition-[width] duration-700 ease-out"
                                                                    :class="
                                                                        ESTILO_CUADRANTE[
                                                                            c
                                                                                .clave
                                                                        ].punto
                                                                    "
                                                                    :style="{
                                                                        width: `${c.porcentaje}%`,
                                                                    }"
                                                                />
                                                            </span>
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </CambioSuave>

                                        <!-- Fuera de la matriz -->
                                        <div
                                            v-if="fueraDeMatriz.length"
                                            class="flex flex-col gap-2 rounded-2xl border border-dashed border-border bg-crema/40 p-4"
                                        >
                                            <p class="text-sm font-semibold">
                                                Fuera de la matriz ({{
                                                    fueraDeMatriz.length
                                                }})
                                            </p>
                                            <p
                                                class="text-[11px] text-muted-foreground"
                                            >
                                                Por calibrar: vinculados sin
                                                Seguimientos ni calibración. Sin
                                                vincular: aún no tienen
                                                colaborador en PEOPLE (solo
                                                desempeño).
                                            </p>
                                            <div
                                                class="flex max-h-48 flex-wrap gap-1.5 overflow-y-auto pt-1"
                                            >
                                                <button
                                                    v-for="p in fueraDeMatriz"
                                                    :key="p.id"
                                                    type="button"
                                                    class="group cursor-pointer rounded-full transicion-ui hover:-translate-y-0.5"
                                                    :class="
                                                        coincidencias &&
                                                        !coincidencias.has(
                                                            p.id,
                                                        ) &&
                                                        'opacity-25'
                                                    "
                                                    :title="`${p.nombre} · ${p.estado === 'sin_vincular' ? 'Sin vincular' : 'Por calibrar'}`"
                                                    @click="abrirPersona(p)"
                                                >
                                                    <ColaboradorAvatar
                                                        :nombre="p.nombre"
                                                        :foto-url="p.foto_url"
                                                        tamano="xs"
                                                        :class="
                                                            cn(
                                                                'ring-2',
                                                                p.estado ===
                                                                    'sin_vincular'
                                                                    ? 'opacity-80 ring-border'
                                                                    : 'ring-oro',
                                                            )
                                                        "
                                                    />
                                                </button>
                                            </div>
                                        </div>
                                    </aside>
                                </section>

                                <!-- Vista tabla -->
                                <section
                                    v-else
                                    key="tabla"
                                    class="rounded-2xl border border-border bg-card p-4"
                                >
                                    <EmptyState
                                        v-if="filasTabla.length === 0"
                                        :icono="Users"
                                        titulo="Sin gestores para mostrar"
                                        descripcion="Ninguna persona de tu alcance coincide con los filtros."
                                    />
                                    <TablaNineBox
                                        v-else
                                        :personas="filasTabla"
                                        :cuadrantes="matriz.cuadrantes"
                                        @elegir-persona="abrirPersona"
                                    />
                                </section>
                            </CambioSuave>

                            <p class="flex gap-2 text-xs text-muted-foreground">
                                <Info
                                    class="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>
                                    Desempeño: colocación, recuperación y mora
                                    de Reportes (sin meta configurada, percentil
                                    contra todos los gestores de la empresa) con
                                    cortes en
                                    {{
                                        matriz.configuracion.cortes.desempeno
                                            .medio
                                    }}
                                    y
                                    {{
                                        matriz.configuracion.cortes.desempeno
                                            .alto
                                    }}. Potencial: promedio de Seguimientos
                                    técnicos de los últimos
                                    {{
                                        matriz.configuracion.potencial
                                            .ventana_evaluaciones_meses
                                    }}
                                    meses y la calibración vigente, con cortes
                                    en
                                    {{
                                        matriz.configuracion.cortes.potencial
                                            .medio
                                    }}
                                    y
                                    {{
                                        matriz.configuracion.cortes.potencial
                                            .alto
                                    }}.
                                </span>
                            </p>
                        </div>
                    </CambioSuave>
                </template>
            </Deferred>
        </div>

        <PanelPersonaNineBox
            v-model:abierto="panelAbierto"
            :persona="persona"
            :cuadrante="cuadrantePersona"
            :periodo="periodo"
            :niveles="niveles"
        />
        <ConfiguracionNineBoxDialogo
            v-if="configuracion"
            v-model:abierto="configAbierta"
            :configuracion="configuracion"
        />
    </div>
</template>
