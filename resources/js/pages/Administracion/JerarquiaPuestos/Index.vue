<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    GitBranch,
    History,
    MapPin,
    Network,
    Pencil,
    Search,
    Users,
} from '@lucide/vue';
import { computed, provide, ref, watch } from 'vue';
import type { Component } from 'vue';
import AgregarSubordinadoDialog from '@/components/Administracion/AgregarSubordinadoDialog.vue';
import JerarquiaPuestoDialog from '@/components/Administracion/JerarquiaPuestoDialog.vue';
import OrganigramaAccordion from '@/components/Administracion/OrganigramaAccordion.vue';
import OrganigramaArbol from '@/components/Administracion/OrganigramaArbol.vue';
import OrganigramaPersona from '@/components/Administracion/OrganigramaPersona.vue';
import OrganigramaPersonasArbol from '@/components/Administracion/OrganigramaPersonasArbol.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { getJson } from '@/lib/http';
import {
    CLAVE_ACCIONES_ORGANIGRAMA,
    CLAVE_BUSQUEDA_ORGANIGRAMA,
    coincideBusqueda,
} from '@/lib/organigrama';
import { dashboard } from '@/routes';
import {
    actualizar,
    historial as historialUrl,
    index,
} from '@/routes/administracion/jerarquia-puestos';
import {
    finalizar as finalizarCobertura,
    store as storeCobertura,
} from '@/routes/administracion/jerarquia-puestos/coberturas';
import { index as indexMatrizComercial } from '@/routes/administracion/matriz-comercial';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import type {
    CoberturasOrganigrama,
    NodoOrganigramaPersona,
    PuestoHistorialResponse,
    PuestoJerarquiaFiltros,
    PuestoJerarquiaItem,
    PuestoJerarquiaOpciones,
} from '@/types';

const props = defineProps<{
    puestos: PuestoJerarquiaItem[];
    personas: NodoOrganigramaPersona[];
    coberturas: CoberturasOrganigrama;
    filtros: PuestoJerarquiaFiltros;
    opciones: PuestoJerarquiaOpciones;
}>();

// "Por personas" (una tarjeta por colaborador, rama por sucursal) es la
// vista principal; "Por puestos" se conserva para editar la jerarquía.
type Vista = 'personas' | 'puestos';

const VISTAS: { valor: Vista; etiqueta: string; icono: Component }[] = [
    { valor: 'personas', etiqueta: 'Por personas', icono: Users },
    { valor: 'puestos', etiqueta: 'Por puestos', icono: Network },
];

const CLAVE_VISTA = 'organigrama-vista';

function leerVista(): Vista {
    try {
        return localStorage.getItem(CLAVE_VISTA) === 'puestos'
            ? 'puestos'
            : 'personas';
    } catch {
        return 'personas';
    }
}

const vista = ref<Vista>(leerVista());

watch(vista, (valor) => {
    try {
        localStorage.setItem(CLAVE_VISTA, valor);
    } catch {
        // Sin localStorage (modo privado): solo no se recuerda la elección.
    }
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Organigrama', href: index.url() },
        ],
    },
});

const TIPO_ETIQUETA: Record<string, string> = {
    comercial: 'Comercial',
    administrativo: 'Administrativo',
    operativo: 'Operativo',
    otro: 'Otro',
};

const { filtros, aplicar, limpiar } = useFiltros(index.url(), {
    empresa_id: props.filtros.empresa_id ?? '',
    sucursal_id: props.filtros.sucursal_id ?? '',
    departamento_id: props.filtros.departamento_id ?? '',
    tipo_puesto: props.filtros.tipo_puesto ?? '',
});
const filtroSheetAbierto = ref(false);
const filtrosActivos = computed(
    () => Object.values(filtros).filter(Boolean).length,
);

