<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Briefcase,
    CalendarRange,
    Copy,
    FilePlus2,
    FileText,
    Flag,
    LoaderCircle,
    Pencil,
    Plus,
    Save,
    Search,
    Sparkles,
    Trash2,
    Trophy,
    UserRound,
    Users,
    Wand2,
} from '@lucide/vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionBuscable } from '@/components/ui/combobox-buscable';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Textarea } from '@/components/ui/textarea';
import { clonarSerializable } from '@/lib/clonar';
import { nombreSeguimiento } from '@/lib/evaluacionesTecnicas';
import { formatearFechaHora } from '@/lib/fechas';
import { tonoAcento } from '@/lib/formulariosEvaluacion';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index } from '@/routes/evaluaciones-tecnicas';
import calendario from '@/routes/evaluaciones-tecnicas/calendario';
import formularios from '@/routes/evaluaciones-tecnicas/formularios';
import type {
    PasoCalendarioTecnico,
    PlantillaTecnica,
    PuestoCalendarioTecnico,
    QuienContestaTecnica,
} from '@/types';

const props = defineProps<{
    puestos: PuestoCalendarioTecnico[];
    plantillas: PlantillaTecnica[];
    opcionesContesta: { value: QuienContestaTecnica; label: string }[];
    diasGracia: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Evaluaciones técnicas', href: index() },
            { title: 'Calendario por puesto', href: '' },
        ],
    },
});

type Pestana = 'calendario' | 'formularios';
const pestana = ref<Pestana>('calendario');

// ---------------------------------------------------------------------
// Puestos
// ---------------------------------------------------------------------
const buscarPuesto = ref('');
const soloConCalendario = ref(false);
const puestoId = ref<number | null>(
    props.puestos.find((p) => p.pasos.length)?.id ?? props.puestos[0]?.id ?? null,
);

