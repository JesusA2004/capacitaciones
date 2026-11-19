<script setup lang="ts">
import { Deferred, Head, router, useForm } from '@inertiajs/vue3';
import {
    Award,
    BadgeCheck,
    CircleDashed,
    Download,
    Eye,
    FileDown,
    FileSignature,
    Info,
    PenLine,
    Search,
    TriangleAlert,
    Upload,
    Users,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import InsigniaNivel from '@/components/Capacitacion/InsigniaNivel.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import DashboardChartCard from '@/components/Dashboard/DashboardChartCard.vue';
import DescargaEnProgreso from '@/components/DescargaEnProgreso.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { Label } from '@/components/ui/label';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useDescargaArchivo } from '@/composables/useDescargaArchivo';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import {
    certificacion as rutaCertificacion,
    index as indexCapacitacion,
} from '@/routes/capacitacion';
import {
    certificado as rutaCertificado,
    firmado as rutaFirmado,
    formato as rutaFormato,
    subirFirmado,
} from '@/routes/capacitacion/certificacion';
import type {
    EstadoCertificacionFormato,
    GestionCertificacionFormato,
    NivelCertificacionItem,
    PersonaCertificacionFormato,
    TableroCertificacion,
} from '@/types';

const props = defineProps<{
    niveles: NivelCertificacionItem[];
    tablero?: TableroCertificacion;
    puede_gestionar: boolean;
    gestion?: GestionCertificacionFormato | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Certificación Mr. Lana', href: rutaCertificacion() },
        ],
    },
});

const distribucion = computed(() => props.tablero?.distribucion ?? []);

// ───── Lista de personas (formato RH-CC-R-001) ─────
type FiltroEstado = 'todos' | EstadoCertificacionFormato;

const busqueda = ref('');
const filtroSucursal = ref<string | null>(null);
const filtroPuesto = ref<string | null>(null);
const filtroEstado = ref<FiltroEstado>('todos');
const POR_PAGINA = 60;
const limite = ref(POR_PAGINA);

const personas = computed(() => props.gestion?.personas ?? []);

function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

function opcionesDe(campo: 'sucursal' | 'puesto'): OpcionBuscable[] {
    const valores = new Set<string>();

    for (const persona of personas.value) {
        const valor = persona[campo];

        if (valor) {
            valores.add(valor);
        }
    }

    return [...valores]
        .sort((a, b) => a.localeCompare(b, 'es'))
        .map((valor) => ({ value: valor, label: valor }));
}

const opcionesSucursal = computed(() => opcionesDe('sucursal'));
const opcionesPuesto = computed(() => opcionesDe('puesto'));

const filtradas = computed(() => {
    const palabras = normalizar(busqueda.value).split(/\s+/).filter(Boolean);

    return personas.value.filter((persona) => {
        if (
            filtroEstado.value !== 'todos' &&
            persona.estado !== filtroEstado.value
        ) {
            return false;
        }

        if (filtroSucursal.value && persona.sucursal !== filtroSucursal.value) {
            return false;
        }

        if (filtroPuesto.value && persona.puesto !== filtroPuesto.value) {
            return false;
        }

        if (palabras.length === 0) {
            return true;
        }

        const texto = normalizar(
            [
                persona.nombre,
                persona.numero_empleado ?? '',
                persona.puesto ?? '',
                persona.sucursal ?? '',
                persona.folio ?? '',
            ].join(' '),
        );

        return palabras.every((palabra) => texto.includes(palabra));
    });
});

const visibles = computed(() => filtradas.value.slice(0, limite.value));

function elegirEstado(estado: FiltroEstado) {
    filtroEstado.value = filtroEstado.value === estado ? 'todos' : estado;
    limite.value = POR_PAGINA;
}

const hayFiltros = computed(
    () =>
        busqueda.value !== '' ||
        filtroSucursal.value !== null ||
        filtroPuesto.value !== null ||
        filtroEstado.value !== 'todos',
);

