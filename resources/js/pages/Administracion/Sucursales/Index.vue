<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Building,
    Building2,
    CheckCircle2,
    MapPin,
    MoreVertical,
    Phone,
    Plus,
    User,
    Users,
    XCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SucursalFormDialog from '@/components/Administracion/SucursalFormDialog.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { usePaginacion } from '@/composables/usePaginacion';
import { dashboard } from '@/routes';
import { destroy, index, show } from '@/routes/administracion/sucursales';
import type {
    EstadisticasActivoInactivo,
    OpcionSimple,
    RespuestaPaginada,
    SucursalItem,
} from '@/types';

const props = defineProps<{
    sucursales: RespuestaPaginada<SucursalItem>;
    filtros: { busqueda?: string; empresa_id?: string };
    /** Prop opcional: solo llega al abrir el diálogo (recarga parcial). */
    responsablesDisponibles?: {
        id: number;
        name: string;
        apellidos: string | null;
    }[];
    empresasDisponibles: OpcionSimple[];
    estadisticas: EstadisticasActivoInactivo;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Sucursales', href: index.url() },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        empresa_id: props.filtros.empresa_id ?? '',
    },
);
const { irA } = usePaginacion();
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const filtrosActivos = computed(() => (filtros.empresa_id ? 1 : 0));

const dialogAbierto = ref(false);

// Con más de una empresa en el catálogo, agrupar por empresa en vez de
// mostrarla como dato repetido en cada tarjeta ahorra espacio y es más
// legible; con una sola empresa (o ya filtrado a una) no aporta nada.
const agruparPorEmpresa = computed(
    () => props.empresasDisponibles.length > 1 && !filtros.empresa_id,
);

type GrupoSucursales = { empresa: string; sucursales: SucursalItem[] };

const gruposSucursales = computed<GrupoSucursales[]>(() => {
    if (!agruparPorEmpresa.value) {
        return [{ empresa: '', sucursales: props.sucursales.data }];
    }

    const grupos: GrupoSucursales[] = [];
    const indice = new Map<string, GrupoSucursales>();

    for (const sucursal of props.sucursales.data) {
        const clave = sucursal.empresa?.nombre ?? 'Sin empresa';
        let grupo = indice.get(clave);

        if (!grupo) {
            grupo = { empresa: clave, sucursales: [] };
            indice.set(clave, grupo);
            grupos.push(grupo);
        }

        grupo.sucursales.push(sucursal);
    }

    return grupos;
});

function abrirSucursal(sucursal: SucursalItem) {
    router.visit(show.url(sucursal.id));
}

// El catálogo de responsables (todas las cuentas) no viaja con el listado:
// se pide solo al abrir el diálogo (SucursalController::index, Inertia::optional).
watch(dialogAbierto, (abierto) => {
    if (abierto && props.responsablesDisponibles === undefined) {
        router.reload({ only: ['responsablesDisponibles'] });
    }
});
const sucursalSeleccionada = ref<SucursalItem | null>(null);

