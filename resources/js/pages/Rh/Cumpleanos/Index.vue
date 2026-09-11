<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    Cake,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    FilterX,
    Gift,
    Image,
    ListChecks,
    Plus,
    Search,
    Settings2,
    Sparkles,
    Trash2,
    TriangleAlert,
    UserRoundX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmojiPicker from '@/components/Common/EmojiPicker.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import ColaboradorCumpleanosCard from '@/components/Rh/ColaboradorCumpleanosCard.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { useInitials } from '@/composables/useInitials';
import { mensajeFelicitacion } from '@/lib/cumpleanos';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/cumpleanos';
import { index as configuracionIndex } from '@/routes/rh/cumpleanos/configuracion';
import {
    destroy as destroyFrase,
    store as storeFrase,
    update as updateFrase,
} from '@/routes/rh/cumpleanos/frases';

type Colaborador = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    sucursal: string | null;
    departamento: string | null;
    puesto: string | null;
    estatus: string | null;
    dia: number;
    mes: number;
    edad: number | null;
    tiene_foto: boolean;
    foto_url: string | null;
};

type Frase = {
    id: number;
    texto: string;
    categoria: string | null;
    activo: boolean;
    usado_count: number;
};

const MESES = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

const DIAS_SEMANA = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

const props = defineProps<{
    mes: number;
    anio: number;
    filtros: {
        sucursal_id?: string;
        departamento_id?: string;
        colaborador_id?: string;
        estatus?: string;
        busqueda?: string;
    };
    delMes: Colaborador[];
    hoy: Colaborador[];
    rango: { desde: string; hasta: string };
    proximosRango: Colaborador[];
    totalProximos7: number;
    totalProximos30: number;
    sinFechaNacimiento: { id: number; nombre: string; sucursal: string | null }[];
    calendario: Record<number, Colaborador[]> | null;
    opciones: {
        sucursales: { id: number; nombre: string }[];
        departamentos: { id: number; nombre: string }[];
        colaboradores: { id: number; nombre: string }[];
        frases: Frase[];
    };
    config: {
        enabled: boolean;
        notify_employee: boolean;
        notify_rh: boolean;
        show_age: boolean;
        show_branch: boolean;
        show_employee_photo: boolean;
        auto_generate_cards: boolean;
    };
    permisos: {
        calendario: boolean;
        descargarImagen: boolean;
        configurar: boolean;
        gestionarFrases: boolean;
        gestionarNotificaciones: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cumpleaños', href: index.url() },
        ],
    },
});

const { mostrarExito, mostrarError } = useAlertas();
const { getInitials } = useInitials();

const filtros = ref({
    sucursal_id: props.filtros.sucursal_id ?? '',
    departamento_id: props.filtros.departamento_id ?? '',
    colaborador_id: props.filtros.colaborador_id ?? '',
    estatus: props.filtros.estatus ?? '',
    busqueda: props.filtros.busqueda ?? '',
});

// reka-ui/Radix prohíben value="" en un <SelectItem> (queda reservado para
// "sin selección" y el click no hace nada) — por eso estos selects no
// respondían. Se usa un centinela solo en el <Select> y se traduce a ''
// (el valor real que espera el backend) al leer/escribir en `filtros`.
const TODAS_SUCURSALES = '__todas__';
const TODOS_DEPARTAMENTOS = '__todos__';
const TODOS_ESTATUS = '__activos__';

const sucursalSeleccionada = computed({
    get: () => filtros.value.sucursal_id || TODAS_SUCURSALES,
    set: (valor: string) => {
        filtros.value.sucursal_id = valor === TODAS_SUCURSALES ? '' : valor;
    },
});
const departamentoSeleccionado = computed({
    get: () => filtros.value.departamento_id || TODOS_DEPARTAMENTOS,
    set: (valor: string) => {
        filtros.value.departamento_id = valor === TODOS_DEPARTAMENTOS ? '' : valor;
    },
});
const estatusSeleccionado = computed({
    get: () => filtros.value.estatus || TODOS_ESTATUS,
    set: (valor: string) => {
        filtros.value.estatus = valor === TODOS_ESTATUS ? '' : valor;
    },
});

