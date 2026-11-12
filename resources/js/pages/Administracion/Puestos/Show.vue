<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Banknote,
    Building2,
    Check,
    CheckCheck,
    Download,
    History,
    Loader2,
    Minus,
    Network,
    Pencil,
    Search,
    Shuffle,
    Timer,
    Trash2,
    TrendingDown,
    TrendingUp,
    Users,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import BotonCorregir from '@/components/correcciones/BotonCorregir.vue';
import DialogoUbicarPuesto from '@/components/Organigrama/DialogoUbicarPuesto.vue';
import BarraTabulador from '@/components/puestos/BarraTabulador.vue';
import PlantillaAutorizadaPuesto from '@/components/puestos/PlantillaAutorizadaPuesto.vue';
import PuestoDatosFormulario from '@/components/puestos/PuestoDatosFormulario.vue';
import TabuladorEditor from '@/components/puestos/TabuladorEditor.vue';
import { Button } from '@/components/ui/button';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import { useAlertas } from '@/composables/useAlertas';
import { getInitials } from '@/composables/useInitials';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    destroy,
    index,
    moverColaboradores,
} from '@/routes/administracion/puestos';
import { excel, pdf } from '@/routes/salarios/exportar';
import type {
    BandaSalarial,
    DestinoPuesto,
    OpcionSimple,
    PersonaPuesto,
    PlantillaPuesto,
    PuestoDetalle,
    PuestoSinUbicar,
    RangoTabulador,
    RangoTabuladorVigente,
    ResultadoMovimientoPuesto,
    VersionTabulador,
} from '@/types';

/**
 * Ficha de un puesto, todo en un lugar:
 *  - Personas: quién lo ocupa hoy (lista completa, buscador sin mínimo) y
 *    traslado en lote a otro puesto, con el efecto en el sueldo de cada
 *    quien frente al tabulador del destino antes de confirmar.
 *  - Tabulador: mínimo / medio / máximo, posición de cada persona,
 *    versiones y captura (salarios.*).
 *  - Datos del puesto: catálogo (puestos.editar).
 * El backend revalida permisos, alcance y «nadie se mueve a sí mismo».
 */
const props = defineProps<{
    puesto: PuestoDetalle;
    personas: PersonaPuesto[];
    destinos: DestinoPuesto[];
    rango: RangoTabuladorVigente | null;
    versiones: VersionTabulador[];
    departamentosDisponibles: OpcionSimple[];
    /** Plazas autorizadas por ubicación (Headcount). */
    plantilla: PlantillaPuesto;
    /** Presente solo si el puesto no cuelga de Dirección General. */
    sinUbicar: PuestoSinUbicar | null;
    /** Recién creado: abre «¿Debajo de quién va?» al entrar. */
    abrirUbicacion: boolean;
    puede: {
        ubicar: boolean;
        editar_plantilla: boolean;
        editar: boolean;
        eliminar: boolean;
        mover: boolean;
        ver_salarios: boolean;
        editar_salarios: boolean;
        exportar_salarios: boolean;
    };
}>();

defineOptions({
    layout: (pageProps: { puesto: { nombre: string } }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Puestos y salarios', href: index.url() },
            { title: pageProps.puesto.nombre, href: '#' },
        ],
    }),
});

const numero = new Intl.NumberFormat('es-MX');

// Recién creado (o llegado con ?ubicar=1) y todavía fuera del árbol: se
// pregunta de inmediato debajo de quién va.
const ubicacionAbierta = ref(
    props.abrirUbicacion && props.sinUbicar !== null && props.puede.ubicar,
);
const { confirmarEliminacion, mostrarError } = useAlertas();

function personasTexto(n: number): string {
    return `${numero.format(n)} ${n === 1 ? 'persona' : 'personas'}`;
}

function normalizar(texto: string | null | undefined): string {
    return (texto ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

const pct = (valor: number | null | undefined): string =>
    valor === null || valor === undefined ? '—' : `${Math.round(valor)}%`;

const CLASE_BANDA: Record<BandaSalarial, string> = {
    bajo_minimo: 'bg-danger-soft text-destructive',
    minimo_a_medio: 'bg-info-soft text-info',
    medio_a_maximo: 'bg-success-soft text-success',
    sobre_maximo: 'bg-warning-soft text-warning',
};

// --- Secciones ---------------------------------------------------------------

type Seccion = 'personas' | 'tabulador' | 'datos';
const seccion = ref<Seccion>('personas');

onMounted(() => {
    const pedida = new URLSearchParams(window.location.search).get('seccion');

    if (pedida === 'tabulador' || pedida === 'datos') {
        seccion.value = pedida;
    }

    if (pedida === 'tabulador' && !props.rango && props.puede.editar_salarios) {
        editandoTabulador.value = true;
    }
});

const opcionesSeccion = computed(() => {
    const lista: { valor: Seccion; etiqueta: string }[] = [
        { valor: 'personas', etiqueta: `Personas (${props.personas.length})` },
    ];

    if (props.puede.ver_salarios) {
        lista.push({ valor: 'tabulador', etiqueta: 'Tabulador y sueldos' });
    }

    lista.push({ valor: 'datos', etiqueta: 'Datos del puesto' });

    return lista;
});

// --- Personas: búsqueda y selección -----------------------------------------

const busqueda = ref('');
const seleccion = ref<Set<number>>(new Set());

const filtradas = computed(() => {
    const termino = normalizar(busqueda.value);

    return termino === ''
        ? props.personas
        : props.personas.filter((p) =>
              normalizar(
                  `${p.nombre} ${p.sucursal ?? ''} ${p.departamento ?? ''} ${p.numero_empleado ?? ''}`,
              ).includes(termino),
          );
});

const movibles = computed(() => filtradas.value.filter((p) => p.movible));
const seleccionadas = computed(() =>
    props.personas.filter((p) => seleccion.value.has(p.id)),
);
const totalSeleccionadas = computed(() => seleccionadas.value.length);

const estadoTodos = computed<'ninguno' | 'parcial' | 'todos'>(() => {
    const marcadas = movibles.value.filter((p) =>
        seleccion.value.has(p.id),
    ).length;

    if (marcadas === 0) {
        return 'ninguno';
    }

    return marcadas === movibles.value.length ? 'todos' : 'parcial';
});

function alternar(persona: PersonaPuesto): void {
    if (!props.puede.mover || !persona.movible) {
        return;
    }

    const nueva = new Set(seleccion.value);

    if (nueva.has(persona.id)) {
        nueva.delete(persona.id);
    } else {
        nueva.add(persona.id);
    }

    seleccion.value = nueva;
}

function alternarTodas(): void {
    const nueva = new Set(seleccion.value);

    if (estadoTodos.value === 'todos') {
        movibles.value.forEach((p) => nueva.delete(p.id));
    } else {
        movibles.value.forEach((p) => nueva.add(p.id));
    }

    seleccion.value = nueva;
}

function limpiarSeleccion(): void {
    seleccion.value = new Set();
}

watch(
    () => props.personas,
    (lista) => {
        const ids = new Set(lista.map((p) => p.id));
        seleccion.value = new Set(
            [...seleccion.value].filter((id) => ids.has(id)),
        );
    },
);

// --- Destino -----------------------------------------------------------------

const destinoId = ref<number | null>(null);
const busquedaDestino = ref('');
const destino = computed(
    () => props.destinos.find((d) => d.id === destinoId.value) ?? null,
);
const destinosFiltrados = computed(() => {
    const termino = normalizar(busquedaDestino.value);

    return termino === ''
        ? props.destinos
        : props.destinos.filter((d) =>
              normalizar(`${d.nombre} ${d.departamento ?? ''}`).includes(
                  termino,
              ),
          );
});

const motivo = ref('');
const listo = computed(
    () => totalSeleccionadas.value > 0 && destino.value !== null,
);

/** Cómo queda el sueldo de cada quien frente al tabulador del destino. */
type Efecto = { tono: string; texto: string; icono: typeof TrendingUp | null };

function efecto(
    persona: PersonaPuesto,
    rango: RangoTabulador | null,
): Efecto | null {
    if (!props.puede.ver_salarios) {
        return null;
    }

    if (rango === null) {
        return {
            tono: 'bg-muted text-muted-foreground',
            texto: 'Destino sin tabulador',
            icono: null,
        };
    }

    if (persona.sueldo === null || persona.sueldo === undefined) {
        return {
            tono: 'bg-muted text-muted-foreground',
            texto: 'Sin sueldo capturado',
            icono: null,
        };
    }

    if (persona.sueldo < rango.minimo) {
        return {
            tono: 'bg-danger-soft text-destructive',
            texto: `Bajo el mínimo · faltan ${formatoMoneda(rango.minimo - persona.sueldo)}`,
            icono: TrendingDown,
        };
    }

    if (persona.sueldo > rango.maximo) {
        return {
            tono: 'bg-warning-soft text-warning',
            texto: `Sobre el máximo · +${formatoMoneda(persona.sueldo - rango.maximo)}`,
            icono: TrendingUp,
        };
    }

    return {
        tono: 'bg-success-soft text-success',
        texto: `Dentro del rango · ${pct((persona.sueldo / rango.medio) * 100)} del medio`,
        icono: Check,
    };
}

const conteoEfectos = computed(() => {
    const rango = destino.value?.rango ?? null;

    if (!props.puede.ver_salarios || rango === null) {
        return null;
    }

    const conSueldo = seleccionadas.value.filter(
        (p) => p.sueldo !== null && p.sueldo !== undefined,
    );

    return {
        dentro: conSueldo.filter(
            (p) => p.sueldo! >= rango.minimo && p.sueldo! <= rango.maximo,
        ).length,
        bajo: conSueldo.filter((p) => p.sueldo! < rango.minimo).length,
        sobre: conSueldo.filter((p) => p.sueldo! > rango.maximo).length,
    };
});

// --- Confirmación y envío ----------------------------------------------------

const confirmando = ref(false);
const enviando = ref(false);
const errores = ref<Record<string, string>>({});
const resultado = ref<ResultadoMovimientoPuesto | null>(null);
const botonConfirmar = ref<HTMLButtonElement | null>(null);
const panelDestino = ref<HTMLElement | null>(null);
const panelResultado = ref<HTMLElement | null>(null);

watch(resultado, (valor) => {
    if (valor) {
        nextTick(() =>
            panelResultado.value?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            }),
        );
    }
});