function abrirCrear() {
    sucursalSeleccionada.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(sucursal: SucursalItem) {
    sucursalSeleccionada.value = sucursal;
    dialogAbierto.value = true;
}

async function eliminar(sucursal: SucursalItem) {
    const confirmado = await confirmarEliminacion(
        `la sucursal «${sucursal.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(sucursal.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('La sucursal se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar la sucursal.'),
    });
}
</script>

<template>
    <Head title="Sucursales" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Sucursales"
            descripcion="Organiza la capacitación por ubicación y revisa el alcance de cada responsable."
            :icono="Building"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva sucursal
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                {
                    etiqueta: 'Sucursales',
                    valor: estadisticas.total,
                    icono: Building,
                },
                {
                    etiqueta: 'Activas',
                    valor: estadisticas.activos,
                    icono: CheckCircle2,
                    tono: 'success',
                },
                {
                    etiqueta: 'Inactivas',
                    valor: estadisticas.inactivos,
                    icono: XCircle,
                    tono: 'danger',
                },
            ]"
        />

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre o clave..."
            @update:model-value="
                (valor) => {
                    filtros.busqueda = valor;
                    aplicarConDebounce();
                }
            "
            :contador-filtros-activos="filtrosActivos"
            titulo-filtros="Filtros"
            @limpiar="limpiar"
        >
            <template v-if="empresasDisponibles.length > 1" #filtros>
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Empresa</label>
                    <Select
                        :model-value="filtros.empresa_id"
                        @update:model-value="
                            (v) => {
                                filtros.empresa_id = String(v ?? '');
                                aplicar();
                            }
                        "
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Todas las empresas" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in empresasDisponibles"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </template>
        </CrudToolbar>

        <CrudEmptyState
            v-if="sucursales.data.length === 0"
            :icono="Building"
            titulo="Todavía no hay sucursales"
            descripcion="Crea la primera sucursal para empezar a organizar la capacitación por ubicación."
        >
            <Button size="sm" @click="abrirCrear">
                <Plus class="size-4" />
                Crear sucursal
            </Button>
        </CrudEmptyState>

        <template v-else>
            <div
                v-for="grupo in gruposSucursales"
                :key="grupo.empresa || 'todas'"
                class="flex flex-col gap-3"
            >
                <div
                    v-if="agruparPorEmpresa"
                    class="flex items-center gap-2 text-sm font-medium text-muted-foreground"
                >
                    <Building2 class="size-4" />
                    {{ grupo.empresa }}
                    <span
                        class="rounded-full bg-muted px-2 py-0.5 text-xs font-normal"
                        >{{ grupo.sucursales.length }}</span
                    >
                </div>

                <div
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <div
                        v-for="sucursal in grupo.sucursales"
                        :key="sucursal.id"
                        role="link"
                        tabindex="0"
                        class="group relative flex cursor-pointer flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                        @click="abrirSucursal(sucursal)"
                        @keydown.enter="abrirSucursal(sucursal)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                            >
                                <Building class="size-5" />
                            </div>
                            <div class="flex items-center gap-1">
                                <EstadoBadge
                                    :estado="
                                        sucursal.activo ? 'activo' : 'inactivo'
                                    "
                                />
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="size-7 opacity-60 hover:opacity-100"
                                            title="Más acciones"
                                            @click.stop
                                        >
                                            <MoreVertical class="size-4" />
                                            <span class="sr-only"
                                                >Más acciones</span
                                            >
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            @select="abrirEditar(sucursal)"
                                            >Editar</DropdownMenuItem
                                        >
                                        <DropdownMenuItem
                                            variant="destructive"
                                            @select="eliminar(sucursal)"
                                            >Eliminar</DropdownMenuItem
                                        >
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>

                        <div class="min-w-0">
                            <p
                                class="truncate font-semibold transition-colors group-hover:text-primary"
                            >
                                {{ sucursal.nombre }}
                            </p>
                            <p
                                class="font-mono text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                {{ sucursal.clave }}
                            </p>
                        </div>

                        <div
                            class="flex flex-col gap-1.5 text-sm text-muted-foreground"
                        >
                            <span
                                v-if="sucursal.ciudad"
                                class="inline-flex items-center gap-1.5"
                            >
                                <MapPin class="size-3.5 shrink-0" />
                                <span class="truncate">{{
                                    sucursal.ciudad
                                }}</span>
                            </span>
                            <span
                                v-if="sucursal.telefono"
                                class="inline-flex items-center gap-1.5"
                            >
                                <Phone class="size-3.5 shrink-0" />
                                <span class="truncate">{{
                                    sucursal.telefono
                                }}</span>
                            </span>
                            <span
                                v-if="sucursal.responsable"
                                class="inline-flex items-center gap-1.5"
                            >
                                <User class="size-3.5 shrink-0" />
                                <span class="truncate">{{
                                    sucursal.responsable.name
                                }}</span>
                            </span>
                        </div>

                        <div
                            class="mt-auto flex items-center gap-1.5 border-t border-border/60 pt-2.5 text-sm"
                        >
                            <Users class="size-3.5 text-muted-foreground" />
                            <span class="font-medium">{{
                                sucursal.colaboradores_count
                            }}</span>
                            <span class="text-muted-foreground"
                                >colaborador(es)</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="sucursales.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground"
            >
                <span
                    >Mostrando {{ sucursales.from ?? 0 }}–{{
                        sucursales.to ?? 0
                    }}
                    de {{ sucursales.total }}</span
                >
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="(enlace, indice) in sucursales.links"
                        :key="indice"
                        type="button"
                        :disabled="!enlace.url"
                        @click="irA(enlace.url)"
                        :class="[
                            'min-w-9 rounded-md border px-3 py-1.5 text-sm transition-colors',
                            enlace.active
                                ? 'border-transparent bg-primary text-primary-foreground'
                                : 'border-border hover:bg-accent',
                            !enlace.url
                                ? 'cursor-not-allowed opacity-50'
                                : 'cursor-pointer',
                        ]"
                        v-html="enlace.label"
                    />
                </div>
            </div>
        </template>
    </div>

    <SucursalFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :sucursal="sucursalSeleccionada"
        :responsables-disponibles="responsablesDisponibles ?? []"
        :empresas-disponibles="empresasDisponibles"
        :key="sucursalSeleccionada?.id ?? 'nueva'"
    />
</template>
