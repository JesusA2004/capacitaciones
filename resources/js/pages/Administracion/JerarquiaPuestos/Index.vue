<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Briefcase, GitBranch, History, Pencil, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import JerarquiaPuestoDialog from '@/components/Administracion/JerarquiaPuestoDialog.vue';
import OrganigramaAccordion from '@/components/Administracion/OrganigramaAccordion.vue';
import OrganigramaArbol from '@/components/Administracion/OrganigramaArbol.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useFiltros } from '@/composables/useFiltros';
import { getJson } from '@/lib/http';
import { dashboard } from '@/routes';
import {
    historial as historialUrl,
    index,
} from '@/routes/administracion/jerarquia-puestos';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import type {
    PuestoHistorialResponse,
    PuestoJerarquiaFiltros,
    PuestoJerarquiaItem,
    PuestoJerarquiaOpciones,
} from '@/types';

const props = defineProps<{
    puestos: PuestoJerarquiaItem[];
    filtros: PuestoJerarquiaFiltros;
    opciones: PuestoJerarquiaOpciones;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Jerarquía de puestos', href: index.url() },
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

const grupos = computed(() => {
    const tipos = new Map<string, PuestoJerarquiaItem[]>();

    for (const puesto of props.puestos) {
        const clave = puesto.tipo_puesto ?? 'sin_clasificar';

        if (!tipos.has(clave)) {
            tipos.set(clave, []);
        }

        tipos.get(clave)!.push(puesto);
    }

    return Array.from(tipos.entries()).map(([tipo, lista]) => ({
        tipo,
        etiqueta: TIPO_ETIQUETA[tipo] ?? 'Sin clasificar',
        raices: lista.filter(
            (p) =>
                !p.puesto_superior_id ||
                !lista.some((otro) => otro.id === p.puesto_superior_id),
        ),
        lista,
    }));
});

function obtenerHijos(lista: PuestoJerarquiaItem[]) {
    return (id: number) => lista.filter((p) => p.puesto_superior_id === id);
}

const puestoSeleccionado = ref<PuestoJerarquiaItem | null>(null);
const panelAbierto = ref(false);
const dialogoAbierto = ref(false);
const tabActiva = ref('detalle');

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
    <Head title="Jerarquía de puestos" />

    <div class="flex flex-col gap-6 p-4 lg:p-6">
        <CrudPageHeader
            titulo="Jerarquía de puestos"
            descripcion="Organigrama, rutas de crecimiento y respaldos de cada puesto."
            :icono="GitBranch"
        />

        <div class="flex flex-wrap items-center gap-2">
            <Select
                :model-value="filtros.empresa_id"
                @update:model-value="
                    (v) => {
                        filtros.empresa_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-full sm:w-48"
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

            <Select
                :model-value="filtros.sucursal_id"
                @update:model-value="
                    (v) => {
                        filtros.sucursal_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-full sm:w-48"
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

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Departamento y tipo de puesto."
                :contador-activos="filtrosActivos"
                :open="filtroSheetAbierto"
                @update:open="(v) => (filtroSheetAbierto = v)"
                @aplicar="aplicar"
                @limpiar="limpiar"
            >
                <div class="grid gap-2">
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
                Limpiar filtros
            </Button>
        </div>

        <CrudEmptyState
            v-if="!puestos.length"
            :icono="GitBranch"
            titulo="Todavía no hay puestos configurados"
            descripcion="Crea puestos desde Administración › Puestos y vuelve aquí para armar el organigrama."
        />

        <div
            v-for="grupo in grupos"
            v-else
            :key="grupo.tipo"
            class="flex flex-col gap-4"
        >
            <h2 class="flex items-center gap-2 text-lg font-semibold">
                <Briefcase class="size-4 text-muted-foreground" />
                {{ grupo.etiqueta }}
            </h2>

            <!-- Escritorio/tablet: árbol visual con conectores y zoom. -->
            <div class="hidden md:block">
                <OrganigramaArbol
                    :raices="grupo.raices"
                    :obtener-hijos="obtenerHijos(grupo.lista)"
                    @seleccionar="seleccionar"
                    @editar="abrirEdicion"
                />
            </div>

            <!-- Móvil: lista jerárquica expandible (el árbol completo no cabe). -->
            <div class="flex flex-col gap-3 md:hidden">
                <OrganigramaAccordion
                    v-for="raiz in grupo.raices"
                    :key="raiz.id"
                    :puesto="raiz"
                    :hijos="obtenerHijos(grupo.lista)(raiz.id)"
                    :obtener-hijos="obtenerHijos(grupo.lista)"
                    @seleccionar="seleccionar"
                    @editar="abrirEdicion"
                />
            </div>
        </div>
    </div>

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
                                    {{ puestoSeleccionado.usuarios_count }}
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
                        <div class="grid grid-cols-2 gap-2">
                            <Link
                                :href="
                                    indexVacantes.url({
                                        query: {
                                            puesto_id: puestoSeleccionado.id,
                                        },
                                    })
                                "
                                class="rounded-xl border border-border/60 bg-card p-3 text-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                            >
                                <span class="block text-lg font-semibold">{{
                                    puestoSeleccionado.vacantes_abiertas_count
                                }}</span>
                                <span class="text-xs text-muted-foreground"
                                    >Vacantes abiertas</span
                                >
                            </Link>
                            <Link
                                :href="
                                    indexVacantes.url({
                                        query: {
                                            puesto_id: puestoSeleccionado.id,
                                            departamento_id:
                                                puestoSeleccionado.departamento
                                                    ?.id,
                                            crear: '1',
                                        },
                                    })
                                "
                                class="flex flex-col items-center justify-center rounded-xl border border-dashed border-primary/40 bg-primary/5 p-3 text-center text-xs font-medium text-primary transition-all duration-200 hover:-translate-y-0.5 hover:bg-primary/10"
                            >
                                Crear vacante para este puesto
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
</template>