// Un solo árbol real (o varios, si hay puestos sin conexión entre sí) — no
// se agrupa por tipo_puesto: un puesto comercial puede colgar de un puesto
// administrativo (p. ej. "Director comercial" reporta a "Dirección
// General"), y partir el árbol por tipo cortaría esa rama de su raíz real.
// El tipo se muestra como badge en cada tarjeta, no como criterio de
// agrupación (ver OrganigramaTarjeta.vue).
const raices = computed(() =>
    props.puestos.filter(
        (p) =>
            !p.puesto_superior_id ||
            !props.puestos.some((otro) => otro.id === p.puesto_superior_id),
    ),
);

function obtenerHijos(id: number): PuestoJerarquiaItem[] {
    return props.puestos.filter((p) => p.puesto_superior_id === id);
}

// Búsqueda de puesto o persona: resalta las tarjetas que coinciden y
// atenúa el resto, sin recargar ni podar el árbol (ver lib/organigrama.ts).
const busqueda = ref('');
provide(CLAVE_BUSQUEDA_ORGANIGRAMA, busqueda);

const coincidencias = computed(() =>
    busqueda.value.trim()
        ? props.puestos.filter((p) => coincideBusqueda(p, busqueda.value))
              .length
        : null,
);

const {
    mostrarExito,
    mostrarError,
    confirmarDesvinculacion,
    confirmarFinCobertura,
} = useAlertas();

// --- Coberturas temporales (alguien cubre un puesto de otra sucursal o
// región sin dejar el suyo) ---
const dialogoCoberturaAbierto = ref(false);
const nodoACubrir = ref<NodoOrganigramaPersona | null>(null);
const formCobertura = useForm({
    colaborador_id: '',
    puesto_id: 0,
    sucursal_id: null as number | null,
    region_id: null as number | null,
    motivo: 'baja',
    nota: '',
});

function abrirCobertura(nodo: NodoOrganigramaPersona): void {
    nodoACubrir.value = nodo;
    formCobertura.reset();
    formCobertura.clearErrors();
    formCobertura.puesto_id = nodo.puesto.id;
    formCobertura.sucursal_id = nodo.sucursal?.id ?? null;
    formCobertura.region_id = nodo.sucursal ? null : (nodo.region?.id ?? null);
    dialogoCoberturaAbierto.value = true;
}

function guardarCobertura(): void {
    formCobertura
        .transform((datos) => ({
            ...datos,
            colaborador_id: Number(datos.colaborador_id) || null,
        }))
        .post(storeCobertura.url(), {
            preserveScroll: true,
            onSuccess: () => {
                dialogoCoberturaAbierto.value = false;
            },
        });
}

async function terminarCobertura(nodo: NodoOrganigramaPersona): Promise<void> {
    if (!nodo.cobertura || !nodo.persona) {
        return;
    }

    const lugar = nodo.sucursal?.nombre ?? nodo.region?.nombre ?? '';
    const confirmado = await confirmarFinCobertura(
        nodo.persona.nombre,
        `${nodo.puesto.nombre}${lugar ? ` · ${lugar}` : ''}`,
    );

    if (confirmado) {
        router.post(
            finalizarCobertura.url(nodo.cobertura.id),
            {},
            { preserveScroll: true },
        );
    }
}

provide(CLAVE_ACCIONES_ORGANIGRAMA, {
    puedeEditar: props.coberturas.puedeEditar,
    asignarCobertura: abrirCobertura,
    terminarCobertura: (nodo) => void terminarCobertura(nodo),
});

const puestoSeleccionado = ref<PuestoJerarquiaItem | null>(null);
const panelAbierto = ref(false);
const dialogoAbierto = ref(false);
const dialogoSubordinadoAbierto = ref(false);
const puestoParaSubordinado = ref<PuestoJerarquiaItem | null>(null);
const tabActiva = ref('detalle');

function abrirAgregarSubordinado(puesto: PuestoJerarquiaItem) {
    puestoParaSubordinado.value = puesto;
    dialogoSubordinadoAbierto.value = true;
}