function continuar(): void {
    if (!listo.value) {
        panelDestino.value?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });

        return;
    }

    errores.value = {};
    confirmando.value = true;
    nextTick(() => botonConfirmar.value?.focus());
}

function cerrarConfirmacion(): void {
    if (!enviando.value) {
        confirmando.value = false;
    }
}

function alTeclear(evento: KeyboardEvent): void {
    if (evento.key === 'Escape' && confirmando.value) {
        cerrarConfirmacion();
    }
}

watch(confirmando, (abierto) => {
    if (abierto) {
        window.addEventListener('keydown', alTeclear);
    } else {
        window.removeEventListener('keydown', alTeclear);
    }
});

onBeforeUnmount(() => window.removeEventListener('keydown', alTeclear));

function confirmar(): void {
    if (!destino.value || totalSeleccionadas.value === 0) {
        return;
    }

    enviando.value = true;

    router.post(
        moverColaboradores.url(props.puesto.id),
        {
            puesto_destino_id: destino.value.id,
            colaborador_ids: [...seleccion.value],
            motivo: motivo.value.trim() || null,
        },
        {
            preserveScroll: true,
            onFlash: (flash) => {
                const dato = (flash as Record<string, unknown>)
                    .movimientoPuesto as ResultadoMovimientoPuesto | undefined;

                if (dato) {
                    resultado.value = dato;
                }
            },
            onSuccess: () => {
                confirmando.value = false;
                limpiarSeleccion();
                destinoId.value = null;
                motivo.value = '';
            },
            onError: (e) => {
                errores.value = e;
                confirmando.value = false;
            },
            onFinish: () => {
                enviando.value = false;
            },
        },
    );
}

const avataresSeleccion = computed(() => seleccionadas.value.slice(0, 5));

// --- Tabulador ---------------------------------------------------------------

const editandoTabulador = ref(false);
const verVersiones = ref(false);
const consultaExportar = computed(() => ({
    query: { vista: 'posiciones', puesto: props.puesto.id },
}));

const resumenSueldos = computed(() => {
    const conSueldo = props.personas.filter(
        (p) => p.sueldo !== null && p.sueldo !== undefined,
    );
    const compa = conSueldo
        .map((p) => p.compa_ratio)
        .filter((c): c is number => c !== null && c !== undefined);

    return {
        conSueldo: conSueldo.length,
        compaPromedio: compa.length
            ? compa.reduce((a, b) => a + b, 0) / compa.length
            : null,
        bajo: props.personas.filter((p) => p.banda === 'bajo_minimo').length,
        sobre: props.personas.filter((p) => p.banda === 'sobre_maximo').length,
    };
});

// --- Eliminar ----------------------------------------------------------------

async function eliminar(): Promise<void> {
    if (await confirmarEliminacion(`el puesto «${props.puesto.nombre}»`)) {
        // Si el puesto sigue en la estructura, el servidor explica por qué.
        router.delete(destroy.url(props.puesto.id), {
            onError: (errores) =>
                mostrarError(
                    errores.puesto ?? 'No fue posible eliminar el puesto.',
                    'No se eliminó el puesto',
                ),
        });
    }
}
</script>