function limpiarFiltros() {
    busqueda.value = '';
    filtroSucursal.value = null;
    filtroPuesto.value = null;
    filtroEstado.value = 'todos';
    limite.value = POR_PAGINA;
}

const ESTADOS: Record<
    EstadoCertificacionFormato,
    { clase: string; icono: typeof Award }
> = {
    sin_formato: {
        clase: 'bg-muted text-muted-foreground',
        icono: CircleDashed,
    },
    formato_descargado: {
        clase: 'bg-warning-soft text-bronce',
        icono: PenLine,
    },
    certificado: {
        clase: 'bg-success-soft text-primary',
        icono: BadgeCheck,
    },
};

// ───── Descargar formato con el encabezado lleno ─────
const descarga = useDescargaArchivo();
const descargandoDe = ref<PersonaCertificacionFormato | null>(null);

async function descargarFormato(persona: PersonaCertificacionFormato) {
    descargandoDe.value = persona;
    const ok = await descarga.descargar(rutaFormato.url(persona.id), {
        nombreRespaldo: `Certificacion Mr Lana - ${persona.nombre}.pdf`,
    });

    if (ok) {
        router.reload({ only: ['gestion'] });
    }
}

// ───── Subir formato firmado ─────
const subirAbierto = ref(false);
const personaSubir = ref<PersonaCertificacionFormato | null>(null);
const archivoUrl = ref<string | null>(null);
const archivoTipo = ref('');
const form = useForm<{
    archivo: File | null;
    promedio: string | number;
    confirmo_firmas: boolean;
}>({
    archivo: null,
    promedio: '',
    confirmo_firmas: false,
});

const promedioMinimo = computed(() => props.gestion?.promedio_minimo ?? 80);
const promedioBajo = computed(
    () =>
        form.promedio !== '' &&
        Number.isFinite(Number(form.promedio)) &&
        Number(form.promedio) < promedioMinimo.value,
);

function limpiarArchivo() {
    if (archivoUrl.value !== null) {
        URL.revokeObjectURL(archivoUrl.value);
    }

    archivoUrl.value = null;
    archivoTipo.value = '';
}

onBeforeUnmount(limpiarArchivo);

function abrirSubir(persona: PersonaCertificacionFormato) {
    personaSubir.value = persona;
    form.reset();
    form.clearErrors();
    limpiarArchivo();
    subirAbierto.value = true;
}

function elegirArchivo(evento: Event) {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    limpiarArchivo();
    form.archivo = archivo;

    if (
        archivo &&
        (archivo.type.startsWith('image/') ||
            archivo.type === 'application/pdf')
    ) {
        archivoUrl.value = URL.createObjectURL(archivo);
        archivoTipo.value = archivo.type;
    }
}

function enviarFirmado() {
    if (personaSubir.value === null) {
        return;
    }

    form.post(subirFirmado.url(personaSubir.value.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            subirAbierto.value = false;
            limpiarArchivo();
        },
    });
}
</script>