async function quitarRelacion(puesto: PuestoJerarquiaItem) {
    const confirmado = await confirmarDesvinculacion(`«${puesto.nombre}»`);

    if (!confirmado) {
        return;
    }

    router.put(
        actualizar.url(puesto.id),
        { puesto_superior_id: null },
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Se quitó la relación jerárquica.'),
            onError: () => mostrarError('No se pudo quitar la relación.'),
        },
    );
}

const historial = ref<PuestoHistorialResponse | null>(null);
const historialCargando = ref(false);

function seleccionar(puesto: PuestoJerarquiaItem) {
    puestoSeleccionado.value = puesto;
    panelAbierto.value = true;
    tabActiva.value = 'detalle';
    historial.value = null;
}

function abrirEdicion(puesto?: PuestoJerarquiaItem) {
    if (puesto) {
        puestoSeleccionado.value = puesto;
    }

    dialogoAbierto.value = true;
}

async function cargarHistorial() {
    if (!puestoSeleccionado.value || historial.value) {
        return;
    }

    historialCargando.value = true;

    try {
        historial.value = await getJson<PuestoHistorialResponse>(
            historialUrl.url(puestoSeleccionado.value.id),
        );
    } finally {
        historialCargando.value = false;
    }
}

watch(tabActiva, (valor) => {
    if (valor === 'historial') {
        cargarHistorial();
    }
});

watch(
    () => props.puestos,
    (lista) => {
        if (!puestoSeleccionado.value) {
            return;
        }

        puestoSeleccionado.value =
            lista.find((p) => p.id === puestoSeleccionado.value?.id) ?? null;
    },
);
</script>