<template>
    <Head :title="puesto.nombre" />

    <div
        class="pagina-ancha flex flex-col gap-5"
        :class="puede.mover && totalSeleccionadas > 0 && 'pb-28 lg:pb-24'"
    >
        <!-- Encabezado -->
        <header
            class="relative overflow-hidden rounded-3xl border bg-card px-5 py-5 sm:px-7 sm:py-6"
        >
            <div
                class="pointer-events-none absolute -top-16 -right-10 size-56 rounded-full bg-salvia/60 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -bottom-20 left-1/3 size-48 rounded-full bg-crema/70 blur-3xl"
                aria-hidden="true"
            />

            <div class="relative flex flex-col gap-3">
                <Link
                    :href="index.url()"
                    class="-ml-2 inline-flex w-fit items-center gap-1.5 rounded-full px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-crema/60 hover:text-foreground"
                >
                    <ArrowLeft class="size-3.5" /> Puestos y salarios
                </Link>

                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="min-w-0">
                        <h1
                            class="text-2xl font-semibold tracking-tight sm:text-3xl"
                        >
                            {{ puesto.nombre }}
                        </h1>
                        <p
                            v-if="puesto.descripcion"
                            class="mt-1 max-w-prose text-sm text-muted-foreground"
                        >
                            {{ puesto.descripcion }}
                        </p>
                        <ul class="mt-3 flex flex-wrap gap-2 text-xs">
                            <li
                                class="inline-flex items-center gap-1.5 rounded-full border bg-background/70 px-3 py-1.5 font-medium"
                            >
                                <Users class="size-3.5 text-primary" />
                                {{ personasTexto(personas.length) }}
                            </li>
                            <li
                                class="inline-flex items-center gap-1.5 rounded-full border bg-background/70 px-3 py-1.5 font-medium"
                            >
                                <Building2 class="size-3.5 text-primary" />
                                {{ puesto.departamento ?? 'Sin departamento' }}
                            </li>
                            <li
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 font-medium"
                                :class="
                                    puesto.meses_periodo_prueba
                                        ? 'border bg-background/70'
                                        : 'bg-warning-soft text-warning'
                                "
                            >
                                <Timer class="size-3.5" />
                                {{
                                    puesto.meses_periodo_prueba
                                        ? `Prueba: ${puesto.meses_periodo_prueba} ${puesto.meses_periodo_prueba === 1 ? 'mes' : 'meses'}`
                                        : 'Periodo de prueba sin configurar'
                                }}
                            </li>
                            <li
                                class="inline-flex items-center rounded-full px-3 py-1.5 font-medium"
                                :class="
                                    puesto.activo
                                        ? 'bg-success-soft text-success'
                                        : 'bg-danger-soft text-danger'
                                "
                            >
                                {{ puesto.activo ? 'Activo' : 'Inactivo' }}
                            </li>
                        </ul>
                    </div>

                    <!-- Rango del tabulador de un vistazo -->
                    <button
                        v-if="puede.ver_salarios"
                        type="button"
                        class="flex tarjeta-interactiva items-stretch gap-0 overflow-hidden rounded-2xl border bg-background/70 text-left"
                        @click="seccion = 'tabulador'"
                    >
                        <template v-if="rango">
                            <span
                                v-for="(valor, etiqueta) in {
                                    Mínimo: rango.minimo,
                                    Medio: rango.medio,
                                    Máximo: rango.maximo,
                                }"
                                :key="etiqueta"
                                class="flex flex-col px-4 py-2.5"
                                :class="etiqueta === 'Medio' && 'bg-menta'"
                            >
                                <span
                                    class="text-[11px] text-muted-foreground"
                                    >{{ etiqueta }}</span
                                >
                                <span
                                    class="font-semibold tabular-nums"
                                    :class="
                                        etiqueta === 'Medio'
                                            ? 'text-lg text-primary'
                                            : 'text-sm'
                                    "
                                    >{{ formatoMoneda(valor) }}</span
                                >
                            </span>
                        </template>
                        <span
                            v-else
                            class="flex items-center gap-2 px-4 py-3 text-sm font-medium text-warning"
                        >
                            <Banknote class="size-4" /> Tabulador sin capturar
                            <ArrowRight class="size-4" />
                        </span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Puesto fuera del árbol: sus plazas y vacantes no se ven en el organigrama hasta ubicarlo. -->
        <section
            v-if="sinUbicar"
            class="flex flex-col gap-3 rounded-2xl border border-warning/30 bg-warning-soft p-4 sm:flex-row sm:items-center"
        >
            <span
                class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-card text-warning"
            >
                <Network class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">
                    Este puesto aún no está en el organigrama
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ sinUbicar.motivo_etiqueta }}. Indica debajo de quién va
                    para que sus personas, plazas y vacantes aparezcan en el
                    árbol y se calcule el jefe directo.
                </p>
            </div>
            <Button v-if="puede.ubicar" @click="ubicacionAbierta = true">
                <Network class="size-4" /> ¿Debajo de quién va?
            </Button>
        </section>
        <DialogoUbicarPuesto
            v-if="puede.ubicar"
            v-model:open="ubicacionAbierta"
            :puesto="{ id: puesto.id, nombre: puesto.nombre }"
        />

        <!-- Plazas autorizadas por ubicación (Corporativo incluido) -->
        <PlantillaAutorizadaPuesto
            :puesto-id="puesto.id"
            :puesto-nombre="puesto.nombre"
            :plantilla="plantilla"
            :puede-editar="puede.editar_plantilla"
        />

        <SelectorVista
            v-model="seccion"
            etiqueta="Sección del puesto"
            :opciones="opcionesSeccion"
        />

        <!-- ════════════ Personas y traslados ════════════ -->
        <template v-if="seccion === 'personas'">
            <!-- Resultado del último traslado -->
            <Transition name="aparecer">
                <section
                    v-if="resultado"
                    ref="panelResultado"
                    class="scroll-mt-4 rounded-2xl border bg-card p-4 sm:p-5"
                    aria-live="polite"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-full"
                            :class="
                                resultado.movidos.length > 0
                                    ? 'bg-success-soft text-success'
                                    : 'bg-warning-soft text-warning'
                            "
                        >
                            <CheckCheck
                                v-if="resultado.movidos.length > 0"
                                class="size-4.5"
                            />
                            <AlertTriangle v-else class="size-4.5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">
                                <template v-if="resultado.movidos.length > 0">
                                    {{
                                        personasTexto(resultado.movidos.length)
                                    }}
                                    {{
                                        resultado.movidos.length === 1
                                            ? 'ahora es'
                                            : 'ahora son'
                                    }}
                                    {{ resultado.destino }}
                                </template>
                                <template v-else>Nadie se trasladó</template>
                            </p>
                            <ul
                                v-if="resultado.movidos.length > 0"
                                class="mt-2 flex flex-wrap gap-1.5"
                            >
                                <li
                                    v-for="m in resultado.movidos"
                                    :key="m.id"
                                    class="rounded-full bg-menta px-2.5 py-1 text-xs"
                                >
                                    {{ m.nombre }}
                                </li>
                            </ul>
                            <div
                                v-if="resultado.omitidos.length > 0"
                                class="mt-3 rounded-xl bg-warning-soft/60 px-3 py-2.5"
                            >
                                <p class="text-xs font-medium">
                                    {{ resultado.omitidos.length }}
                                    {{
                                        resultado.omitidos.length === 1
                                            ? 'persona omitida'
                                            : 'personas omitidas'
                                    }}
                                </p>
                                <ul class="mt-1 space-y-0.5 text-xs">
                                    <li
                                        v-for="o in resultado.omitidos"
                                        :key="o.id"
                                    >
                                        <span class="font-medium">{{
                                            o.nombre
                                        }}</span>
                                        <span class="text-muted-foreground">
                                            — {{ o.motivo }}</span
                                        >
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="grid size-8 shrink-0 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-crema/60 hover:text-foreground"
                            aria-label="Cerrar resultado"
                            @click="resultado = null"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                </section>
            </Transition>

            <div
                class="grid items-start gap-5"
                :class="
                    puede.mover &&
                    'lg:grid-cols-[minmax(0,1fr)_minmax(320px,400px)]'
                "
            >
                <!-- Origen -->
                <section
                    class="flex min-w-0 flex-col gap-3 rounded-3xl border bg-card p-4 sm:p-5"
                    aria-labelledby="titulo-origen"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                v-if="puede.mover"
                                class="grid size-6 place-items-center rounded-full bg-primary text-[11px] font-semibold text-primary-foreground"
                                >1</span
                            >
                            <h2 id="titulo-origen" class="font-semibold">
                                {{
                                    puede.mover
                                        ? '¿A quiénes vas a trasladar?'
                                        : `Quiénes son ${puesto.nombre}`
                                }}
                            </h2>
                        </div>
                        <p
                            v-if="puede.mover"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{ totalSeleccionadas }} de
                            {{ personas.filter((p) => p.movible).length }}
                            seleccionadas
                        </p>
                    </div>

                    <div
                        v-if="personas.length > 0"
                        class="flex flex-col gap-2 sm:flex-row sm:items-center"
                    >
                        <label class="relative flex-1">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <span class="sr-only">Buscar persona</span>
                            <input
                                v-model="busqueda"
                                type="search"
                                placeholder="Buscar por nombre, sucursal, departamento o número…"
                                class="h-11 w-full rounded-2xl border bg-background/60 pr-10 pl-10 text-sm transition-[border-color,box-shadow] outline-none placeholder:text-muted-foreground focus:border-primary/50 focus:ring-4 focus:ring-primary/10"
                            />
                            <button
                                v-if="busqueda"
                                type="button"
                                class="absolute top-1/2 right-2 grid size-7 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-crema/60"
                                aria-label="Limpiar búsqueda"
                                @click="busqueda = ''"
                            >
                                <X class="size-3.5" />
                            </button>
                        </label>

                        <button
                            v-if="puede.mover && movibles.length > 0"
                            type="button"
                            role="checkbox"
                            :aria-checked="
                                estadoTodos === 'todos'
                                    ? 'true'
                                    : estadoTodos === 'parcial'
                                      ? 'mixed'
                                      : 'false'
                            "
                            class="inline-flex h-11 shrink-0 items-center gap-2 rounded-2xl border px-4 text-sm font-medium transition-colors"
                            :class="
                                estadoTodos === 'ninguno'
                                    ? 'bg-background/60 hover:bg-crema/50'
                                    : 'border-primary/40 bg-menta text-primary'
                            "
                            @click="alternarTodas"
                        >
                            <span
                                class="grid size-5 place-items-center rounded-md border-2 transition-colors"
                                :class="
                                    estadoTodos === 'ninguno'
                                        ? 'border-muted-foreground/40'
                                        : 'border-primary bg-primary text-primary-foreground'
                                "
                            >
                                <Check
                                    v-if="estadoTodos === 'todos'"
                                    class="size-3.5"
                                    :stroke-width="3"
                                />
                                <Minus
                                    v-else-if="estadoTodos === 'parcial'"
                                    class="size-3.5"
                                    :stroke-width="3"
                                />
                            </span>
                            {{
                                busqueda
                                    ? `Seleccionar las ${movibles.length} encontradas`
                                    : 'Seleccionar todas'
                            }}
                        </button>
                    </div>

                    <EmptyState
                        v-if="personas.length === 0"
                        :icono="Users"
                        :titulo="`Nadie es ${puesto.nombre} hoy`"
                        descripcion="Aquí aparecen las personas activas o en incorporación con este puesto dentro de tu alcance."
                    />
                    <p
                        v-else-if="filtradas.length === 0"
                        class="rounded-2xl bg-crema/30 px-4 py-8 text-center text-sm text-muted-foreground"
                    >
                        Nadie coincide con «{{ busqueda }}».
                    </p>

                    <ListaAnimada
                        v-else
                        tag="ul"
                        :cantidad="filtradas.length"
                        class="grid gap-2.5 sm:grid-cols-2 2xl:grid-cols-3"
                        :aria-label="`Personas con el puesto ${puesto.nombre}`"
                    >
                        <li
                            v-for="(p, i) in filtradas"
                            :key="p.id"
                            class="entrada-persona"
                            :style="{
                                animationDelay: `${Math.min(i, 14) * 22}ms`,
                            }"
                        >
                            <component
                                :is="puede.mover ? 'button' : 'div'"
                                :type="puede.mover ? 'button' : undefined"
                                :role="puede.mover ? 'checkbox' : undefined"
                                :aria-checked="
                                    puede.mover
                                        ? seleccion.has(p.id)
                                            ? 'true'
                                            : 'false'
                                        : undefined
                                "
                                :aria-disabled="
                                    puede.mover && !p.movible
                                        ? 'true'
                                        : undefined
                                "
                                :title="p.motivo_no_movible ?? undefined"
                                class="tarjeta-persona group relative flex w-full items-center gap-3 rounded-2xl border p-3 text-left"
                                :class="[
                                    seleccion.has(p.id)
                                        ? 'seleccionada border-primary/50 bg-menta'
                                        : 'bg-background/40',
                                    puede.mover && p.movible
                                        ? 'cursor-pointer'
                                        : '',
                                    puede.mover &&
                                    p.movible &&
                                    !seleccion.has(p.id)
                                        ? 'hover:border-oro/60 hover:bg-crema/30'
                                        : '',
                                    puede.mover && !p.movible
                                        ? 'cursor-not-allowed opacity-60'
                                        : '',
                                ]"
                                @click="alternar(p)"
                            >
                                <span class="relative shrink-0">
                                    <img
                                        v-if="p.foto_url"
                                        :src="p.foto_url"
                                        alt=""
                                        class="size-11 rounded-full object-cover ring-2 ring-card"
                                        loading="lazy"
                                    />
                                    <span
                                        v-else
                                        class="grid size-11 place-items-center rounded-full bg-salvia text-sm font-semibold text-primary ring-2 ring-card"
                                        >{{ getInitials(p.nombre) }}</span
                                    >
                                    <span
                                        v-if="puede.mover && p.movible"
                                        class="marca absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full border-2 border-card"
                                        :class="
                                            seleccion.has(p.id)
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-transparent group-hover:bg-crema-2'
                                        "
                                    >
                                        <Check
                                            class="size-3"
                                            :stroke-width="3.5"
                                        />
                                    </span>
                                </span>

                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-medium"
                                        >{{ p.nombre }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                    >
                                        {{ p.sucursal ?? 'Sin sucursal' }}
                                        <template v-if="p.departamento">
                                            · {{ p.departamento }}</template
                                        >
                                    </span>
                                    <span class="mt-1 flex flex-wrap gap-1">
                                        <span
                                            v-if="p.numero_empleado"
                                            class="rounded-md bg-crema/60 px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground tabular-nums"
                                            >#{{ p.numero_empleado }}</span
                                        >
                                        <span
                                            v-if="p.en_incorporacion"
                                            class="rounded-md bg-info-soft px-1.5 py-0.5 text-[10px] font-medium"
                                            >En incorporación</span
                                        >
                                        <span
                                            v-if="p.banda"
                                            class="rounded-md px-1.5 py-0.5 text-[10px] font-medium"
                                            :class="CLASE_BANDA[p.banda]"
                                            >{{ p.banda_etiqueta }}</span
                                        >
                                        <span
                                            v-if="puede.mover && !p.movible"
                                            class="rounded-md bg-warning-soft px-1.5 py-0.5 text-[10px] font-medium"
                                            >Eres tú</span
                                        >
                                    </span>
                                </span>

                                <span
                                    v-if="puede.ver_salarios"
                                    class="shrink-0 text-right"
                                >
                                    <span
                                        class="block text-sm font-semibold tabular-nums"
                                        >{{
                                            p.sueldo == null
                                                ? '—'
                                                : formatoMoneda(p.sueldo)
                                        }}</span
                                    >
                                    <span
                                        class="block text-[11px] text-muted-foreground tabular-nums"
                                        >{{
                                            p.compa_ratio == null
                                                ? 'sin sueldo'
                                                : `${pct(p.compa_ratio)} del medio`
                                        }}</span
                                    >
                                </span>
                            </component>
                        </li>
                    </ListaAnimada>

                    <p
                        v-if="!puede.mover && personas.length > 0"
                        class="text-xs text-muted-foreground"
                    >
                        Para trasladar personas a otro puesto se necesita editar
                        el catálogo de puestos y los expedientes (RH).
                    </p>
                </section>

                <!-- Destino y resumen -->
                <aside
                    v-if="puede.mover"
                    ref="panelDestino"
                    class="flex scroll-mt-4 flex-col gap-4 lg:sticky lg:top-4"
                    aria-labelledby="titulo-destino"
                >
                    <section class="rounded-3xl border bg-card p-4 sm:p-5">
                        <div class="flex items-center gap-2">
                            <span
                                class="grid size-6 place-items-center rounded-full text-[11px] font-semibold transition-colors"
                                :class="
                                    totalSeleccionadas > 0
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground'
                                "
                                >2</span
                            >
                            <h2 id="titulo-destino" class="font-semibold">
                                ¿A qué puesto?
                            </h2>
                        </div>

                        <p
                            v-if="destinos.length === 0"
                            class="mt-3 rounded-2xl bg-crema/30 px-4 py-6 text-center text-sm text-muted-foreground"
                        >
                            No hay otro puesto activo. Crea uno en Puestos y
                            salarios.
                        </p>

                        <template v-else>
                            <label
                                v-if="destinos.length > 6"
                                class="relative mt-3 block"
                            >
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                                />
                                <span class="sr-only">Buscar puesto</span>
                                <input
                                    v-model="busquedaDestino"
                                    type="search"
                                    placeholder="Buscar puesto…"
                                    class="h-9 w-full rounded-xl border bg-background/60 pr-3 pl-9 text-sm outline-none placeholder:text-muted-foreground focus:border-primary/50 focus:ring-4 focus:ring-primary/10"
                                />
                            </label>

                            <ul
                                role="radiogroup"
                                aria-label="Puesto destino"
                                class="mt-3 flex max-h-[min(22rem,45vh)] flex-col gap-1.5 overflow-y-auto pr-1"
                            >
                                <li v-for="d in destinosFiltrados" :key="d.id">
                                    <button
                                        type="button"
                                        role="radio"
                                        :aria-checked="
                                            destinoId === d.id
                                                ? 'true'
                                                : 'false'
                                        "
                                        class="flex w-full items-center gap-3 rounded-2xl border px-3 py-2.5 text-left transition-[background-color,border-color] duration-150"
                                        :class="
                                            destinoId === d.id
                                                ? 'border-primary/50 bg-menta'
                                                : 'border-transparent hover:border-oro/50 hover:bg-crema/30'
                                        "
                                        @click="
                                            destinoId =
                                                destinoId === d.id ? null : d.id
                                        "
                                    >
                                        <span
                                            class="grid size-9 shrink-0 place-items-center rounded-xl text-sm font-semibold transition-colors"
                                            :class="
                                                destinoId === d.id
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'bg-oro/15 text-bronce'
                                            "
                                            >{{ d.nombre.charAt(0) }}</span
                                        >
                                        <span class="min-w-0 flex-1">
                                            <span
                                                class="block truncate text-sm font-medium"
                                                >{{ d.nombre }}</span
                                            >
                                            <span
                                                class="block truncate text-xs text-muted-foreground"
                                            >
                                                {{ personasTexto(d.personas) }}
                                                <template
                                                    v-if="puede.ver_salarios"
                                                >
                                                    ·
                                                    {{
                                                        d.rango
                                                            ? `medio ${formatoMoneda(d.rango.medio)}`
                                                            : 'sin tabulador'
                                                    }}
                                                </template>
                                            </span>
                                        </span>
                                        <span
                                            class="grid size-5 shrink-0 place-items-center rounded-full border-2 transition-colors"
                                            :class="
                                                destinoId === d.id
                                                    ? 'border-primary'
                                                    : 'border-muted-foreground/30'
                                            "
                                        >
                                            <span
                                                class="punto size-2.5 rounded-full bg-primary"
                                                :class="
                                                    destinoId === d.id
                                                        ? 'scale-100'
                                                        : 'scale-0'
                                                "
                                            />
                                        </span>
                                    </button>
                                </li>
                                <li
                                    v-if="destinosFiltrados.length === 0"
                                    class="px-3 py-4 text-center text-xs text-muted-foreground"
                                >
                                    Ningún puesto coincide.
                                </li>
                            </ul>
                        </template>
                    </section>

                    <!-- Resumen -->
                    <section
                        class="resumen rounded-3xl border p-4 sm:p-5"
                        :class="listo ? 'listo' : 'bg-card'"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="grid size-6 place-items-center rounded-full text-[11px] font-semibold transition-colors"
                                :class="
                                    listo
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground'
                                "
                                >3</span
                            >
                            <h2 class="font-semibold">Revisa y confirma</h2>
                        </div>

                        <Transition name="aparecer" mode="out-in">
                            <div v-if="listo" key="listo" class="mt-3">
                                <div class="flex -space-x-2.5">
                                    <template
                                        v-for="p in avataresSeleccion"
                                        :key="p.id"
                                    >
                                        <img
                                            v-if="p.foto_url"
                                            :src="p.foto_url"
                                            alt=""
                                            class="size-8 rounded-full object-cover ring-2 ring-card"
                                        />
                                        <span
                                            v-else
                                            class="grid size-8 place-items-center rounded-full bg-salvia text-[11px] font-semibold text-primary ring-2 ring-card"
                                            >{{ getInitials(p.nombre) }}</span
                                        >
                                    </template>
                                    <span
                                        v-if="totalSeleccionadas > 5"
                                        class="grid size-8 place-items-center rounded-full bg-crema-2 text-[11px] font-semibold ring-2 ring-card"
                                        >+{{ totalSeleccionadas - 5 }}</span
                                    >
                                </div>
                                <p class="mt-3 text-sm leading-relaxed">
                                    Vas a trasladar
                                    <strong class="font-semibold">{{
                                        personasTexto(totalSeleccionadas)
                                    }}</strong>
                                    de
                                    <strong class="font-semibold">{{
                                        puesto.nombre
                                    }}</strong>
                                    a
                                    <strong
                                        class="font-semibold text-primary"
                                        >{{ destino?.nombre }}</strong
                                    >
                                </p>

                                <!-- Efecto en el sueldo frente al tabulador del destino -->
                                <ul
                                    v-if="conteoEfectos"
                                    class="mt-3 grid grid-cols-3 gap-1.5 text-center text-[11px]"
                                >
                                    <li
                                        class="rounded-xl bg-success-soft px-2 py-1.5"
                                    >
                                        <b
                                            class="block text-base tabular-nums"
                                            >{{ conteoEfectos.dentro }}</b
                                        >
                                        en rango
                                    </li>
                                    <li
                                        class="rounded-xl bg-danger-soft px-2 py-1.5"
                                    >
                                        <b
                                            class="block text-base tabular-nums"
                                            >{{ conteoEfectos.bajo }}</b
                                        >
                                        bajo mínimo
                                    </li>
                                    <li
                                        class="rounded-xl bg-warning-soft px-2 py-1.5"
                                    >
                                        <b
                                            class="block text-base tabular-nums"
                                            >{{ conteoEfectos.sobre }}</b
                                        >
                                        sobre máximo
                                    </li>
                                </ul>
                                <p
                                    v-else-if="
                                        puede.ver_salarios &&
                                        destino &&
                                        !destino.rango
                                    "
                                    class="mt-3 rounded-xl bg-warning-soft px-3 py-2 text-xs"
                                >
                                    {{ destino.nombre }} no tiene tabulador: no
                                    se puede comparar el sueldo.
                                </p>

                                <label class="mt-3 block">
                                    <span
                                        class="text-xs font-medium text-muted-foreground"
                                        >Motivo (opcional)</span
                                    >
                                    <textarea
                                        v-model="motivo"
                                        rows="2"
                                        maxlength="255"
                                        placeholder="Ej. Promoción por desempeño, reorganización del área"
                                        class="mt-1 w-full resize-none rounded-xl border bg-background/70 px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus:border-primary/50 focus:ring-4 focus:ring-primary/10"
                                    />
                                </label>
                            </div>
                            <p
                                v-else
                                key="pendiente"
                                class="mt-3 text-sm text-muted-foreground"
                            >
                                {{
                                    totalSeleccionadas === 0
                                        ? 'Marca a una o varias personas de la lista.'
                                        : `${personasTexto(totalSeleccionadas)} ${totalSeleccionadas === 1 ? 'lista' : 'listas'}. Ahora elige el puesto destino.`
                                }}
                            </p>
                        </Transition>

                        <p
                            v-for="(mensaje, campo) in errores"
                            :key="campo"
                            class="mt-2 rounded-lg bg-danger-soft px-3 py-2 text-xs"
                        >
                            {{ mensaje }}
                        </p>

                        <button
                            type="button"
                            class="boton-principal mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-2xl px-4 text-sm font-semibold transition-[background-color,opacity,transform] duration-150 active:scale-[0.98] disabled:cursor-not-allowed"
                            :disabled="!listo"
                            @click="continuar"
                        >
                            <Shuffle class="size-4" />
                            {{
                                listo
                                    ? `Trasladar ${personasTexto(totalSeleccionadas)}`
                                    : 'Trasladar personas'
                            }}
                        </button>
                        <p
                            class="mt-2 text-[11px] leading-snug text-muted-foreground"
                        >
                            Cada persona queda con su movimiento laboral
                            («Cambio de puesto» o «Promoción») y su jefe se
                            recalcula del organigrama. El sueldo no cambia:
                            ajústalo desde su expediente si corresponde.
                            Plantilla y vacantes se actualizan solas.
                        </p>
                    </section>
                </aside>
            </div>
        </template>

        <!-- ════════════ Tabulador y sueldos ════════════ -->
        <template v-if="seccion === 'tabulador' && puede.ver_salarios">
            <section
                class="flex flex-col gap-4 rounded-3xl border bg-card p-4 sm:p-6"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Tabulador del puesto</h2>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="rango">
                                Versión {{ rango.version }} ·
                                {{ rango.fuente }} · actualizado
                                {{ rango.fecha_actualizacion }}
                            </template>
                            <template v-else
                                >Aún no tiene rango capturado.</template
                            >
                        </p>
                    </div>
                    <Button
                        v-if="puede.editar_salarios && !editandoTabulador"
                        @click="editandoTabulador = true"
                    >
                        <Pencil class="size-4" />
                        {{
                            rango
                                ? 'Editar mínimo, medio y máximo'
                                : 'Capturar tabulador'
                        }}
                    </Button>
                </div>

                <TabuladorEditor
                    v-if="editandoTabulador"
                    :puesto-id="puesto.id"
                    :rango="rango"
                    @guardado="editandoTabulador = false"
                    @cancelar="editandoTabulador = false"
                />

                <template v-else-if="rango">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div
                            v-for="(valor, etiqueta) in {
                                Mínimo: rango.minimo,
                                Medio: rango.medio,
                                Máximo: rango.maximo,
                            }"
                            :key="etiqueta"
                            class="rounded-2xl border p-4"
                            :class="
                                etiqueta === 'Medio'
                                    ? 'border-primary/30 bg-menta'
                                    : 'bg-crema/30'
                            "
                        >
                            <p class="text-xs text-muted-foreground">
                                {{ etiqueta }} mensual
                            </p>
                            <p
                                class="mt-1 text-2xl font-semibold tracking-tight tabular-nums"
                                :class="etiqueta === 'Medio' && 'text-primary'"
                            >
                                {{ formatoMoneda(valor) }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="rounded-2xl border border-dashed bg-background/60 px-2 pt-2"
                    >
                        <p class="px-2 text-xs text-muted-foreground">
                            Dónde está el sueldo de cada persona
                        </p>
                        <BarraTabulador
                            :rango="rango"
                            :personas="
                                personas.map((p) => ({
                                    id: p.id,
                                    nombre: p.nombre,
                                    sueldo: p.sueldo ?? null,
                                }))
                            "
                        />
                    </div>

                    <ul
                        class="grid grid-cols-2 gap-2 text-center text-xs sm:grid-cols-4"
                    >
                        <li class="rounded-2xl bg-crema/40 px-3 py-2.5">
                            <b class="block text-lg tabular-nums">{{
                                resumenSueldos.conSueldo
                            }}</b>
                            con sueldo
                        </li>
                        <li class="rounded-2xl bg-success-soft px-3 py-2.5">
                            <b class="block text-lg tabular-nums">{{
                                pct(resumenSueldos.compaPromedio)
                            }}</b>
                            compa-ratio promedio
                        </li>
                        <li class="rounded-2xl bg-danger-soft px-3 py-2.5">
                            <b class="block text-lg tabular-nums">{{
                                resumenSueldos.bajo
                            }}</b>
                            bajo el mínimo
                        </li>
                        <li class="rounded-2xl bg-warning-soft px-3 py-2.5">
                            <b class="block text-lg tabular-nums">{{
                                resumenSueldos.sobre
                            }}</b>
                            sobre el máximo
                        </li>
                    </ul>
                </template>

                <EmptyState
                    v-else
                    :icono="Banknote"
                    titulo="Sin tabulador"
                    descripcion="Captura el mínimo, medio y máximo mensual para ver la posición de cada persona."
                />
            </section>

            <!-- Posición de cada persona -->
            <section
                class="flex flex-col gap-3 rounded-3xl border bg-card p-4 sm:p-6"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">
                        Sueldo y posición de cada persona
                    </h2>
                    <div v-if="puede.exportar_salarios" class="flex gap-2">
                        <Button variant="outline" size="sm" as-child>
                            <a :href="excel.url(consultaExportar)"
                                ><Download class="size-4" /> Excel</a
                            >
                        </Button>
                        <Button variant="outline" size="sm" as-child>
                            <a :href="pdf.url(consultaExportar)"
                                ><Download class="size-4" /> PDF</a
                            >
                        </Button>
                    </div>
                </div>

                <EmptyState
                    v-if="personas.length === 0"
                    :icono="Users"
                    titulo="Nadie ocupa este puesto"
                />

                <div v-else class="tabla-scroll rounded-2xl border">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead class="bg-crema text-left text-xs">
                            <tr>
                                <th class="p-2.5">Persona</th>
                                <th class="p-2.5 text-right">Sueldo mensual</th>
                                <th class="p-2.5 text-center">Compa-ratio</th>
                                <th class="p-2.5">Posición</th>
                                <th class="p-2.5">Desempeño</th>
                                <th class="p-2.5 text-right">Tope</th>
                                <th class="p-2.5 text-right">Puede subir</th>
                                <th class="p-2.5">
                                    <span class="sr-only">Corregir</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="p in personas"
                                :key="p.id"
                                class="border-t"
                            >
                                <td class="p-2.5">
                                    <span class="flex items-center gap-2.5">
                                        <img
                                            v-if="p.foto_url"
                                            :src="p.foto_url"
                                            alt=""
                                            class="size-8 rounded-full object-cover"
                                        />
                                        <span
                                            v-else
                                            class="grid size-8 place-items-center rounded-full bg-salvia text-[10px] font-semibold text-primary"
                                            >{{ getInitials(p.nombre) }}</span
                                        >
                                        <span class="min-w-0">
                                            <span class="block font-medium">{{
                                                p.nombre
                                            }}</span>
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >{{
                                                    p.sucursal ?? 'Sin sucursal'
                                                }}</span
                                            >
                                        </span>
                                    </span>
                                </td>
                                <td
                                    class="p-2.5 text-right font-semibold tabular-nums"
                                >
                                    {{
                                        p.sueldo == null
                                            ? 'Sin capturar'
                                            : formatoMoneda(p.sueldo)
                                    }}
                                </td>
                                <td class="p-2.5 text-center tabular-nums">
                                    {{ pct(p.compa_ratio) }}
                                </td>
                                <td class="p-2.5">
                                    <span
                                        v-if="p.banda"
                                        class="rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap"
                                        :class="CLASE_BANDA[p.banda]"
                                        >{{ p.banda_etiqueta }}</span
                                    >
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="p-2.5 text-xs">
                                    {{ p.desempeno_etiqueta ?? '—' }}
                                </td>
                                <td class="p-2.5 text-right tabular-nums">
                                    {{
                                        p.tope == null
                                            ? '—'
                                            : formatoMoneda(p.tope)
                                    }}
                                </td>
                                <td
                                    class="p-2.5 text-right text-success tabular-nums"
                                >
                                    {{
                                        p.margen == null
                                            ? '—'
                                            : formatoMoneda(p.margen)
                                    }}
                                </td>
                                <td class="p-2.5 text-right">
                                    <BotonCorregir
                                        dominio="salario"
                                        :sujeto-id="p.id"
                                        permiso="salarios.editar"
                                        variante="ghost"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Versiones -->
            <section
                v-if="versiones.length"
                class="rounded-3xl border bg-card p-4 sm:p-6"
            >
                <Button
                    variant="ghost"
                    size="sm"
                    class="-ml-2"
                    @click="verVersiones = !verVersiones"
                >
                    <History class="size-4" />
                    {{
                        verVersiones
                            ? 'Ocultar versiones'
                            : `Versiones del tabulador (${versiones.length})`
                    }}
                </Button>
                <ol
                    v-if="verVersiones"
                    class="relative mt-3 ml-3 grid gap-3 border-l pl-5"
                >
                    <li
                        v-for="v in versiones"
                        :key="v.version"
                        class="relative"
                    >
                        <span
                            class="absolute top-1 -left-[1.71rem] size-3 rounded-full ring-4 ring-card"
                            :class="
                                v.vigente_hasta
                                    ? 'bg-muted-foreground/40'
                                    : 'bg-primary'
                            "
                        />
                        <p class="text-sm font-medium">
                            Versión {{ v.version }}
                            <span
                                v-if="!v.vigente_hasta"
                                class="ml-1 rounded-full bg-success-soft px-2 py-0.5 text-[11px] text-success"
                                >Vigente</span
                            >
                        </p>
                        <p class="text-sm tabular-nums">
                            {{ formatoMoneda(v.minimo) }} ·
                            <b>{{ formatoMoneda(v.medio) }}</b> ·
                            {{ formatoMoneda(v.maximo) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ v.fuente }} · {{ v.vigente_desde
                            }}{{
                                v.vigente_hasta ? ` a ${v.vigente_hasta}` : ''
                            }}
                            ·
                            {{ v.usuario ?? 'Sistema' }}
                        </p>
                    </li>
                </ol>
            </section>
        </template>

        <!-- ════════════ Datos del puesto ════════════ -->
        <template v-if="seccion === 'datos'">
            <section class="rounded-3xl border bg-card p-4 sm:p-6">
                <h2 class="mb-4 font-semibold">Datos del puesto</h2>
                <PuestoDatosFormulario
                    v-if="puede.editar"
                    :key="`${puesto.id}-${puesto.nombre}-${puesto.activo}`"
                    :puesto="puesto"
                    :departamentos-disponibles="departamentosDisponibles"
                />
                <dl v-else class="grid gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-2xl bg-crema/30 p-3">
                        <dt class="text-xs text-muted-foreground">
                            Departamento
                        </dt>
                        <dd class="font-medium">
                            {{ puesto.departamento ?? 'Sin departamento' }}
                        </dd>
                    </div>
                    <div class="rounded-2xl bg-crema/30 p-3">
                        <dt class="text-xs text-muted-foreground">
                            Periodo de prueba
                        </dt>
                        <dd class="font-medium">
                            {{
                                puesto.meses_periodo_prueba
                                    ? `${puesto.meses_periodo_prueba} mes(es)`
                                    : 'Sin configurar'
                            }}
                        </dd>
                    </div>
                    <div class="rounded-2xl bg-crema/30 p-3 sm:col-span-2">
                        <dt class="text-xs text-muted-foreground">
                            Descripción
                        </dt>
                        <dd>{{ puesto.descripcion ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            <section
                v-if="puede.eliminar"
                class="flex flex-wrap items-center justify-between gap-3 rounded-3xl border border-destructive/25 bg-danger-soft/40 p-4 sm:p-6"
            >
                <div>
                    <h2 class="font-semibold">Eliminar puesto</h2>
                    <p class="text-sm text-muted-foreground">
                        Solo si ya no se usa. Si alguien lo ocupa, trasládalo
                        primero o márcalo como inactivo.
                    </p>
                </div>
                <Button
                    variant="destructive"
                    :disabled="personas.length > 0"
                    @click="eliminar"
                >
                    <Trash2 class="size-4" /> Eliminar
                </Button>
            </section>
        </template>

        <!-- Barra flotante de selección -->
        <Transition name="subir">
            <div
                v-if="
                    seccion === 'personas' &&
                    puede.mover &&
                    totalSeleccionadas > 0 &&
                    !confirmando
                "
                class="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-40 pr-20 pb-2 pl-3 sm:bottom-0 sm:pb-3 lg:inset-x-auto lg:bottom-6 lg:left-1/2 lg:-translate-x-1/2 lg:px-0 lg:pb-0"
            >
                <div
                    class="mx-auto flex max-w-xl items-center gap-2.5 rounded-2xl border bg-card/95 p-2 pl-3 shadow-lg backdrop-blur sm:gap-3 sm:p-2.5 sm:pl-4 lg:rounded-full lg:pl-5"
                >
                    <span
                        :key="totalSeleccionadas"
                        class="contador grid size-8 shrink-0 place-items-center rounded-full bg-primary text-sm font-semibold text-primary-foreground tabular-nums"
                        >{{ totalSeleccionadas }}</span
                    >
                    <span class="min-w-0 flex-1 text-sm leading-tight">
                        <span class="block font-medium">{{
                            totalSeleccionadas === 1
                                ? 'seleccionada'
                                : 'seleccionadas'
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{
                                destino
                                    ? `→ ${destino.nombre}`
                                    : 'Falta el puesto destino'
                            }}</span
                        >
                    </span>
                    <button
                        type="button"
                        class="hidden size-9 shrink-0 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-crema/60 hover:text-foreground sm:grid"
                        aria-label="Quitar selección"
                        @click="limpiarSeleccion"
                    >
                        <X class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="boton-principal inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl px-3.5 text-sm font-semibold sm:px-4 lg:rounded-full"
                        @click="continuar"
                    >
                        <template v-if="destino">Trasladar</template>
                        <template v-else>
                            <span class="sm:hidden">Destino</span>
                            <span class="hidden sm:inline">Elegir puesto</span>
                        </template>
                        <ArrowRight class="size-4" />
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Confirmación -->
        <Teleport to="body">
            <Transition name="velo">
                <div
                    v-if="confirmando"
                    class="fixed inset-0 z-50 flex items-end justify-center bg-foreground/25 backdrop-blur-[2px] sm:items-center sm:p-6"
                    @click.self="cerrarConfirmacion"
                >
                    <div
                        class="hoja w-full max-w-lg rounded-t-3xl border bg-card p-5 shadow-2xl sm:rounded-3xl sm:p-6"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="titulo-confirmacion"
                    >
                        <div
                            class="mx-auto mb-4 h-1.5 w-10 rounded-full bg-muted sm:hidden"
                            aria-hidden="true"
                        />
                        <h2
                            id="titulo-confirmacion"
                            class="text-lg font-semibold tracking-tight"
                        >
                            Trasladar {{ personasTexto(totalSeleccionadas) }}
                        </h2>

                        <div
                            class="mt-4 flex items-center gap-2 rounded-2xl bg-crema/40 p-3"
                        >
                            <span
                                class="min-w-0 flex-1 truncate rounded-xl bg-card px-3 py-2 text-center text-sm font-medium"
                                >{{ puesto.nombre }}</span
                            >
                            <span
                                class="flecha grid size-9 shrink-0 place-items-center rounded-full bg-primary text-primary-foreground"
                            >
                                <ArrowRight class="size-4" />
                            </span>
                            <span
                                class="min-w-0 flex-1 truncate rounded-xl bg-menta px-3 py-2 text-center text-sm font-semibold text-primary"
                                >{{ destino?.nombre }}</span
                            >
                        </div>

                        <p
                            v-if="puede.ver_salarios && destino?.rango"
                            class="mt-3 text-xs text-muted-foreground"
                        >
                            Tabulador de {{ destino.nombre }}:
                            {{ formatoMoneda(destino.rango.minimo) }} ·
                            <b>{{ formatoMoneda(destino.rango.medio) }}</b> ·
                            {{ formatoMoneda(destino.rango.maximo) }}
                        </p>

                        <ul
                            class="mt-3 max-h-64 divide-y overflow-y-auto rounded-2xl border"
                        >
                            <li
                                v-for="p in seleccionadas"
                                :key="p.id"
                                class="flex items-center gap-3 px-3 py-2"
                            >
                                <img
                                    v-if="p.foto_url"
                                    :src="p.foto_url"
                                    alt=""
                                    class="size-7 rounded-full object-cover"
                                />
                                <span
                                    v-else
                                    class="grid size-7 place-items-center rounded-full bg-salvia text-[10px] font-semibold text-primary"
                                    >{{ getInitials(p.nombre) }}</span
                                >
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-medium"
                                        >{{ p.nombre }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                    >
                                        {{ p.sucursal ?? 'Sin sucursal' }}
                                        <template
                                            v-if="
                                                puede.ver_salarios &&
                                                p.sueldo != null
                                            "
                                        >
                                            ·
                                            {{
                                                formatoMoneda(p.sueldo)
                                            }}</template
                                        >
                                    </span>
                                </span>
                                <span
                                    v-if="efecto(p, destino?.rango ?? null)"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        efecto(p, destino?.rango ?? null)!.tono
                                    "
                                >
                                    <component
                                        :is="
                                            efecto(p, destino?.rango ?? null)!
                                                .icono
                                        "
                                        v-if="
                                            efecto(p, destino?.rango ?? null)!
                                                .icono
                                        "
                                        class="size-3"
                                    />
                                    {{
                                        efecto(p, destino?.rango ?? null)!.texto
                                    }}
                                </span>
                            </li>
                        </ul>

                        <p
                            v-if="motivo.trim()"
                            class="mt-3 text-xs text-muted-foreground"
                        >
                            Motivo: «{{ motivo.trim() }}»
                        </p>

                        <div
                            class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                        >
                            <button
                                type="button"
                                class="inline-flex h-11 items-center justify-center rounded-2xl border px-5 text-sm font-medium transition-colors hover:bg-crema/50 disabled:opacity-50"
                                :disabled="enviando"
                                @click="cerrarConfirmacion"
                            >
                                Volver
                            </button>
                            <button
                                ref="botonConfirmar"
                                type="button"
                                class="boton-principal inline-flex h-11 items-center justify-center gap-2 rounded-2xl px-5 text-sm font-semibold"
                                :disabled="enviando"
                                @click="confirmar"
                            >
                                <Loader2
                                    v-if="enviando"
                                    class="size-4 animate-spin"
                                />
                                <Check v-else class="size-4" />
                                Sí, trasladar
                                {{ personasTexto(totalSeleccionadas) }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<style scoped>
.entrada-persona {
    animation: entrada-persona 260ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

@keyframes entrada-persona {
    from {
        opacity: 0;
        transform: translateY(6px) scale(0.985);
    }
}

.tarjeta-persona {
    transition:
        background-color 160ms ease,
        border-color 160ms ease,
        box-shadow 200ms ease,
        transform 160ms ease;
}

.tarjeta-persona.seleccionada {
    box-shadow:
        0 0 0 3px color-mix(in oklab, var(--primary) 14%, transparent),
        0 10px 22px -16px color-mix(in oklab, var(--primary) 60%, transparent);
}

.tarjeta-persona:focus-visible {
    outline: 2px solid var(--pastel-oro);
    outline-offset: 2px;
}

.tarjeta-persona:active:not([aria-disabled='true']) {
    transform: scale(0.985);
}

.marca {
    transition:
        background-color 160ms ease,
        color 160ms ease,
        transform 220ms var(--curva-salida);
}

.seleccionada .marca {
    transform: scale(1.12);
}

.punto {
    transition: transform 220ms var(--curva-salida);
}

.boton-principal {
    background-color: var(--primary);
    color: var(--primary-foreground);
}

.boton-principal:hover:not(:disabled) {
    background-color: color-mix(in oklab, var(--primary) 88%, black);
}

.boton-principal:disabled {
    background-color: var(--muted);
    color: var(--muted-foreground);
}

.boton-principal:focus-visible {
    outline: 2px solid var(--pastel-oro);
    outline-offset: 2px;
}

.resumen {
    transition:
        background-color 220ms ease,
        border-color 220ms ease;
}

.resumen.listo {
    border-color: color-mix(in oklab, var(--primary) 30%, transparent);
    background-image: linear-gradient(
        160deg,
        var(--pastel-menta),
        var(--pastel-superficie) 70%
    );
}

.flecha {
    animation: empuje 1.6s ease-in-out infinite;
}

.contador {
    animation: latido 260ms var(--curva-salida);
}

@keyframes latido {
    from {
        transform: scale(0.7);
    }
}

@keyframes empuje {
    50% {
        transform: translateX(3px);
    }
}

.aparecer-enter-active,
.aparecer-leave-active {
    transition:
        opacity 200ms ease,
        transform 200ms ease;
}

.aparecer-enter-from,
.aparecer-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

.subir-enter-active,
.subir-leave-active {
    transition:
        opacity 220ms ease,
        transform 260ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

.subir-enter-from,
.subir-leave-to {
    opacity: 0;
    transform: translateY(16px);
}

.velo-enter-active,
.velo-leave-active {
    transition: opacity 200ms ease;
}

.velo-enter-active .hoja,
.velo-leave-active .hoja {
    transition: transform 280ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

.velo-enter-from,
.velo-leave-to {
    opacity: 0;
}

.velo-enter-from .hoja,
.velo-leave-to .hoja {
    transform: translateY(32px);
}

@media (min-width: 640px) {
    .velo-enter-from .hoja,
    .velo-leave-to .hoja {
        transform: translateY(8px) scale(0.97);
    }
}

@media (prefers-reduced-motion: reduce) {
    .entrada-persona,
    .flecha,
    .contador {
        animation: none;
    }
}
</style>