<template>
    <Head title="Certificación Mr. Lana" />

    <div class="pagina-ancha flex flex-col gap-6">
        <section
            class="relative entrada-suave overflow-hidden rounded-3xl border border-oro/30 bg-linear-to-br from-crema via-card to-salvia p-6 shadow-sm sm:p-8"
        >
            <span
                class="pointer-events-none absolute -top-12 -right-12 size-52 rounded-full bg-oro/10"
                aria-hidden="true"
            />
            <div class="relative flex flex-col gap-5">
                <Heading
                    title="Certificación Mr. Lana"
                    :description="
                        puede_gestionar
                            ? 'Descarga el formato oficial con los datos de la persona, se llena y firma a pluma, súbelo firmado y se emite su certificado.'
                            : 'Cada constancia de capacitación suma hacia el siguiente nivel.'
                    "
                />
                <ol
                    v-if="puede_gestionar"
                    class="grid grid-cols-1 gap-3 md:grid-cols-3"
                    aria-label="Cómo se certifica"
                >
                    <li
                        v-for="(paso, i) in [
                            {
                                icono: FileDown,
                                titulo: 'Descarga el formato',
                                texto: `${gestion?.formato_clave ?? 'RH-CC-R-001'} con nombre, sucursal y fecha de ingreso ya escritos.`,
                            },
                            {
                                icono: PenLine,
                                titulo: 'Se llena y firma a pluma',
                                texto: 'Temas, fechas, evaluación por módulo y promedio, con las firmas del gestor y su gerente.',
                            },
                            {
                                icono: Award,
                                titulo: 'Sube el firmado',
                                texto: 'La persona queda certificada y se emite su certificado con folio verificable.',
                            },
                        ]"
                        :key="paso.titulo"
                        class="flex items-start gap-3 rounded-2xl bg-card/80 p-4 shadow-sm"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                        >
                            <component :is="paso.icono" class="size-4.5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                {{ i + 1 }}. {{ paso.titulo }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ paso.texto }}
                            </p>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

        <!-- ───── Gestión del formato RH-CC-R-001 ───── -->
        <Deferred v-if="puede_gestionar" data="gestion">
            <template #fallback>
                <div class="grid gap-4" aria-busy="true">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Skeleton
                            v-for="i in 4"
                            :key="i"
                            class="h-24 rounded-2xl"
                        />
                    </div>
                    <Skeleton class="h-96 rounded-2xl" />
                </div>
            </template>

            <template v-if="gestion">
                <div
                    v-if="!gestion.formato_disponible"
                    class="flex items-start gap-3 rounded-2xl border border-warning/40 bg-warning-soft p-4 text-sm"
                    role="alert"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0 text-bronce" />
                    El formato oficial {{ gestion.formato_clave }} no está
                    cargado en el servidor: no se puede descargar todavía. Avisa
                    a Sistemas.
                </div>

                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <button
                        v-for="tarjeta in [
                            {
                                estado: 'todos' as FiltroEstado,
                                etiqueta: 'Personas en tu alcance',
                                valor: gestion.resumen.total,
                                icono: Users,
                                color: 'bg-salvia text-primary',
                            },
                            {
                                estado: 'sin_formato' as FiltroEstado,
                                etiqueta: 'Sin formato',
                                valor: gestion.resumen.sin_formato,
                                icono: CircleDashed,
                                color: 'bg-muted text-muted-foreground',
                            },
                            {
                                estado: 'formato_descargado' as FiltroEstado,
                                etiqueta: 'Formato descargado',
                                valor: gestion.resumen.formato_descargado,
                                icono: PenLine,
                                color: 'bg-warning-soft text-bronce',
                            },
                            {
                                estado: 'certificado' as FiltroEstado,
                                etiqueta: 'Certificadas',
                                valor: gestion.resumen.certificados,
                                icono: BadgeCheck,
                                color: 'bg-success-soft text-primary',
                            },
                        ]"
                        :key="tarjeta.estado"
                        type="button"
                        class="tarjeta-interactiva rounded-2xl text-left"
                        :class="
                            filtroEstado === tarjeta.estado &&
                            tarjeta.estado !== 'todos'
                                ? 'ring-2 ring-oro/60'
                                : ''
                        "
                        :aria-pressed="filtroEstado === tarjeta.estado"
                        @click="elegirEstado(tarjeta.estado)"
                    >
                        <MetricCard
                            :etiqueta="tarjeta.etiqueta"
                            :valor="tarjeta.valor"
                            :icono="tarjeta.icono"
                            :color-clase="tarjeta.color"
                        />
                    </button>
                </div>

                <section
                    class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:p-5"
                >
                    <div
                        class="flex flex-col gap-3 lg:flex-row lg:items-center"
                    >
                        <div class="relative flex-1">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                v-model="busqueda"
                                type="search"
                                class="pl-9"
                                placeholder="Buscar por nombre, número, puesto, sucursal o folio…"
                                aria-label="Buscar persona"
                                @update:model-value="limite = POR_PAGINA"
                            />
                        </div>
                        <ComboboxBuscable
                            v-model="filtroSucursal"
                            :opciones="opcionesSucursal"
                            placeholder="Todas las sucursales"
                            placeholder-busqueda="Buscar sucursal…"
                            aria-label="Filtrar por sucursal"
                            limpiable
                            class="lg:w-64"
                        />
                        <ComboboxBuscable
                            v-model="filtroPuesto"
                            :opciones="opcionesPuesto"
                            placeholder="Todos los puestos"
                            placeholder-busqueda="Buscar puesto…"
                            aria-label="Filtrar por puesto"
                            limpiable
                            class="lg:w-64"
                        />
                        <Button
                            v-if="hayFiltros"
                            variant="ghost"
                            size="sm"
                            @click="limpiarFiltros"
                        >
                            Limpiar
                        </Button>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{ filtradas.length }}
                        {{ filtradas.length === 1 ? 'persona' : 'personas' }}
                        <template v-if="hayFiltros">
                            con estos filtros</template
                        >
                    </p>

                    <EmptyState
                        v-if="filtradas.length === 0"
                        :icono="Award"
                        :titulo="
                            hayFiltros
                                ? 'Nadie coincide con los filtros'
                                : 'Sin personas en tu alcance'
                        "
                        descripcion="Prueba con otro nombre, sucursal o puesto."
                    />

                    <ListaAnimada
                        v-else
                        tag="ul"
                        :cantidad="visibles.length"
                        class="flex flex-col divide-y divide-border/60"
                    >
                        <li
                            v-for="persona in visibles"
                            :key="persona.id"
                            class="flex flex-col gap-3 py-3 md:flex-row md:items-center"
                        >
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <ColaboradorAvatar
                                    :nombre="persona.nombre"
                                    :foto-url="persona.foto_url"
                                    tamano="sm"
                                />
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ persona.nombre }}
                                        <span
                                            v-if="!persona.activo"
                                            class="ml-1 text-xs font-normal text-muted-foreground"
                                            >(inactivo)</span
                                        >
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            [
                                                persona.puesto,
                                                persona.sucursal,
                                                persona.fecha_ingreso
                                                    ? `Ingreso ${formatearFecha(persona.fecha_ingreso)}`
                                                    : 'Sin fecha de ingreso',
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="flex flex-wrap items-center gap-2 md:justify-end"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="ESTADOS[persona.estado].clase"
                                    :title="
                                        persona.estado === 'certificado' &&
                                        persona.certificado_en
                                            ? `Certificado el ${formatearFecha(persona.certificado_en)} · folio ${persona.folio}`
                                            : persona.formato_descargado_en
                                              ? `Formato descargado el ${formatearFecha(persona.formato_descargado_en)}`
                                              : undefined
                                    "
                                >
                                    <component
                                        :is="ESTADOS[persona.estado].icono"
                                        class="size-3.5"
                                    />
                                    {{ persona.estado_etiqueta }}
                                    <template
                                        v-if="
                                            persona.estado === 'certificado' &&
                                            persona.promedio !== null
                                        "
                                    >
                                        · {{ persona.promedio }}</template
                                    >
                                </span>

                                <template
                                    v-if="persona.estado !== 'certificado'"
                                >
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        :disabled="
                                            !gestion.formato_disponible ||
                                            descarga.enProgreso.value
                                        "
                                        @click="descargarFormato(persona)"
                                    >
                                        <Download class="size-3.5" />
                                        {{
                                            persona.estado === 'sin_formato'
                                                ? 'Descargar formato'
                                                : 'Descargar de nuevo'
                                        }}
                                    </Button>
                                    <Button
                                        size="sm"
                                        @click="abrirSubir(persona)"
                                    >
                                        <Upload class="size-3.5" />
                                        Subir firmado
                                    </Button>
                                </template>
                                <template v-else>
                                    <Button
                                        v-if="persona.tiene_firmado"
                                        size="sm"
                                        variant="ghost"
                                        as="a"
                                        :href="rutaFirmado.url(persona.id)"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <FileSignature class="size-3.5" />
                                        Formato firmado
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        as="a"
                                        :href="rutaCertificado.url(persona.id)"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <Eye class="size-3.5" />
                                        Ver certificado
                                    </Button>
                                    <Button
                                        size="sm"
                                        as="a"
                                        :href="
                                            rutaCertificado.url(persona.id, {
                                                query: { descargar: 1 },
                                            })
                                        "
                                    >
                                        <Award class="size-3.5" />
                                        Descargar
                                    </Button>
                                </template>
                            </div>
                        </li>
                    </ListaAnimada>

                    <div
                        v-if="filtradas.length > visibles.length"
                        class="flex justify-center"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="limite += POR_PAGINA"
                        >
                            Ver más ({{ filtradas.length - visibles.length }}
                            restantes)
                        </Button>
                    </div>
                </section>
            </template>
        </Deferred>

        <!-- ───── Niveles por constancias de cursos ───── -->
        <section class="flex flex-col gap-3">
            <h2 class="text-base font-semibold">
                Niveles por constancias de cursos
            </h2>
            <ul class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <li
                    v-for="nivel in niveles"
                    :key="nivel.clave"
                    class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <InsigniaNivel
                        :nivel="nivel.clave"
                        :etiqueta="nivel.etiqueta"
                        tamano="lg"
                        class="self-start"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{ nivel.descripcion }}
                    </p>
                    <p class="text-xs font-medium text-bronce">
                        Desde {{ nivel.umbral }}
                        {{ nivel.umbral === 1 ? 'constancia' : 'constancias' }}
                    </p>
                </li>
            </ul>
            <p class="flex items-start gap-2 text-xs text-muted-foreground">
                <Info class="mt-0.5 size-3.5 shrink-0" />
                Base inicial: el nivel se calcula con las constancias emitidas.
                Las rutas de cursos por puesto se definirán con RH.
            </p>
        </section>

        <Deferred data="tablero">
            <template #fallback>
                <div class="grid gap-4" aria-busy="true">
                    <div class="grid grid-cols-2 gap-3">
                        <Skeleton class="h-24 rounded-2xl" />
                        <Skeleton class="h-24 rounded-2xl" />
                    </div>
                    <Skeleton class="h-64 rounded-2xl" />
                </div>
            </template>

            <template v-if="tablero">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <MetricCard
                        etiqueta="Personas con nivel"
                        :valor="tablero.total_certificados"
                        :icono="Users"
                        color-clase="bg-salvia text-primary"
                    />
                    <MetricCard
                        etiqueta="Constancias emitidas"
                        :valor="tablero.total_constancias"
                        :icono="Award"
                        color-clase="bg-oro/20 text-bronce"
                    />
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <DashboardChartCard
                        title="Personas por nivel"
                        type="donut"
                        :data="distribucion"
                        label-key="etiqueta"
                        value-key="personas"
                        :colors="[
                            'var(--pastel-bronce)',
                            'var(--pastel-esmeralda)',
                            'var(--pastel-oro)',
                        ]"
                        empty-title="Nadie con nivel todavía"
                        empty-description="Los niveles aparecen cuando se emiten constancias."
                    />

                    <section
                        class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-5 shadow-sm lg:col-span-2"
                    >
                        <h2 class="text-base font-semibold">
                            Quién va adelante
                        </h2>
                        <EmptyState
                            v-if="tablero.personas.length === 0"
                            :icono="Award"
                            titulo="Sin constancias en tu alcance"
                            descripcion="Cuando tu gente termine cursos con constancia, la verás aquí."
                        />
                        <ListaAnimada
                            v-else
                            tag="ol"
                            :cantidad="tablero.personas.length"
                            class="flex flex-col divide-y divide-border/60"
                        >
                            <li
                                v-for="(persona, i) in tablero.personas"
                                :key="persona.id"
                                class="flex items-center gap-3 py-2.5"
                            >
                                <span
                                    class="w-6 text-right text-xs font-semibold text-muted-foreground tabular-nums"
                                    >{{ i + 1 }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium">
                                        {{ persona.nombre }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ persona.constancias }}
                                        {{
                                            persona.constancias === 1
                                                ? 'constancia'
                                                : 'constancias'
                                        }}
                                        <template v-if="persona.ultima">
                                            · última
                                            {{ formatearFecha(persona.ultima) }}
                                        </template>
                                    </p>
                                </div>
                                <InsigniaNivel
                                    v-if="persona.nivel"
                                    :nivel="persona.nivel.clave"
                                    :etiqueta="persona.nivel.etiqueta"
                                />
                            </li>
                        </ListaAnimada>
                    </section>
                </div>
            </template>
        </Deferred>
    </div>

    <DescargaEnProgreso
        :estado="descarga.estado.value"
        :error="descarga.error.value"
        :nombre-archivo="descarga.nombreArchivo.value"
        titulo="Preparando el formato de certificación"
        :sujeto="descargandoDe?.nombre ?? null"
        descripcion="Llenando nombre, sucursal y fecha de ingreso en el formato oficial…"
        @cancelar="descarga.cancelar"
        @reintentar="descarga.reintentar"
        @cerrar="descarga.cerrar"
    />

    <Dialog v-model:open="subirAbierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Subir formato firmado</DialogTitle>
                <DialogDescription>
                    Escaneo o foto del formato de
                    {{ personaSubir?.nombre }} ya llenado y firmado. Queda en su
                    expediente y se emite su certificado.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="enviarFirmado">
                <div class="flex flex-col gap-1.5">
                    <Label for="archivo-certificacion"
                        >Archivo (PDF o imagen)</Label
                    >
                    <input
                        id="archivo-certificacion"
                        type="file"
                        accept="application/pdf,image/*"
                        class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-primary"
                        @change="elegirArchivo"
                    />
                    <InputError :message="form.errors.archivo" />
                </div>

                <div
                    v-if="archivoUrl"
                    class="overflow-hidden rounded-xl border border-border/60 bg-crema/30"
                >
                    <img
                        v-if="archivoTipo.startsWith('image/')"
                        :src="archivoUrl"
                        alt="Vista previa del formato firmado"
                        class="mx-auto max-h-[55vh] object-contain"
                    />
                    <iframe
                        v-else
                        :src="archivoUrl"
                        title="Vista previa del formato firmado"
                        class="h-[55vh] w-full bg-card"
                    />
                </div>

                <div class="flex flex-col gap-1.5 sm:max-w-xs">
                    <Label for="promedio-certificacion"
                        >Promedio del formato</Label
                    >
                    <Input
                        id="promedio-certificacion"
                        v-model="form.promedio"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        max="100"
                        step="0.01"
                        placeholder="Ej. 92"
                    />
                    <p
                        class="text-xs"
                        :class="
                            promedioBajo
                                ? 'font-medium text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        Mínimo {{ promedioMinimo }} para asumir la certificación
                        (nota del formato).
                    </p>
                    <InputError :message="form.errors.promedio" />
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        class="mt-0.5"
                        :model-value="form.confirmo_firmas"
                        @update:model-value="
                            (v) => (form.confirmo_firmas = !!v)
                        "
                    />
                    Confirmo que el formato tiene las firmas del gestor y del
                    gerente de sucursal, y «Certificado: SÍ».
                </label>
                <InputError :message="form.errors.confirmo_firmas" />

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        @click="subirAbierto = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="
                            !form.archivo ||
                            form.promedio === '' ||
                            promedioBajo ||
                            !form.confirmo_firmas ||
                            form.processing
                        "
                    >
                        <Award class="size-4" />
                        {{
                            form.processing
                                ? 'Certificando…'
                                : 'Subir y certificar'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