<template>
    <Head title="Organigrama" />

    <div class="flex flex-col gap-3 p-3 sm:p-4">
        <!-- Una sola barra: vista, búsqueda, filtros (en panel lateral) y
             Matriz comercial — el espacio vertical se deja al árbol. -->
        <div
            data-tour="organigrama-filtros"
            class="flex flex-wrap items-center gap-2"
        >
            <div
                class="inline-flex items-center gap-0.5 rounded-xl bg-muted p-1"
                role="tablist"
                aria-label="Vista del organigrama"
            >
                <button
                    v-for="opcion in VISTAS"
                    :key="opcion.valor"
                    type="button"
                    role="tab"
                    :aria-selected="vista === opcion.valor"
                    class="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-sm font-medium transition-all"
                    :class="
                        vista === opcion.valor
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="vista = opcion.valor"
                >
                    <component :is="opcion.icono" class="size-4" />
                    <span class="hidden sm:inline">{{ opcion.etiqueta }}</span>
                </button>
            </div>

            <div
                data-tour="organigrama-buscar"
                class="relative w-full min-w-48 flex-1 sm:w-auto sm:max-w-72"
            >
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="busqueda"
                    type="search"
                    placeholder="Buscar puesto o persona…"
                    class="h-10 w-full rounded-xl border border-input bg-card pr-3 pl-9 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
            </div>
            <span
                v-if="coincidencias !== null"
                class="rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary"
            >
                {{ coincidencias }}
                {{ coincidencias === 1 ? 'coincidencia' : 'coincidencias' }}
            </span>

            <CrudFilterSheet
                titulo="Filtros del organigrama"
                descripcion="Empresa, sucursal, departamento y tipo de puesto."
                :contador-activos="filtrosActivos"
                :open="filtroSheetAbierto"
                @update:open="(v) => (filtroSheetAbierto = v)"
                @aplicar="aplicar"
                @limpiar="limpiar"
            >
                <div class="grid gap-2">
                    <Label>Empresa</Label>
                    <Select
                        :model-value="filtros.empresa_id"
                        @update:model-value="
                            (v) => (filtros.empresa_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todas las empresas"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.empresas"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label>Sucursal</Label>
                    <Select
                        :model-value="filtros.sucursal_id"
                        @update:model-value="
                            (v) => (filtros.sucursal_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todas las sucursales"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.sucursales"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label>Departamento</Label>
                    <Select
                        :model-value="filtros.departamento_id"
                        @update:model-value="
                            (v) => (filtros.departamento_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos los departamentos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.departamentos"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label>Tipo de puesto</Label>
                    <Select
                        :model-value="filtros.tipo_puesto"
                        @update:model-value="
                            (v) => (filtros.tipo_puesto = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos los tipos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(etiqueta, valor) in TIPO_ETIQUETA"
                                :key="valor"
                                :value="valor"
                                >{{ etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </CrudFilterSheet>

            <Button
                v-if="filtrosActivos > 0"
                variant="ghost"
                size="sm"
                @click="limpiar"
            >
                Limpiar
            </Button>

            <Link
                data-tour="encabezado"
                :href="indexMatrizComercial()"
                class="ml-auto inline-flex h-10 items-center gap-1.5 rounded-xl border border-border/60 bg-card px-3 text-sm font-medium shadow-sm transition-colors hover:border-primary/40 hover:bg-accent"
            >
                <MapPin class="size-4" />
                <span class="hidden sm:inline">Matriz comercial</span>
            </Link>
        </div>

        <CrudEmptyState
            v-if="!puestos.length"
            :icono="GitBranch"
            titulo="Todavía no hay puestos configurados"
            descripcion="Crea puestos desde Administración › Puestos y vuelve aquí para armar el organigrama."
        />

        <template v-else>
            <!-- Por personas: una tarjeta por colaborador, rama por sucursal. -->
            <div v-if="vista === 'personas'" data-tour="organigrama-arbol">
                <OrganigramaPersonasArbol
                    v-if="personas.length"
                    :nodos="personas"
                />
                <CrudEmptyState
                    v-else
                    :icono="Users"
                    titulo="No hay colaboradores activos con estos filtros"
                    descripcion="Ajusta los filtros o revisa que los colaboradores tengan puesto asignado."
                />
            </div>

            <template v-else>
                <!-- Escritorio/tablet: árbol visual con conectores y zoom. -->
                <div data-tour="organigrama-arbol" class="hidden md:block">
                    <OrganigramaArbol
                        :raices="raices"
                        :obtener-hijos="obtenerHijos"
                        @seleccionar="seleccionar"
                        @editar="abrirEdicion"
                        @agregar-subordinado="abrirAgregarSubordinado"
                        @quitar-relacion="quitarRelacion"
                    />
                </div>

                <!-- Móvil: lista jerárquica expandible (el árbol completo no cabe). -->
                <div
                    data-tour="organigrama-arbol"
                    class="flex flex-col gap-3 md:hidden"
                >
                    <OrganigramaAccordion
                        v-for="raiz in raices"
                        :key="raiz.id"
                        :puesto="raiz"
                        :hijos="obtenerHijos(raiz.id)"
                        :obtener-hijos="obtenerHijos"
                        @seleccionar="seleccionar"
                        @editar="abrirEdicion"
                        @agregar-subordinado="abrirAgregarSubordinado"
                        @quitar-relacion="quitarRelacion"
                    />
                </div>
            </template>
        </template>
    </div>

    <!-- Cobertura temporal: alguien de otra sucursal/región cubre este
         puesto sin dejar el suyo. -->
    <Dialog v-model:open="dialogoCoberturaAbierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Asignar quién cubre</DialogTitle>
                <DialogDescription v-if="nodoACubrir">
                    <strong>{{ nodoACubrir.puesto.nombre }}</strong>
                    <template v-if="nodoACubrir.sucursal">
                        en {{ nodoACubrir.sucursal.nombre }}</template
                    ><template v-else-if="nodoACubrir.region">
                        de {{ nodoACubrir.region.nombre }}</template
                    >. La persona conserva su propio puesto y aparecerá en ambos
                    lugares del organigrama.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label>¿Quién lo cubre?</Label>
                    <Combobox
                        v-model="formCobertura.colaborador_id"
                        :items="coberturas.colaboradores"
                        placeholder="Buscar colaborador…"
                        empty-text="Sin colaboradores con ese nombre."
                    />
                    <p
                        v-if="formCobertura.errors.colaborador_id"
                        class="text-sm text-destructive"
                    >
                        {{ formCobertura.errors.colaborador_id }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>Motivo</Label>
                    <Select v-model="formCobertura.motivo">
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="motivo in coberturas.motivos"
                                :key="motivo.value"
                                :value="motivo.value"
                                >{{ motivo.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Nota (opcional)</Label>
                    <Textarea
                        v-model="formCobertura.nota"
                        rows="2"
                        placeholder="Ej. mientras se contrata y capacita al nuevo gerente"
                    />
                </div>
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="formCobertura.processing"
                    @click="dialogoCoberturaAbierto = false"
                >
                    Cancelar
                </Button>
                <Button
                    :disabled="
                        formCobertura.processing ||
                        !formCobertura.colaborador_id
                    "
                    @click="guardarCobertura"
                >
                    <Spinner v-if="formCobertura.processing" />
                    Asignar cobertura
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Sheet v-model:open="panelAbierto">
        <SheetContent
            v-if="puestoSeleccionado"
            class="w-full overflow-y-auto sm:max-w-lg"
        >
            <SheetHeader>
                <SheetTitle>{{ puestoSeleccionado.nombre }}</SheetTitle>
            </SheetHeader>

            <div class="flex flex-col gap-4 px-4 pb-4">
                <Button class="w-fit" size="sm" @click="abrirEdicion()">
                    <Pencil class="size-4" />
                    Editar jerarquía
                </Button>

                <Tabs v-model="tabActiva">
                    <TabsList class="w-full">
                        <TabsTrigger value="detalle" class="flex-1"
                            >Detalle</TabsTrigger
                        >
                        <TabsTrigger value="vacantes" class="flex-1"
                            >Vacantes</TabsTrigger
                        >
                        <TabsTrigger value="historial" class="flex-1"
                            >Historial</TabsTrigger
                        >
                    </TabsList>

                    <TabsContent
                        value="detalle"
                        class="flex flex-col gap-4 pt-4"
                    >
                        <div
                            v-if="puestoSeleccionado.ocupantes?.length"
                            class="flex flex-col gap-1 rounded-2xl border border-border/60 bg-muted/30 p-2"
                        >
                            <p
                                class="px-1 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Personas en este puesto ({{
                                    puestoSeleccionado.colaboradores_count
                                }})
                            </p>
                            <OrganigramaPersona
                                v-for="persona in puestoSeleccionado.ocupantes"
                                :key="persona.id"
                                :persona="persona"
                            />
                        </div>

                        <p class="text-sm text-muted-foreground">
                            {{
                                puestoSeleccionado.descripcion ??
                                'Sin descripción registrada.'
                            }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Badge
                                v-if="puestoSeleccionado.tipo_puesto"
                                variant="outline"
                            >
                                {{
                                    TIPO_ETIQUETA[
                                        puestoSeleccionado.tipo_puesto
                                    ]
                                }}
                            </Badge>
                            <Badge
                                v-if="puestoSeleccionado.nivel_jerarquico"
                                variant="outline"
                            >
                                Nivel {{ puestoSeleccionado.nivel_jerarquico }}
                            </Badge>
                            <Badge
                                v-if="puestoSeleccionado.requiere_ruta"
                                variant="outline"
                            >
                                Requiere ruta
                            </Badge>
                            <Badge
                                v-if="!puestoSeleccionado.activo"
                                variant="outline"
                            >
                                Inactivo
                            </Badge>
                        </div>

                        <dl class="grid grid-cols-1 gap-3 text-sm">
                            <div>
                                <dt class="text-muted-foreground">
                                    Puesto superior
                                </dt>
                                <dd>
                                    {{
                                        puestoSeleccionado.puesto_superior
                                            ?.nombre ?? '—'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Ruta de crecimiento
                                </dt>
                                <dd>
                                    {{
                                        puestoSeleccionado.puesto_crecimiento
                                            ?.nombre ?? '—'
                                    }}
                                </dd>
                            </div>
                            <div v-if="puestoSeleccionado.esquema_comisiones">
                                <dt class="text-muted-foreground">
                                    Esquema de comisiones
                                </dt>
                                <dd>
                                    {{ puestoSeleccionado.esquema_comisiones }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Respaldos</dt>
                                <dd v-if="puestoSeleccionado.respaldos.length">
                                    {{
                                        puestoSeleccionado.respaldos
                                            .map((r) => r.nombre)
                                            .join(', ')
                                    }}
                                </dd>
                                <dd v-else>—</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Puestos que puede cubrir
                                </dt>
                                <dd
                                    v-if="
                                        puestoSeleccionado
                                            .puestos_que_puede_cubrir.length
                                    "
                                >
                                    {{
                                        puestoSeleccionado.puestos_que_puede_cubrir
                                            .map((r) => r.nombre)
                                            .join(', ')
                                    }}
                                </dd>
                                <dd v-else>—</dd>
                            </div>
                            <div v-if="puestoSeleccionado.responsabilidades">
                                <dt class="text-muted-foreground">
                                    Responsabilidades
                                </dt>
                                <dd class="whitespace-pre-line">
                                    {{ puestoSeleccionado.responsabilidades }}
                                </dd>
                            </div>
                            <div v-if="puestoSeleccionado.requisitos">
                                <dt class="text-muted-foreground">
                                    Requisitos
                                </dt>
                                <dd class="whitespace-pre-line">
                                    {{ puestoSeleccionado.requisitos }}
                                </dd>
                            </div>
                        </dl>

                        <div class="grid grid-cols-2 gap-2">
                            <div
                                class="rounded-xl border border-border/60 bg-card p-3 text-sm"
                            >
                                <span
                                    class="flex items-center gap-1.5 text-lg font-semibold"
                                >
                                    <Users class="size-4" />
                                    {{ puestoSeleccionado.colaboradores_count }}
                                </span>
                                <span class="text-xs text-muted-foreground"
                                    >Colaboradores activos</span
                                >
                            </div>
                            <div
                                class="rounded-xl border border-border/60 bg-card p-3 text-sm"
                            >
                                <span class="block text-lg font-semibold">{{
                                    puestoSeleccionado.candidatos_count
                                }}</span>
                                <span class="text-xs text-muted-foreground"
                                    >Candidatos</span
                                >
                            </div>
                        </div>

                        <div
                            v-if="puestoSeleccionado.candidatos.length"
                            class="flex flex-col gap-1.5"
                        >
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Candidatos relacionados
                            </p>
                            <Link
                                v-for="candidato in puestoSeleccionado.candidatos"
                                :key="candidato.id"
                                :href="
                                    indexCandidatos.url({
                                        query: { busqueda: candidato.nombre },
                                    })
                                "
                                class="rounded-lg border border-border/60 px-3 py-2 text-sm transition-colors hover:border-primary/40"
                            >
                                {{ candidato.nombre }} {{ candidato.apellidos }}
                                <span class="text-xs text-muted-foreground"
                                    >· {{ candidato.estado }}</span
                                >
                            </Link>
                        </div>
                    </TabsContent>

                    <TabsContent
                        value="vacantes"
                        class="flex flex-col gap-4 pt-4"
                    >
                        <div class="grid grid-cols-1">
                            <Link
                                :href="
                                    indexVacantes.url({
                                        query: {
                                            puesto_id: puestoSeleccionado.id,
                                        },
                                    })
                                "
                                class="rounded-xl border border-border/60 bg-card p-3 text-sm transition-all duration-200 hover:border-primary/40 hover:shadow-md"
                            >
                                <span class="block text-lg font-semibold">{{
                                    puestoSeleccionado.vacantes_abiertas_count
                                }}</span>
                                <span class="text-xs text-muted-foreground"
                                    >Vacantes abiertas — ver cobertura de
                                    plantilla</span
                                >
                            </Link>
                        </div>
                    </TabsContent>

                    <TabsContent
                        value="historial"
                        class="flex flex-col gap-4 pt-4"
                    >
                        <div
                            v-if="historialCargando"
                            class="flex flex-col gap-2"
                        >
                            <Skeleton class="h-16 w-full rounded-xl" />
                            <Skeleton class="h-16 w-full rounded-xl" />
                            <Skeleton class="h-16 w-full rounded-xl" />
                        </div>

                        <template v-else-if="historial">
                            <div
                                v-if="
                                    !historial.movimientos.length &&
                                    !historial.cambiosJerarquia.length &&
                                    !historial.vacantes.length
                                "
                                class="rounded-2xl border border-dashed border-border/60 p-6 text-center text-xs text-muted-foreground"
                            >
                                <History class="mx-auto mb-2 size-5" />
                                Sin historial registrado para este puesto
                                todavía.
                            </div>

                            <div
                                v-if="historial.movimientos.length"
                                class="flex flex-col gap-2"
                            >
                                <p
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    Movimientos laborales
                                </p>
                                <div
                                    v-for="movimiento in historial.movimientos"
                                    :key="`mov-${movimiento.id}`"
                                    class="rounded-xl border border-border/60 bg-card p-3 text-sm"
                                >
                                    <p>{{ movimiento.descripcion }}</p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ movimiento.fecha_movimiento }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="historial.vacantes.length"
                                class="flex flex-col gap-2"
                            >
                                <p
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    Vacantes generadas
                                </p>
                                <div
                                    v-for="vacante in historial.vacantes"
                                    :key="`vac-${vacante.id}`"
                                    class="rounded-xl border border-border/60 bg-card p-3 text-sm"
                                >
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <Badge variant="outline">{{
                                            vacante.motivo
                                        }}</Badge>
                                        <Badge variant="secondary">{{
                                            vacante.estado
                                        }}</Badge>
                                    </div>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ vacante.fecha_apertura }} ·
                                        {{
                                            vacante.sucursal?.nombre ??
                                            'Sin sucursal'
                                        }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="historial.cambiosJerarquia.length"
                                class="flex flex-col gap-2"
                            >
                                <p
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    Cambios de jerarquía
                                </p>
                                <div
                                    v-for="cambio in historial.cambiosJerarquia"
                                    :key="`cambio-${cambio.id}`"
                                    class="rounded-xl border border-border/60 bg-card p-3 text-sm"
                                >
                                    <p>{{ cambio.descripcion }}</p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ cambio.fecha }}
                                    </p>
                                </div>
                            </div>
                        </template>
                    </TabsContent>
                </Tabs>
            </div>
        </SheetContent>
    </Sheet>

    <JerarquiaPuestoDialog
        v-if="dialogoAbierto && puestoSeleccionado"
        v-model:open="dialogoAbierto"
        :puesto="puestoSeleccionado"
        :todos-los-puestos="puestos"
        :key="puestoSeleccionado.id"
    />

    <AgregarSubordinadoDialog
        v-if="dialogoSubordinadoAbierto && puestoParaSubordinado"
        v-model:open="dialogoSubordinadoAbierto"
        :puesto="puestoParaSubordinado"
        :todos-los-puestos="puestos"
        :key="`sub-${puestoParaSubordinado.id}`"
    />
</template>