function normalizar(t: string): string {
    return t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const puestosFiltrados = computed(() =>
    props.puestos.filter(
        (p) =>
            (!soloConCalendario.value || p.pasos.length > 0) &&
            normalizar(`${p.nombre} ${p.departamento ?? ''}`).includes(
                normalizar(buscarPuesto.value.trim()),
            ),
    ),
);
const puesto = computed(
    () => props.puestos.find((p) => p.id === puestoId.value) ?? null,
);
const conCalendario = computed(
    () => props.puestos.filter((p) => p.pasos.length > 0).length,
);

// ---------------------------------------------------------------------
// Calendario del puesto elegido (estado local hasta «Guardar»)
// ---------------------------------------------------------------------
type PasoLocal = PasoCalendarioTecnico & { _id: string; nombreAuto: boolean };

let contador = 0;

function local(p: PasoCalendarioTecnico): PasoLocal {
    return {
        ...clonarSerializable(p),
        _id: `p${contador++}`,
        nombreAuto: p.nombre === nombreSeguimiento(p.dias),
    };
}

const pasos = ref<PasoLocal[]>([]);
const sucio = ref(false);

function cargar(): void {
    pasos.value = (puesto.value?.pasos ?? []).map(local);
    // El watcher profundo corre después de esta asignación: recién cargado no está «sucio».
    void nextTick(() => (sucio.value = false));
}

watch(puestoId, cargar, { immediate: true });
watch(
    () => props.puestos,
    () => cargar(),
);
watch(
    pasos,
    () => {
        for (const p of pasos.value) {
            if (p.nombreAuto) {
                p.nombre = nombreSeguimiento(Number(p.dias) || 0);
            }
        }

        sucio.value = true;
    },
    { deep: true },
);

const plantillasActivas = computed(() =>
    props.plantillas.filter((p) => p.activa),
);
const opcionesPlantilla = computed<OpcionBuscable[]>(() =>
    plantillasActivas.value.map((p) => ({
        value: p.id,
        label: p.nombre,
        descripcion: `${p.preguntas} preguntas · ${p.total_puntos} puntos`,
        chip: p.version_publicada ? `v${p.version_publicada}` : 'Sin publicar',
        chipTono: p.version_publicada ? 'primario' : 'neutro',
    })),
);

function plantillaDe(id: number | null): PlantillaTecnica | null {
    return props.plantillas.find((p) => p.id === id) ?? null;
}

function nuevoPaso(dias: number, final = false): PasoLocal {
    return local({
        dias,
        nombre: nombreSeguimiento(dias),
        plantilla_id: plantillasActivas.value[0]?.id ?? null,
        contesta: 'colaborador',
        calificacion_minima: 80,
        dias_para_contestar: 7,
        es_final: final,
        activo: true,
    });
}

function agregar(): void {
    const ultimo = Math.max(0, ...pasos.value.map((p) => Number(p.dias) || 0));
    pasos.value.push(nuevoPaso(ultimo ? ultimo + 15 : 15));
}

function usarSugerencia(): void {
    const dias = puesto.value?.sugerencia ?? [];
    pasos.value = dias.map((d, i) => nuevoPaso(d, i === dias.length - 1));
}

function quitar(i: number): void {
    pasos.value.splice(i, 1);
}

function marcarFinal(p: PasoLocal): void {
    const valor = !p.es_final;
    pasos.value.forEach((x) => (x.es_final = false));
    p.es_final = valor;
}

const ordenados = computed(() =>
    [...pasos.value].sort((a, b) => (Number(a.dias) || 0) - (Number(b.dias) || 0)),
);
const ultimoDia = computed(() =>
    Math.max(
        (puesto.value?.meses_periodo_prueba ?? 0) * 30,
        ...pasos.value.map((p) => Number(p.dias) || 0),
        1,
    ),
);

const guardando = ref(false);
const errores = ref<string[]>([]);

function guardar(): void {
    if (!puesto.value) {
        return;
    }

    guardando.value = true;
    errores.value = [];
    router.put(
        calendario.guardar.url(puesto.value.id),
        {
            pasos: ordenados.value.map((p) => ({
                dias: Number(p.dias),
                nombre: p.nombre,
                plantilla_id: p.plantilla_id,
                contesta: p.contesta,
                calificacion_minima: Number(p.calificacion_minima),
                dias_para_contestar: Number(p.dias_para_contestar),
                es_final: p.es_final,
                activo: p.activo,
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (e) =>
                (errores.value = Object.values(e).flatMap((v) =>
                    String(v).split('\n'),
                )),
            onFinish: () => (guardando.value = false),
        },
    );
}

// ---- Copiar a otros puestos
const copiarAbierto = ref(false);
const destinos = ref<number[]>([]);
const buscarDestino = ref('');
const destinosFiltrados = computed(() =>
    props.puestos.filter(
        (p) =>
            p.id !== puestoId.value &&
            normalizar(p.nombre).includes(normalizar(buscarDestino.value.trim())),
    ),
);

function alternarDestino(id: number, marcar: boolean): void {
    destinos.value = marcar
        ? [...destinos.value, id]
        : destinos.value.filter((d) => d !== id);
}

function copiar(): void {
    if (!puesto.value) {
        return;
    }

    router.post(
        calendario.copiar.url(puesto.value.id),
        { destinos: destinos.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                copiarAbierto.value = false;
                destinos.value = [];
            },
        },
    );
}

// ---------------------------------------------------------------------
// Formularios técnicos
// ---------------------------------------------------------------------
const dialogoFormulario = ref(false);
const editandoPlantilla = ref<PlantillaTecnica | null>(null);
const formPlantilla = reactive({ nombre: '', descripcion: '', activa: true });
const erroresPlantilla = ref<Record<string, string>>({});
const guardandoPlantilla = ref(false);

function abrirPlantilla(p: PlantillaTecnica | null): void {
    editandoPlantilla.value = p;
    formPlantilla.nombre = p?.nombre ?? '';
    formPlantilla.descripcion = p?.descripcion ?? '';
    formPlantilla.activa = p?.activa ?? true;
    erroresPlantilla.value = {};
    dialogoFormulario.value = true;
}

function guardarPlantilla(): void {
    guardandoPlantilla.value = true;
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (dialogoFormulario.value = false),
        onError: (e: Record<string, string>) => (erroresPlantilla.value = e),
        onFinish: () => (guardandoPlantilla.value = false),
    };

    if (editandoPlantilla.value) {
        router.put(formularios.update.url(editandoPlantilla.value.id), { ...formPlantilla }, opciones);
    } else {
        router.post(formularios.store.url(), { nombre: formPlantilla.nombre, descripcion: formPlantilla.descripcion }, opciones);
    }
}

const CONTESTA_ICONO: Record<QuienContestaTecnica, typeof UserRound> = {
    colaborador: UserRound,
    jefe_directo: Users,
};
const campo =
    'h-9 rounded-lg border border-input bg-background px-2.5 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40';
</script>

<template>
    <Head title="Calendario de evaluaciones técnicas" />

    <div class="pagina-ancha flex flex-col gap-5 pb-16">
        <CrudPageHeader
            titulo="Calendario de evaluaciones técnicas"
            descripcion="Por puesto: qué día desde el ingreso, qué formulario, quién la contesta y con cuánto aprueba."
            :icono="CalendarRange"
        >
            <Button data-tour="calendario-seguimientos-nuevo-formulario" variant="outline" @click="abrirPlantilla(null)">
                <FilePlus2 class="size-4" /> Nuevo formulario técnico
            </Button>
        </CrudPageHeader>

        <!-- Pestañas -->
        <nav data-tour="calendario-seguimientos-pestanas" class="scroll-x-limpio flex w-fit gap-1 rounded-2xl border border-border bg-card p-1 shadow-xs" aria-label="Secciones">
            <button
                v-for="p in [
                    { clave: 'calendario', texto: `Calendario por puesto · ${conCalendario}`, icono: CalendarRange },
                    { clave: 'formularios', texto: `Formularios técnicos · ${plantillas.length}`, icono: FileText },
                ] as const"
                :key="p.clave"
                type="button"
                :class="
                    cn(
                        'flex min-h-10 presionable items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition-colors',
                        pestana === p.clave ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted/50',
                    )
                "
                :aria-current="pestana === p.clave ? 'page' : undefined"
                @click="pestana = p.clave"
            >
                <component :is="p.icono" class="size-4" /> {{ p.texto }}
            </button>
        </nav>

        <CambioSuave>
            <!-- ======================= CALENDARIO ======================= -->
            <div v-if="pestana === 'calendario'" key="calendario" class="grid items-start gap-5 lg:grid-cols-[320px_minmax(0,1fr)]">
                <!-- Lista de puestos -->
                <aside data-tour="calendario-seguimientos-puestos" class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-3 shadow-xs lg:sticky lg:top-20">
                    <label class="relative">
                        <span class="sr-only">Buscar puesto</span>
                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="buscarPuesto" class="pl-9" placeholder="Buscar puesto…" />
                    </label>
                    <label class="flex items-center gap-2 px-1 text-xs text-muted-foreground">
                        <Checkbox v-model="soloConCalendario" /> Solo con calendario
                    </label>
                    <ListaAnimada :cantidad="puestosFiltrados.length" class="flex max-h-[65vh] flex-col gap-1 overflow-y-auto pr-1">
                        <li v-for="p in puestosFiltrados" :key="p.id">
                            <button
                                type="button"
                                :class="
                                    cn(
                                        'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors',
                                        p.id === puestoId ? 'bg-salvia text-primary' : 'hover:bg-muted/50',
                                    )
                                "
                                @click="puestoId = p.id"
                            >
                                <span
                                    :class="
                                        cn(
                                            'flex size-8 shrink-0 items-center justify-center rounded-lg text-xs font-semibold',
                                            p.pasos.length ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                                        )
                                    "
                                    >{{ p.pasos.length || '—' }}</span
                                >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium">{{ p.nombre }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">
                                        {{ p.pasos.length ? p.pasos.map((x) => x.dias).join(' · ') + ' días' : 'Sin calendario' }}
                                        · {{ p.colaboradores_activos }} activos
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ListaAnimada>
                    <p v-if="!puestosFiltrados.length" class="px-2 py-6 text-center text-sm text-muted-foreground">Sin coincidencias.</p>
                </aside>

                <!-- Calendario -->
                <section v-if="puesto" data-tour="calendario-seguimientos-calendario" class="flex flex-col gap-4">
                    <header class="flex flex-wrap items-start justify-between gap-3 rounded-3xl border border-border bg-gradient-to-br from-crema/70 via-card to-salvia/40 p-5 shadow-xs">
                        <div class="min-w-0">
                            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Briefcase class="size-3.5" /> {{ puesto.departamento ?? 'Puesto' }}
                            </p>
                            <h2 class="font-display text-2xl font-semibold tracking-tight">{{ puesto.nombre }}</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                <template v-if="puesto.meses_periodo_prueba">
                                    Contrato de capacitación de <strong class="text-foreground">{{ puesto.meses_periodo_prueba }} {{ puesto.meses_periodo_prueba === 1 ? 'mes' : 'meses' }}</strong> (catálogo de puestos).
                                    Sugerencia: {{ puesto.sugerencia.map(nombreSeguimiento).join(' · ') }}.
                                </template>
                                <template v-else>Este puesto no tiene cargada la duración del periodo de prueba: arma el calendario a mano.</template>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button v-if="puesto.sugerencia.length" variant="outline" @click="usarSugerencia">
                                <Wand2 class="size-4" /> Usar sugerencia
                            </Button>
                            <Button v-if="puesto.pasos.length" variant="outline" @click="copiarAbierto = true">
                                <Copy class="size-4" /> Copiar a otros puestos
                            </Button>
                        </div>
                    </header>

                    <!-- Vista rápida del calendario -->
                    <div v-if="ordenados.length" class="rounded-2xl border border-border bg-card px-6 pt-8 pb-6 shadow-xs">
                        <div class="relative h-2 rounded-full bg-muted">
                            <div class="absolute inset-y-0 left-0 crece-barra rounded-full bg-gradient-to-r from-[var(--pastel-esmeralda)] to-[var(--pastel-oro)]" style="width: 100%" />
                            <span
                                v-for="p in ordenados"
                                :key="p._id"
                                class="absolute top-1/2 flex -translate-x-1/2 -translate-y-1/2 flex-col items-center transition-[left] duration-500 ease-out"
                                :style="{ left: `${Math.min(100, ((Number(p.dias) || 0) / ultimoDia) * 100)}%` }"
                            >
                                <span
                                    :class="
                                        cn(
                                            'flex size-8 items-center justify-center rounded-full shadow ring-4 ring-card',
                                            p.es_final ? 'bg-oro text-foreground' : 'bg-primary text-primary-foreground',
                                            !p.activo && 'opacity-40',
                                        )
                                    "
                                >
                                    <Trophy v-if="p.es_final" class="size-4" />
                                    <component :is="CONTESTA_ICONO[p.contesta]" v-else class="size-4" />
                                </span>
                                <span class="absolute top-10 text-[11px] font-medium whitespace-nowrap">{{ p.nombre }}</span>
                            </span>
                        </div>
                    </div>

                    <ul v-if="errores.length" class="flex entrada-suave flex-col gap-1 rounded-2xl bg-danger-soft p-4 text-sm text-destructive">
                        <li v-for="(e, i) in errores" :key="i">{{ e }}</li>
                    </ul>

                    <ListaAnimada v-if="pasos.length" :cantidad="pasos.length" class="flex flex-col gap-3">
                        <li
                            v-for="(p, i) in pasos"
                            :key="p._id"
                            :class="
                                cn(
                                    'grid gap-4 rounded-2xl border bg-card p-4 shadow-xs transition-[border-color,opacity] md:grid-cols-[120px_minmax(0,1fr)]',
                                    p.es_final ? 'border-oro/70' : 'border-border',
                                    !p.activo && 'opacity-70',
                                )
                            "
                        >
                            <!-- Día -->
                            <div class="flex items-center gap-3 md:flex-col md:items-start md:gap-1">
                                <span class="text-xs text-muted-foreground">Día desde el ingreso</span>
                                <input
                                    v-model.number="p.dias"
                                    type="number"
                                    min="1"
                                    max="730"
                                    :class="cn(campo, 'h-12 w-24 text-center font-display text-2xl font-semibold tabular-nums')"
                                    aria-label="Día desde el ingreso"
                                />
                            </div>

                            <div class="flex min-w-0 flex-col gap-3">
                                <div class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
                                    <label class="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
                                        Nombre
                                        <input
                                            v-model="p.nombre"
                                            :class="cn(campo, 'text-foreground')"
                                            maxlength="120"
                                            @input="p.nombreAuto = p.nombre === nombreSeguimiento(Number(p.dias) || 0)"
                                        />
                                    </label>
                                    <div class="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
                                        Formulario técnico
                                        <ComboboxBuscable
                                            v-model="p.plantilla_id"
                                            :opciones="opcionesPlantilla"
                                            numerico
                                            placeholder="Elige el formulario…"
                                            texto-vacio="Crea primero un formulario técnico"
                                            aria-label="Formulario técnico"
                                        />
                                        <span v-if="plantillaDe(p.plantilla_id) && !plantillaDe(p.plantilla_id)?.version_publicada" class="text-[11px] font-normal text-warning">
                                            Publícalo para que se pueda generar.
                                        </span>
                                    </div>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_repeat(2,minmax(0,1fr))]">
                                    <div class="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
                                        La contesta
                                        <div class="flex gap-1 rounded-xl bg-muted/60 p-1" role="radiogroup">
                                            <button
                                                v-for="o in opcionesContesta"
                                                :key="o.value"
                                                type="button"
                                                role="radio"
                                                :aria-checked="p.contesta === o.value"
                                                :class="
                                                    cn(
                                                        'flex flex-1 presionable items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium transition-all',
                                                        p.contesta === o.value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                                    )
                                                "
                                                @click="p.contesta = o.value"
                                            >
                                                <component :is="CONTESTA_ICONO[o.value]" class="size-3.5" />
                                                {{ o.value === 'colaborador' ? 'Colaborador' : 'Jefe directo' }}
                                            </button>
                                        </div>
                                    </div>
                                    <label class="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
                                        Aprueba con (0-100)
                                        <input v-model.number="p.calificacion_minima" type="number" min="0" max="100" step="0.5" :class="cn(campo, 'text-foreground tabular-nums')" />
                                    </label>
                                    <label class="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
                                        Días para contestar
                                        <input v-model.number="p.dias_para_contestar" type="number" min="1" max="60" :class="cn(campo, 'text-foreground tabular-nums')" />
                                    </label>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 border-t border-border pt-3">
                                    <Button type="button" size="sm" :variant="p.es_final ? 'default' : 'outline'" @click="marcarFinal(p)">
                                        <Flag class="size-3.5" /> {{ p.es_final ? 'Técnica final' : 'Marcar como técnica final' }}
                                    </Button>
                                    <label class="flex items-center gap-2 px-2 text-xs">
                                        <Checkbox v-model="p.activo" /> Activa
                                    </label>
                                    <span class="ml-auto text-[11px] text-muted-foreground">
                                        {{ p.contesta === 'jefe_directo' ? 'El jefe directo sale del organigrama.' : 'Le llega al colaborador en Mi espacio.' }}
                                    </span>
                                    <Button type="button" size="sm" variant="ghost" class="text-destructive hover:text-destructive" :aria-label="`Quitar ${p.nombre}`" @click="quitar(i)">
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>
                        </li>
                    </ListaAnimada>

                    <EmptyState
                        v-else
                        :icono="CalendarRange"
                        titulo="Este puesto no tiene evaluaciones técnicas"
                        :descripcion="
                            puesto.sugerencia.length
                                ? `Usa la sugerencia (${puesto.sugerencia.map(nombreSeguimiento).join(', ')}) o agrega cada evaluación a mano.`
                                : 'Agrega la primera evaluación: el día desde el ingreso, el formulario y quién la contesta.'
                        "
                    />

                    <div data-tour="calendario-seguimientos-guardar" class="sticky bottom-3 z-10 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-card/95 px-4 py-3 shadow-md backdrop-blur">
                        <Button variant="outline" :disabled="pasos.length >= 12" @click="agregar">
                            <Plus class="size-4" /> Agregar evaluación
                        </Button>
                        <p class="hidden text-xs text-muted-foreground md:block">
                            Se generan solas cada mañana al llegar su día (hasta {{ diasGracia }} días tarde). Quitar una no borra las ya generadas.
                        </p>
                        <Button :disabled="guardando || !sucio" @click="guardar">
                            <LoaderCircle v-if="guardando" class="size-4 animate-spin" />
                            <Save v-else class="size-4" />
                            Guardar calendario
                        </Button>
                    </div>
                </section>
                <EmptyState v-else :icono="Briefcase" titulo="Elige un puesto" descripcion="Selecciona un puesto de la lista para armar su calendario." />
            </div>

            <!-- ======================= FORMULARIOS ======================= -->
            <div v-else key="formularios" data-tour="calendario-seguimientos-formularios" class="flex flex-col gap-4">
                <div v-if="plantillas.length" class="grid entrada-escalonada gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    <article
                        v-for="p in plantillas"
                        :key="p.id"
                        :class="cn('flex flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-xs', !p.activa && 'opacity-60')"
                    >
                        <div class="h-2" :style="{ background: tonoAcento(p.acento).fondo }" />
                        <div class="flex flex-1 flex-col gap-3 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="font-semibold text-balance">{{ p.nombre }}</h3>
                                <span
                                    :class="
                                        cn(
                                            'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                            p.version_publicada ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning',
                                        )
                                    "
                                    >{{ p.version_publicada ? `v${p.version_publicada} publicada` : 'Sin publicar' }}</span
                                >
                            </div>
                            <p v-if="p.descripcion" class="line-clamp-2 text-sm text-muted-foreground">{{ p.descripcion }}</p>
                            <div class="flex flex-wrap gap-1.5 text-xs">
                                <span class="rounded-full bg-muted px-2 py-0.5">{{ p.preguntas }} preguntas</span>
                                <span class="rounded-full bg-muted px-2 py-0.5">{{ p.total_puntos }} puntos</span>
                                <span class="rounded-full bg-muted px-2 py-0.5">En {{ p.pasos }} {{ p.pasos === 1 ? 'evaluación' : 'evaluaciones' }}</span>
                                <span v-if="p.tiene_borrador" class="rounded-full bg-crema px-2 py-0.5 text-bronce">Borrador en edición</span>
                            </div>
                            <p class="mt-auto text-[11px] text-muted-foreground">Actualizado {{ formatearFechaHora(p.actualizado_en) }}</p>
                            <div class="flex gap-2">
                                <Button class="flex-1" as-child>
                                    <Link :href="formularios.editar(p.id)"><Pencil class="size-4" /> Editar preguntas y diseño</Link>
                                </Button>
                                <Button variant="outline" size="icon" :aria-label="`Renombrar ${p.nombre}`" @click="abrirPlantilla(p)">
                                    <FileText class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </article>
                </div>
                <EmptyState
                    v-else
                    :icono="Sparkles"
                    titulo="Crea tu primer formulario técnico"
                    descripcion="Arma las preguntas como en Google Forms (opción múltiple, casillas, escala…), con respuesta correcta y puntaje. Después lo eliges en el calendario de cada puesto."
                />
                <Button class="w-fit" @click="abrirPlantilla(null)"><FilePlus2 class="size-4" /> Nuevo formulario técnico</Button>
            </div>
        </CambioSuave>
    </div>

    <!-- Nuevo / renombrar formulario -->
    <Dialog v-model:open="dialogoFormulario">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ editandoPlantilla ? 'Datos del formulario' : 'Nuevo formulario técnico' }}</DialogTitle>
                <DialogDescription>
                    {{ editandoPlantilla ? 'El nombre lo ve RH al elegirlo en el calendario.' : 'Nace con una pregunta de ejemplo; enseguida abres el editor para armarlo.' }}
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-3">
                <label class="flex flex-col gap-1 text-sm font-medium">
                    Nombre
                    <Input v-model="formPlantilla.nombre" maxlength="160" placeholder="Técnica de cobranza — Gestor" />
                    <span v-if="erroresPlantilla.nombre" class="text-xs text-destructive">{{ erroresPlantilla.nombre }}</span>
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium">
                    Descripción (opcional)
                    <Textarea v-model="formPlantilla.descripcion" rows="3" maxlength="2000" />
                </label>
                <label v-if="editandoPlantilla" class="flex items-center gap-2 text-sm">
                    <Checkbox v-model="formPlantilla.activa" /> Activo (se puede elegir en el calendario)
                </label>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="dialogoFormulario = false">Cancelar</Button>
                <Button :disabled="guardandoPlantilla || !formPlantilla.nombre.trim()" @click="guardarPlantilla">
                    <LoaderCircle v-if="guardandoPlantilla" class="size-4 animate-spin" />
                    {{ editandoPlantilla ? 'Guardar' : 'Crear y abrir el editor' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Copiar calendario -->
    <Dialog v-model:open="copiarAbierto">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Copiar el calendario de {{ puesto?.nombre }}</DialogTitle>
                <DialogDescription>Reemplaza el calendario de los puestos que elijas (p. ej. Gestor → Gestor Volante).</DialogDescription>
            </DialogHeader>
            <label class="relative">
                <span class="sr-only">Buscar puesto</span>
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="buscarDestino" class="pl-9" placeholder="Buscar puesto…" />
            </label>
            <ul class="flex max-h-72 flex-col gap-1 overflow-y-auto">
                <li v-for="p in destinosFiltrados" :key="p.id">
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2 hover:bg-muted/50">
                        <Checkbox :model-value="destinos.includes(p.id)" @update:model-value="alternarDestino(p.id, $event === true)" />
                        <span class="flex-1 text-sm">{{ p.nombre }}</span>
                        <span v-if="p.pasos.length" class="text-xs text-warning">Ya tiene {{ p.pasos.length }}</span>
                    </label>
                </li>
            </ul>
            <DialogFooter>
                <Button variant="outline" @click="copiarAbierto = false">Cancelar</Button>
                <Button :disabled="!destinos.length" @click="copiar"><Copy class="size-4" /> Copiar a {{ destinos.length }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