const opcionesColaboradores = computed(() =>
    props.opciones.colaboradores.map((c) => ({ value: String(c.id), label: c.nombre })),
);
const mesActual = ref(props.mes);
const anioActual = ref(props.anio);
const rangoDesde = ref(props.rango.desde);
const rangoHasta = ref(props.rango.hasta);

let temporizadorBusqueda: ReturnType<typeof setTimeout> | undefined;
let temporizadorRango: ReturnType<typeof setTimeout> | undefined;

function navegar() {
    router.get(
        index.url(),
        {
            ...filtros.value,
            mes: mesActual.value,
            anio: anioActual.value,
            rango_desde: rangoDesde.value,
            rango_hasta: rangoHasta.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function navegarConDebounce() {
    clearTimeout(temporizadorBusqueda);
    temporizadorBusqueda = setTimeout(navegar, 400);
}

function navegarRangoConDebounce() {
    if (!rangoDesde.value || !rangoHasta.value || rangoHasta.value < rangoDesde.value) {
        return;
    }

    clearTimeout(temporizadorRango);
    temporizadorRango = setTimeout(navegar, 300);
}

const hayFiltrosActivos = computed(
    () =>
        filtros.value.sucursal_id !== '' ||
        filtros.value.departamento_id !== '' ||
        filtros.value.colaborador_id !== '' ||
        filtros.value.estatus !== '' ||
        filtros.value.busqueda !== '',
);

function limpiarFiltros() {
    filtros.value = {
        sucursal_id: '',
        departamento_id: '',
        colaborador_id: '',
        estatus: '',
        busqueda: '',
    };
    navegar();
}

function mesAnterior() {
    if (mesActual.value === 1) {
        mesActual.value = 12;
        anioActual.value -= 1;
    } else {
        mesActual.value -= 1;
    }

    navegar();
}

function mesSiguiente() {
    if (mesActual.value === 12) {
        mesActual.value = 1;
        anioActual.value += 1;
    } else {
        mesActual.value += 1;
    }

    navegar();
}

const hoyReal = new Date();

function irAHoy() {
    mesActual.value = hoyReal.getMonth() + 1;
    anioActual.value = hoyReal.getFullYear();
    navegar();
}

async function copiarMensaje(colaborador: { nombre: string }) {
    const texto = mensajeFelicitacion(colaborador.nombre);

    try {
        await navigator.clipboard.writeText(texto);
        mostrarExito('Mensaje copiado al portapapeles.');
    } catch {
        mostrarError('No se pudo copiar el mensaje.');
    }
}

// --- Calendario: dias reales del mes seleccionado, nunca un "31" fijo ---
const diasEnMes = computed(() =>
    new Date(anioActual.value, mesActual.value, 0).getDate(),
);

// getDay(): 0 = domingo ... 6 = sabado (misma convencion que DIAS_SEMANA).
const primerDiaSemana = computed(
    () => new Date(anioActual.value, mesActual.value - 1, 1).getDay(),
);

type CeldaCalendario = { dia: number | null; colaboradores: Colaborador[] };

const celdas = computed<CeldaCalendario[]>(() => {
    const lista: CeldaCalendario[] = [];

    for (let i = 0; i < primerDiaSemana.value; i++) {
        lista.push({ dia: null, colaboradores: [] });
    }

    for (let dia = 1; dia <= diasEnMes.value; dia++) {
        lista.push({ dia, colaboradores: props.calendario?.[dia] ?? [] });
    }

    // Completa la ultima semana para que el grid no quede "cortado" a medias.
    while (lista.length % 7 !== 0) {
        lista.push({ dia: null, colaboradores: [] });
    }

    return lista;
});

const semanas = computed(() => {
    const filas: CeldaCalendario[][] = [];

    for (let i = 0; i < celdas.value.length; i += 7) {
        filas.push(celdas.value.slice(i, i + 7));
    }

    return filas;
});

function esHoy(dia: number | null): boolean {
    return (
        dia !== null &&
        anioActual.value === hoyReal.getFullYear() &&
        mesActual.value === hoyReal.getMonth() + 1 &&
        dia === hoyReal.getDate()
    );
}

const diasConDatos = computed(() => {
    if (!props.calendario) {
        return [];
    }

    return Object.entries(props.calendario)
        .map(([dia, colaboradores]) => ({ dia: Number(dia), colaboradores }))
        .sort((a, b) => a.dia - b.dia);
});

// --- Dialog de detalle del dia ---
const diaSeleccionado = ref<CeldaCalendario | null>(null);

function abrirDia(celda: CeldaCalendario) {
    if (celda.dia === null || celda.colaboradores.length === 0) {
        return;
    }

    diaSeleccionado.value = celda;
}

// --- Dialog de gestion de frases ---
const dialogFrasesAbierto = ref(false);
const nuevaFrase = useForm({ texto: '', categoria: '' });

function agregarFrase() {
    nuevaFrase.post(storeFrase.url(), {
        preserveScroll: true,
        onSuccess: () => nuevaFrase.reset(),
    });
}

function alternarFrase(frase: Frase) {
    router.put(
        updateFrase.url(frase.id),
        { activo: !frase.activo },
        { preserveScroll: true, preserveState: true },
    );
}

async function eliminarFrase(frase: Frase) {
    if (!confirm(`¿Eliminar la frase "${frase.texto.slice(0, 40)}..."?`)) {
        return;
    }

    router.delete(destroyFrase.url(frase.id), { preserveScroll: true });
}

// --- Sidebar "Proximos cumpleaños": rango de fechas libre (mini-calendario) ---
const RANGOS_RAPIDOS = [
    { etiqueta: '7 días', dias: 7 },
    { etiqueta: '30 días', dias: 30 },
    { etiqueta: '90 días', dias: 90 },
];

function aplicarRangoRapido(dias: number) {
    const hoy = new Date();
    const hasta = new Date(hoy);
    hasta.setDate(hoy.getDate() + dias);

    rangoDesde.value = hoy.toISOString().slice(0, 10);
    rangoHasta.value = hasta.toISOString().slice(0, 10);
    navegar();
}
</script>

<template>
    <div class="flex w-full flex-col p-4 sm:px-6 lg:px-8">
    <CrudPageHeader
        titulo="Calendario de cumpleaños"
        descripcion="Vista mensual, tarjetas y felicitaciones de los colaboradores."
        :icono="Cake"
    >
        <Button
            v-if="permisos.gestionarFrases"
            variant="outline"
            size="sm"
            @click="dialogFrasesAbierto = true"
        >
            <Settings2 class="size-4" />
            Frases
        </Button>
        <Button v-if="permisos.configurar" as-child variant="outline" size="sm">
            <Link :href="configuracionIndex.url()">
                <Image class="size-4" />
                Fondo de tarjeta
            </Link>
        </Button>
    </CrudPageHeader>

    <div v-if="!config.enabled" class="mt-4">
        <Card class="border-dashed">
            <CardContent class="py-6 text-sm text-muted-foreground">
                El módulo de cumpleaños está deshabilitado
                (<code>CUMPLEANOS_ENABLED=false</code>). Los colaboradores no
                recibirán felicitaciones automáticas mientras esté apagado.
            </CardContent>
        </Card>
    </div>

    <template v-else>
        <!-- Cards de resumen -->
        <CrudStats
            class="mt-4"
            :estadisticas="[
                { etiqueta: 'Hoy cumplen', valor: hoy.length, icono: Sparkles, tono: 'success' },
                { etiqueta: 'Próximos 7 días', valor: totalProximos7, icono: Gift, tono: 'info' },
                { etiqueta: 'Próximos 30 días', valor: totalProximos30, icono: ListChecks },
                { etiqueta: `Total en ${MESES[mesActual - 1]}`, valor: delMes.length, icono: CalendarDays },
                { etiqueta: 'Sin fecha de nacimiento', valor: sinFechaNacimiento.length, icono: UserRoundX, tono: sinFechaNacimiento.length > 0 ? 'warning' : undefined },
            ]"
        />

        <!-- Alerta: colaboradores activos sin fecha_nacimiento capturada -->
        <Card
            v-if="sinFechaNacimiento.length > 0"
            class="mt-4 border-[var(--warning)]/40 bg-[var(--warning)]/5"
        >
            <CardHeader class="pb-3">
                <CardTitle class="flex items-center gap-2 text-base text-[var(--warning)]">
                    <TriangleAlert class="size-5" />
                    Hay {{ sinFechaNacimiento.length }} colaborador{{ sinFechaNacimiento.length === 1 ? '' : 'es' }}
                    activo{{ sinFechaNacimiento.length === 1 ? '' : 's' }} sin fecha de nacimiento
                </CardTitle>
            </CardHeader>
            <CardContent class="pt-0 text-sm text-muted-foreground">
                <p class="mb-2">
                    Completa sus datos en el expediente para que aparezcan en cumpleaños.
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <Badge
                        v-for="colaborador in sinFechaNacimiento"
                        :key="colaborador.id"
                        variant="outline"
                        class="border-[var(--warning)]/40 text-foreground"
                    >
                        {{ colaborador.nombre }}
                        <span v-if="colaborador.sucursal" class="text-muted-foreground">· {{ colaborador.sucursal }}</span>
                    </Badge>
                </div>
            </CardContent>
        </Card>

        <!-- Banner de hoy: siempre visible, nunca escondido en un tab -->
        <Card
            v-if="hoy.length > 0"
            class="mt-4 border-[var(--success)]/40 bg-[var(--success)]/5"
        >
            <CardHeader class="pb-3">
                <CardTitle class="flex items-center gap-2 text-base">
                    <Sparkles class="size-5 text-[var(--success)]" />
                    Hoy cumplen años ({{ hoy.length }})
                </CardTitle>
            </CardHeader>
            <CardContent
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <ColaboradorCumpleanosCard
                    v-for="colaborador in hoy"
                    :key="colaborador.id"
                    :colaborador="colaborador"
                    :puede-descargar="permisos.descargarImagen"
                    :puede-enviar="permisos.gestionarNotificaciones"
                    es-hoy
                    @copiar="copiarMensaje"
                />
            </CardContent>
        </Card>

        <!-- Filtros -->
        <Card class="mt-4">
            <CardContent class="flex flex-col gap-3 pt-6">
            <div
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5"
            >
                <div class="grid gap-1.5">
                    <Label>Sucursal</Label>
                    <Select v-model="sucursalSeleccionada" @update:model-value="navegar">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Todas" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="TODAS_SUCURSALES">Todas</SelectItem>
                            <SelectItem
                                v-for="s in opciones.sucursales"
                                :key="s.id"
                                :value="String(s.id)"
                            >
                                {{ s.nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-1.5">
                    <Label>Departamento</Label>
                    <Select v-model="departamentoSeleccionado" @update:model-value="navegar">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Todos" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="TODOS_DEPARTAMENTOS">Todos</SelectItem>
                            <SelectItem
                                v-for="d in opciones.departamentos"
                                :key="d.id"
                                :value="String(d.id)"
                            >
                                {{ d.nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-1.5">
                    <Label>Colaborador</Label>
                    <Combobox
                        v-model="filtros.colaborador_id"
                        :items="opcionesColaboradores"
                        placeholder="Todos"
                        empty-text="Sin colaboradores con estos filtros."
                        @update:model-value="navegar"
                    />
                </div>

                <div class="grid gap-1.5">
                    <Label>Estatus</Label>
                    <Select v-model="estatusSeleccionado" @update:model-value="navegar">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Activos" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="TODOS_ESTATUS">Activos</SelectItem>
                            <SelectItem value="activo">Activo</SelectItem>
                            <SelectItem value="en_incorporacion">En incorporación</SelectItem>
                            <SelectItem value="inactivo">Inactivo</SelectItem>
                            <SelectItem value="suspendido">Suspendido</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-1.5">
                    <Label>Buscar</Label>
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="filtros.busqueda"
                            placeholder="Nombre o número de empleado"
                            class="pl-8"
                            @input="navegarConDebounce"
                        />
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <Button
                    variant="secondary"
                    size="sm"
                    :disabled="!hayFiltrosActivos"
                    @click="limpiarFiltros"
                >
                    <FilterX class="size-4" />
                    Limpiar filtros
                </Button>
            </div>
            </CardContent>
        </Card>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-4">
            <!-- Calendario grande -->
            <Card class="lg:col-span-3">
                <CardHeader
                    class="flex-row items-center justify-between gap-2 space-y-0"
                >
                    <div class="flex items-center gap-1">
                        <Button variant="ghost" size="icon" @click="mesAnterior">
                            <ChevronLeft class="size-4" />
                        </Button>
                        <CardTitle class="min-w-[11rem] text-center text-lg">
                            {{ MESES[mesActual - 1] }} {{ anioActual }}
                        </CardTitle>
                        <Button variant="ghost" size="icon" @click="mesSiguiente">
                            <ChevronRight class="size-4" />
                        </Button>
                    </div>

                    <div class="flex items-center gap-2">
                        <Select
                            :model-value="String(mesActual)"
                            @update:model-value="
                                (v) => {
                                    mesActual = Number(v);
                                    navegar();
                                }
                            "
                        >
                            <SelectTrigger class="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="(nombre, i) in MESES"
                                    :key="i"
                                    :value="String(i + 1)"
                                >
                                    {{ nombre }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Input
                            type="number"
                            :model-value="anioActual"
                            class="w-24"
                            @change="
                                (e: Event) => {
                                    anioActual = Number(
                                        (e.target as HTMLInputElement).value,
                                    );
                                    navegar();
                                }
                            "
                        />
                        <Button variant="outline" size="sm" @click="irAHoy">
                            Hoy
                        </Button>
                    </div>
                </CardHeader>

                <CardContent>
                    <div v-if="!permisos.calendario" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No tienes permiso para ver el calendario completo.
                    </div>

                    <template v-else>
                        <!-- Escritorio / tablet: calendario tipo Google Calendar -->
                        <div class="hidden sm:block">
                            <div class="grid grid-cols-7 gap-px overflow-hidden rounded-lg border bg-border text-center text-xs font-medium text-muted-foreground">
                                <div
                                    v-for="d in DIAS_SEMANA"
                                    :key="d"
                                    class="bg-muted/50 py-2"
                                >
                                    {{ d }}
                                </div>
                            </div>

                            <div
                                v-for="(semana, i) in semanas"
                                :key="i"
                                class="grid grid-cols-7 gap-px bg-border"
                                :class="i === semanas.length - 1 ? 'rounded-b-lg overflow-hidden' : ''"
                            >
                                <button
                                    v-for="(celda, j) in semana"
                                    :key="j"
                                    type="button"
                                    class="flex min-h-24 flex-col items-stretch gap-1 bg-background p-1.5 text-left transition-all lg:min-h-28"
                                    :class="[
                                        celda.dia === null && 'bg-muted/20',
                                        celda.colaboradores.length > 0 &&
                                            'cursor-pointer hover:bg-primary/[0.06] hover:shadow-[inset_0_0_0_1px_var(--primary)]/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-inset',
                                        celda.colaboradores.length === 0 && 'cursor-default',
                                        esHoy(celda.dia) && 'ring-2 ring-inset ring-primary/30',
                                    ]"
                                    :disabled="celda.colaboradores.length === 0"
                                    @click="abrirDia(celda)"
                                >
                                    <span
                                        v-if="celda.dia !== null"
                                        class="flex size-6 items-center justify-center rounded-full text-xs font-medium"
                                        :class="
                                            esHoy(celda.dia)
                                                ? 'bg-primary text-primary-foreground shadow-sm'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{ celda.dia }}
                                    </span>

                                    <div
                                        v-if="celda.colaboradores.length > 0"
                                        class="flex flex-col gap-1"
                                    >
                                        <div
                                            v-for="c in celda.colaboradores.slice(0, 2)"
                                            :key="c.id"
                                            class="flex items-center gap-1.5 rounded-full bg-primary/10 py-0.5 pr-2 pl-0.5 text-[11px] font-medium text-primary"
                                        >
                                            <Avatar class="size-5 shrink-0 ring-1 ring-background">
                                                <AvatarImage v-if="c.foto_url" :src="c.foto_url" :alt="c.nombre" />
                                                <AvatarFallback class="text-[9px]">{{ getInitials(c.nombre) }}</AvatarFallback>
                                            </Avatar>
                                            <span class="truncate">{{ c.nombre.split(' ')[0] }}</span>
                                        </div>
                                        <span
                                            v-if="celda.colaboradores.length > 2"
                                            class="text-[11px] font-medium text-muted-foreground"
                                        >
                                            +{{ celda.colaboradores.length - 2 }} más
                                        </span>
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- Movil: lista agrupada por dia -->
                        <div class="flex flex-col gap-3 sm:hidden">
                            <div
                                v-if="diasConDatos.length === 0"
                                class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                            >
                                Sin cumpleaños que mostrar en
                                {{ MESES[mesActual - 1] }}.
                            </div>
                            <div
                                v-for="grupo in diasConDatos"
                                :key="grupo.dia"
                                class="rounded-xl border p-3"
                                :class="esHoy(grupo.dia) && 'border-[var(--success)]/40 bg-[var(--success)]/5'"
                            >
                                <p class="mb-2 flex items-center gap-1.5 text-sm font-semibold">
                                    {{ grupo.dia }} de {{ MESES[mesActual - 1] }}
                                    <Badge v-if="esHoy(grupo.dia)" variant="outline" class="border-[var(--success)]/40 text-[var(--success)]">Hoy</Badge>
                                </p>
                                <div class="flex flex-col gap-2">
                                    <ColaboradorCumpleanosCard
                                        v-for="colaborador in grupo.colaboradores"
                                        :key="colaborador.id"
                                        :colaborador="colaborador"
                                        :puede-descargar="permisos.descargarImagen"
                                        :puede-enviar="permisos.gestionarNotificaciones"
                                        compacto
                                        @copiar="copiarMensaje"
                                    />
                                </div>
                            </div>
                        </div>
                    </template>
                </CardContent>
            </Card>

            <!-- Sidebar: proximos cumpleaños, con rango de fechas libre -->
            <Card class="lg:col-span-1">
                <CardHeader class="gap-3 space-y-0 pb-3">
                    <CardTitle class="text-base">Próximos cumpleaños</CardTitle>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="grid gap-1">
                            <Label class="text-xs text-muted-foreground">Desde</Label>
                            <Input
                                v-model="rangoDesde"
                                type="date"
                                class="h-8 text-xs"
                                @change="navegarRangoConDebounce"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs text-muted-foreground">Hasta</Label>
                            <Input
                                v-model="rangoHasta"
                                type="date"
                                :min="rangoDesde"
                                class="h-8 text-xs"
                                @change="navegarRangoConDebounce"
                            />
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="opcion in RANGOS_RAPIDOS"
                            :key="opcion.dias"
                            type="button"
                            class="rounded-full border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            @click="aplicarRangoRapido(opcion.dias)"
                        >
                            {{ opcion.etiqueta }}
                        </button>
                    </div>
                </CardHeader>
                <CardContent class="flex max-h-[30rem] flex-col gap-2 overflow-y-auto">
                    <p
                        v-if="proximosRango.length === 0"
                        class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        No hay cumpleaños en este rango.
                    </p>
                    <ColaboradorCumpleanosCard
                        v-for="colaborador in proximosRango"
                        :key="colaborador.id"
                        :colaborador="colaborador"
                        :puede-descargar="permisos.descargarImagen"
                        :puede-enviar="permisos.gestionarNotificaciones"
                        compacto
                        @copiar="copiarMensaje"
                    />
                </CardContent>
            </Card>
        </div>

        <!-- Lista completa del mes: no solo las tarjetas del calendario grande -->
        <Card class="mt-4">
            <CardHeader>
                <CardTitle class="text-base">
                    Lista de cumpleaños de {{ MESES[mesActual - 1] }}
                </CardTitle>
                <p class="text-xs text-muted-foreground">
                    {{ delMes.length }} colaborador{{ delMes.length === 1 ? '' : 'es' }}
                    con cumpleaños este mes, con los filtros aplicados.
                </p>
            </CardHeader>
            <CardContent>
                <p
                    v-if="delMes.length === 0"
                    class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    Sin cumpleaños que mostrar en {{ MESES[mesActual - 1] }} con estos filtros.
                </p>
                <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <ColaboradorCumpleanosCard
                        v-for="colaborador in delMes"
                        :key="colaborador.id"
                        :colaborador="colaborador"
                        :puede-descargar="permisos.descargarImagen"
                        :puede-enviar="permisos.gestionarNotificaciones"
                        compacto
                        @copiar="copiarMensaje"
                    />
                </div>
            </CardContent>
        </Card>
    </template>
    </div>

    <!-- Dialog: detalle de un dia del calendario -->
    <Dialog :open="diaSeleccionado !== null" @update:open="(v) => !v && (diaSeleccionado = null)">
        <DialogContent
            class="max-h-[85vh] w-[calc(100vw-2rem)] overflow-x-hidden overflow-y-auto sm:max-w-xl lg:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle>
                    {{ diaSeleccionado?.dia }} de {{ MESES[mesActual - 1] }}
                </DialogTitle>
                <DialogDescription>
                    {{ diaSeleccionado?.colaboradores.length }} colaborador(es) cumplen años este día.
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-2">
                <ColaboradorCumpleanosCard
                    v-for="colaborador in diaSeleccionado?.colaboradores ?? []"
                    :key="colaborador.id"
                    :colaborador="colaborador"
                    :puede-descargar="permisos.descargarImagen"
                    :puede-enviar="permisos.gestionarNotificaciones"
                    @copiar="copiarMensaje"
                />
            </div>
        </DialogContent>
    </Dialog>

    <!-- Dialog: gestion de frases -->
    <Dialog v-model:open="dialogFrasesAbierto">
        <DialogContent
            class="flex max-h-[85vh] w-[calc(100vw-2rem)] flex-col overflow-hidden sm:max-w-2xl lg:max-w-4xl"
        >
            <DialogHeader class="shrink-0">
                <DialogTitle>Frases de felicitación</DialogTitle>
                <DialogDescription>
                    Las frases activas rotan automáticamente para no repetir siempre la misma.
                </DialogDescription>
            </DialogHeader>

            <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                <Input
                    v-model="nuevaFrase.texto"
                    placeholder="Escribe una nueva frase..."
                    class="flex-1"
                    @keyup.enter="agregarFrase"
                />
                <EmojiPicker @select="(emoji) => (nuevaFrase.texto += emoji)" />
                <Button
                    class="shrink-0"
                    :disabled="nuevaFrase.processing || !nuevaFrase.texto"
                    @click="agregarFrase"
                >
                    <Spinner v-if="nuevaFrase.processing" />
                    <Plus v-else class="size-4" />
                    Agregar frase
                </Button>
            </div>

            <div class="-mx-1 flex-1 overflow-y-auto overflow-x-hidden px-1">
                <p
                    v-if="opciones.frases.length === 0"
                    class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Todavía no hay frases. Agrega la primera arriba.
                </p>

                <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="frase in opciones.frases"
                        :key="frase.id"
                        class="rounded-xl border p-3"
                        :class="!frase.activo && 'bg-muted/30'"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 flex-1 text-sm break-words whitespace-normal">
                                {{ frase.texto }}
                            </p>
                            <Badge
                                :variant="frase.activo ? 'default' : 'outline'"
                                class="shrink-0"
                            >
                                {{ frase.activo ? 'Activa' : 'Inactiva' }}
                            </Badge>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs text-muted-foreground">
                                Usada {{ frase.usado_count }} {{ frase.usado_count === 1 ? 'vez' : 'veces' }}
                            </p>
                            <div class="flex items-center gap-2">
                                <Button size="sm" variant="outline" @click="alternarFrase(frase)">
                                    {{ frase.activo ? 'Desactivar' : 'Activar' }}
                                </Button>
                                <Button size="sm" variant="ghost" @click="eliminarFrase(frase)">
                                    <Trash2 class="size-4 text-destructive" />
                                    Eliminar
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <DialogFooter class="shrink-0">
                <Button variant="secondary" @click="dialogFrasesAbierto = false">
                    Cerrar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
