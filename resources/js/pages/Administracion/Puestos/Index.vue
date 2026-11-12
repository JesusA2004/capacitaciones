<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Banknote,
    Briefcase,
    Download,
    Lock,
    Plus,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PuestoFormDialog from '@/components/Administracion/PuestoFormDialog.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudActionMenu from '@/components/DataTable/CrudActionMenu.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudMobileCard from '@/components/DataTable/CrudMobileCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import ImportacionTabuladorPanel from '@/components/puestos/ImportacionTabuladorPanel.vue';
import MatrizMeritoPanel from '@/components/puestos/MatrizMeritoPanel.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import { destroy, index, show } from '@/routes/administracion/puestos';
import { excel, pdf } from '@/routes/salarios/exportar';
import type {
    EstadisticasActivoInactivo,
    OpcionSimple,
    PuestoItem,
    RespuestaPaginada,
    SalariosModulo,
} from '@/types';

/**
 * Organización → «Puestos y salarios»: el catálogo de puestos y su
 * tabulador en un solo lugar. Cada puesto abre su ficha (personas,
 * traslados, tabulador y datos). Los importes, la matriz de mérito y las
 * alertas solo aparecen con salarios.ver (el backend no los manda sin él).
 */
const props = defineProps<{
    puede: {
        crear: boolean;
        editar: boolean;
        eliminar: boolean;
        ver_salarios: boolean;
        editar_salarios: boolean;
        exportar_salarios: boolean;
        importar_salarios: boolean;
    };
    puestos: RespuestaPaginada<PuestoItem>;
    filtros: {
        busqueda?: string;
        departamento?: number | null;
        estado?: string;
    };
    departamentosDisponibles: OpcionSimple[];
    estadisticas: EstadisticasActivoInactivo;
    salarios: SalariosModulo | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Puestos y salarios', href: index.url() },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        departamento: props.filtros.departamento
            ? String(props.filtros.departamento)
            : '',
        estado: props.filtros.estado ?? '',
    },
);
const filtrosActivos = computed(
    () => [filtros.departamento, filtros.estado].filter(Boolean).length,
);
const { confirmarEliminacion, mostrarError } = useAlertas();

type Seccion = 'puestos' | 'alertas' | 'merito' | 'importar';
const seccion = ref<Seccion>(
    props.salarios?.importacion ? 'importar' : 'puestos',
);

const fueraDeRango = computed(
    () =>
        (props.salarios?.resumen.bajo_minimo ?? 0) +
        (props.salarios?.resumen.sobre_maximo ?? 0),
);

const secciones = computed(() => {
    const lista: { valor: Seccion; etiqueta: string; contador?: number }[] = [
        { valor: 'puestos', etiqueta: 'Puestos' },
    ];

    if (props.salarios) {
        lista.push(
            {
                valor: 'alertas',
                etiqueta: 'Alertas salariales',
                contador:
                    fueraDeRango.value + props.salarios.sin_tabulador.length,
            },
            { valor: 'merito', etiqueta: 'Matriz de mérito' },
        );
    }

    if (props.puede.importar_salarios) {
        lista.push({ valor: 'importar', etiqueta: 'Cargar desde Excel' });
    }

    return lista;
});

const columnas = computed<ColumnaDataTable[]>(() => [
    {
        clave: 'nombre',
        etiqueta: 'Puesto',
        clase: 'min-w-48 whitespace-normal',
    },
    { clave: 'colaboradores_count', etiqueta: 'Personas' },
    { clave: 'plazas', etiqueta: 'Plazas autorizadas' },
    ...(props.puede.ver_salarios
        ? [
              {
                  clave: 'tabulador',
                  etiqueta: 'Tabulador (mín · medio · máx)',
                  clase: 'min-w-64',
              },
              { clave: 'compa', etiqueta: 'Compa-ratio' },
          ]
        : []),
    { clave: 'meses_periodo_prueba', etiqueta: 'Periodo de prueba' },
    { clave: 'activo', etiqueta: 'Estado' },
]);

const dialogAbierto = ref(false);

function abrir(puesto: PuestoItem): void {
    router.visit(show.url(puesto.id));
}

async function eliminar(puesto: PuestoItem) {
    const confirmado = await confirmarEliminacion(
        `el puesto «${puesto.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(puesto.id), {
        preserveScroll: true,
        // El aviso de éxito (con las vacantes canceladas) llega del servidor;
        // si el puesto sigue en la estructura, el servidor explica por qué.
        onError: (errores) =>
            mostrarError(
                errores.puesto ?? 'No fue posible eliminar el puesto.',
                'No se eliminó el puesto',
            ),
    });
}

function tonoCompa(compa: number | null): string {
    if (compa === null) {
        return 'text-muted-foreground';
    }

    if (compa < 90) {
        return 'bg-danger-soft text-destructive';
    }

    return compa > 110
        ? 'bg-warning-soft text-warning'
        : 'bg-success-soft text-success';
}

const pct = (valor: number | null): string =>
    valor === null ? '—' : `${Math.round(valor)}%`;
</script>

<template>
    <Head title="Puestos y salarios" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Puestos y salarios"
            descripcion="Cada puesto con su tabulador, quién lo ocupa y sus traslados. Abre un puesto para editarlo."
            :icono="Briefcase"
        >
            <span
                v-if="puede.ver_salarios"
                class="inline-flex items-center gap-1 rounded-full bg-danger-soft px-3 py-1 text-xs font-medium text-destructive"
            >
                <Lock class="size-3" /> Sueldos confidenciales
            </span>
            <template v-if="puede.exportar_salarios">
                <Button variant="outline" size="sm" as-child>
                    <a :href="excel.url()"><Download class="size-4" /> Excel</a>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <a :href="pdf.url()"><Download class="size-4" /> PDF</a>
                </Button>
            </template>
            <Button v-if="puede.crear" @click="dialogAbierto = true">
                <Plus class="size-4" /> Nuevo puesto
            </Button>
        </CrudPageHeader>

        <section
            class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4"
            aria-label="Resumen"
        >
            <TarjetaMetrica
                etiqueta="Puestos activos"
                :valor="estadisticas.activos"
                :icono="Briefcase"
                tono="primario"
                :detalle="`${estadisticas.inactivos} inactivo(s)`"
            />
            <template v-if="salarios">
                <TarjetaMetrica
                    etiqueta="Con tabulador"
                    :valor="`${salarios.resumen.puestos_con_tabulador} de ${salarios.resumen.puestos_total}`"
                    :icono="Banknote"
                    tono="info"
                    :detalle="
                        salarios.resumen.puestos_sin_tabulador
                            ? `${salarios.resumen.puestos_sin_tabulador} por capturar`
                            : 'Todos capturados'
                    "
                />
                <TarjetaMetrica
                    etiqueta="Compa-ratio promedio"
                    :valor="pct(salarios.resumen.compa_ratio_promedio)"
                    :icono="Users"
                    tono="exito"
                    :detalle="`${salarios.resumen.colaboradores} personas con sueldo`"
                />
                <TarjetaMetrica
                    etiqueta="Fuera de rango"
                    :valor="fueraDeRango"
                    :icono="AlertTriangle"
                    :tono="fueraDeRango ? 'peligro' : 'exito'"
                    :detalle="`${salarios.resumen.bajo_minimo} bajo mínimo · ${salarios.resumen.sobre_maximo} sobre máximo`"
                    clicable
                    :activa="seccion === 'alertas'"
                    @click="seccion = 'alertas'"
                />
            </template>
        </section>

        <SelectorVista
            v-if="secciones.length > 1"
            v-model="seccion"
            etiqueta="Sección del módulo"
            :opciones="secciones"
        />

        <!-- Puestos -->
        <div v-show="seccion === 'puestos'" class="flex flex-col gap-4">
            <CrudToolbar
                :model-value="filtros.busqueda"
                placeholder="Buscar puesto…"
                :contador-filtros-activos="filtrosActivos"
                @update:model-value="
                    (valor) => {
                        filtros.busqueda = valor;
                        aplicarConDebounce();
                    }
                "
                @aplicar-filtros="aplicar"
                @limpiar="limpiar"
            >
                <template #filtros>
                    <div class="grid gap-4">
                        <label class="grid gap-1.5 text-sm font-medium">
                            Departamento
                            <SelectSimple
                                :model-value="filtros.departamento"
                                :opciones="
                                    departamentosDisponibles.map((d) => ({
                                        value: String(d.id),
                                        label: d.nombre,
                                    }))
                                "
                                opcion-vacia="Todos"
                                placeholder="Todos"
                                @update:model-value="
                                    (v) =>
                                        (filtros.departamento = String(v ?? ''))
                                "
                            />
                        </label>
                        <label class="grid gap-1.5 text-sm font-medium">
                            Estado
                            <SelectSimple
                                :model-value="filtros.estado"
                                :opciones="[
                                    { value: 'activos', label: 'Solo activos' },
                                    {
                                        value: 'inactivos',
                                        label: 'Solo inactivos',
                                    },
                                ]"
                                opcion-vacia="Activos e inactivos"
                                placeholder="Activos e inactivos"
                                @update:model-value="
                                    (v) => (filtros.estado = String(v ?? ''))
                                "
                            />
                        </label>
                    </div>
                </template>
            </CrudToolbar>

            <DataTable
                :columnas="columnas"
                :datos="puestos"
                mensaje-vacio="No se encontraron puestos."
                filas-clicables
                @click-fila="abrir"
            >
                <template #vacio>
                    <CrudEmptyState
                        :icono="Briefcase"
                        titulo="Todavía no hay puestos"
                        descripcion="Crea el primer puesto para capturar su tabulador y asignarlo a tus colaboradores."
                    >
                        <Button
                            v-if="puede.crear"
                            size="sm"
                            @click="dialogAbierto = true"
                        >
                            <Plus class="size-4" /> Crear puesto
                        </Button>
                    </CrudEmptyState>
                </template>

                <template #celda-nombre="{ fila }">
                    <span class="block font-medium">{{ fila.nombre }}</span>
                    <span class="block text-xs text-muted-foreground">{{
                        fila.departamento?.nombre ?? 'Sin departamento'
                    }}</span>
                </template>
                <template #celda-colaboradores_count="{ fila }">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-salvia px-2.5 py-1 text-xs font-semibold tabular-nums"
                    >
                        <Users class="size-3.5" />
                        {{ fila.colaboradores_count }}
                    </span>
                </template>
                <template #celda-plazas="{ fila }">
                    <span
                        v-if="fila.plazas && fila.plazas.autorizadas > 0"
                        class="inline-flex flex-wrap items-center gap-1.5 text-xs tabular-nums"
                        :title="`${fila.plazas.ocupadas} de ${fila.plazas.autorizadas} plazas ocupadas`"
                    >
                        <span class="font-semibold"
                            >{{ fila.plazas.ocupadas }} /
                            {{ fila.plazas.autorizadas }}</span
                        >
                        <span
                            class="rounded-full px-2 py-0.5 font-medium"
                            :class="
                                fila.plazas.vacantes > 0
                                    ? 'bg-warning-soft text-warning'
                                    : 'bg-success-soft text-success'
                            "
                            >{{
                                fila.plazas.vacantes > 0
                                    ? `${fila.plazas.vacantes} ${fila.plazas.vacantes === 1 ? 'vacante' : 'vacantes'}`
                                    : 'Completa'
                            }}</span
                        >
                    </span>
                    <span v-else class="text-xs text-muted-foreground"
                        >Sin plazas</span
                    >
                </template>
                <template #celda-tabulador="{ fila }">
                    <span
                        v-if="fila.tabulador"
                        class="inline-flex items-baseline gap-1.5 text-xs tabular-nums"
                    >
                        <span class="text-muted-foreground">{{
                            formatoMoneda(fila.tabulador.minimo)
                        }}</span>
                        <span class="text-muted-foreground/60">·</span>
                        <span class="text-sm font-semibold">{{
                            formatoMoneda(fila.tabulador.medio)
                        }}</span>
                        <span class="text-muted-foreground/60">·</span>
                        <span class="text-muted-foreground">{{
                            formatoMoneda(fila.tabulador.maximo)
                        }}</span>
                    </span>
                    <span
                        v-else
                        class="inline-flex rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-warning"
                        >Sin capturar</span
                    >
                </template>
                <template #celda-compa="{ fila }">
                    <span
                        v-if="fila.tabulador?.compa_ratio != null"
                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold tabular-nums"
                        :class="tonoCompa(fila.tabulador.compa_ratio)"
                        >{{ pct(fila.tabulador.compa_ratio) }}</span
                    >
                    <span v-else class="text-xs text-muted-foreground">—</span>
                </template>
                <template #celda-meses_periodo_prueba="{ fila }">
                    <span v-if="fila.meses_periodo_prueba" class="text-sm">
                        {{ fila.meses_periodo_prueba }}
                        {{ fila.meses_periodo_prueba === 1 ? 'mes' : 'meses' }}
                    </span>
                    <span v-else class="text-xs text-warning"
                        >Sin configurar</span
                    >
                </template>
                <template #celda-activo="{ fila }">
                    <EstadoBadge
                        :estado="fila.activo ? 'activo' : 'inactivo'"
                    />
                </template>
                <template #acciones="{ fila }">
                    <CrudActionMenu>
                        <DropdownMenuItem @select="abrir(fila)"
                            >Abrir ficha</DropdownMenuItem
                        >
                        <DropdownMenuItem
                            v-if="puede.eliminar"
                            variant="destructive"
                            @select="eliminar(fila)"
                            >Eliminar</DropdownMenuItem
                        >
                    </CrudActionMenu>
                </template>

                <template #mobile-card="{ fila }">
                    <CrudMobileCard
                        :titulo="fila.nombre"
                        :subtitulo="
                            fila.departamento?.nombre ?? 'Sin departamento'
                        "
                        @click="abrir(fila)"
                    >
                        <template #badge>
                            <EstadoBadge
                                :estado="fila.activo ? 'activo' : 'inactivo'"
                            />
                        </template>
                        <span class="inline-flex items-center gap-1.5">
                            <Users class="size-3.5" />
                            {{ fila.colaboradores_count }}
                            {{
                                fila.colaboradores_count === 1
                                    ? 'persona'
                                    : 'personas'
                            }}
                        </span>
                        <span
                            v-if="fila.plazas && fila.plazas.autorizadas > 0"
                            class="tabular-nums"
                        >
                            {{ fila.plazas.autorizadas }} plazas ·
                            {{ fila.plazas.vacantes }}
                            {{
                                fila.plazas.vacantes === 1
                                    ? 'vacante'
                                    : 'vacantes'
                            }}
                        </span>
                        <span v-if="puede.ver_salarios" class="tabular-nums">
                            {{
                                fila.tabulador
                                    ? `Medio ${formatoMoneda(fila.tabulador.medio)}`
                                    : 'Tabulador sin capturar'
                            }}
                        </span>
                    </CrudMobileCard>
                </template>
            </DataTable>
        </div>

        <!-- Alertas salariales -->
        <div
            v-if="salarios"
            v-show="seccion === 'alertas'"
            class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
        >
            <section
                class="flex flex-col gap-3 rounded-3xl border bg-card p-4 sm:p-6"
            >
                <div>
                    <h2 class="font-semibold">Personas fuera de rango</h2>
                    <p class="text-sm text-muted-foreground">
                        Bajo el mínimo hay riesgo de rotación; sobre el máximo
                        solo procede bono. Toca una persona para ir a su puesto.
                    </p>
                </div>
                <p
                    v-if="salarios.fuera_de_rango.length === 0"
                    class="rounded-2xl bg-success-soft/60 px-4 py-8 text-center text-sm"
                >
                    Nadie está fuera del rango de su puesto.
                </p>
                <ul v-else class="grid gap-2">
                    <li v-for="p in salarios.fuera_de_rango" :key="p.id">
                        <Link
                            :href="p.puesto_id ? show.url(p.puesto_id) : '#'"
                            class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border bg-background/50 p-3"
                        >
                            <span
                                class="grid size-9 shrink-0 place-items-center rounded-full"
                                :class="
                                    p.banda === 'bajo_minimo'
                                        ? 'bg-danger-soft text-destructive'
                                        : 'bg-warning-soft text-warning'
                                "
                            >
                                <AlertTriangle class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ p.nombre }}</span
                                >
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ p.puesto ?? 'Sin puesto'
                                    }}<template v-if="p.sucursal">
                                        · {{ p.sucursal }}</template
                                    >
                                </span>
                            </span>
                            <span class="text-right">
                                <span
                                    class="block text-sm font-semibold tabular-nums"
                                    >{{
                                        p.sueldo === null
                                            ? '—'
                                            : formatoMoneda(p.sueldo)
                                    }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ p.banda_etiqueta }} ·
                                    {{ pct(p.compa_ratio) }}</span
                                >
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>

            <section
                class="flex flex-col gap-3 rounded-3xl border bg-card p-4 sm:p-6"
            >
                <div>
                    <h2 class="font-semibold">Puestos sin tabulador</h2>
                    <p class="text-sm text-muted-foreground">
                        Sin rango no se calcula la posición de sus personas.
                    </p>
                </div>
                <p
                    v-if="salarios.sin_tabulador.length === 0"
                    class="rounded-2xl bg-success-soft/60 px-4 py-8 text-center text-sm"
                >
                    Todos los puestos activos tienen tabulador.
                </p>
                <ul v-else class="grid gap-1.5">
                    <li v-for="p in salarios.sin_tabulador" :key="p.puesto_id">
                        <Link
                            :href="
                                show.url(p.puesto_id, {
                                    query: { seccion: 'tabulador' },
                                })
                            "
                            class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border bg-background/50 px-3 py-2.5"
                        >
                            <span
                                class="min-w-0 flex-1 truncate text-sm font-medium"
                                >{{ p.puesto }}</span
                            >
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ p.colaboradores }} pers.</span
                            >
                            <span
                                class="inline-flex items-center gap-1 text-xs font-semibold text-primary"
                            >
                                Capturar <ArrowRight class="size-3.5" />
                            </span>
                        </Link>
                    </li>
                </ul>
                <p
                    v-if="salarios.resumen.sin_sueldo"
                    class="rounded-xl bg-warning-soft px-3 py-2 text-xs"
                >
                    {{ salarios.resumen.sin_sueldo }} persona(s) sin sueldo
                    capturado en su expediente.
                </p>
                <p
                    v-if="salarios.resumen.con_evaluacion === 0"
                    class="rounded-xl bg-info-soft px-3 py-2 text-xs"
                >
                    Sin evaluación de desempeño vigente: aún no se calculan
                    topes ni márgenes de incremento.
                </p>
            </section>
        </div>

        <!-- Matriz de mérito -->
        <MatrizMeritoPanel
            v-if="salarios"
            v-show="seccion === 'merito'"
            :matriz="salarios.matriz"
            :historial="salarios.historial_matriz"
            :puede-editar="puede.editar_salarios"
        />

        <!-- Importación (super administración) -->
        <ImportacionTabuladorPanel
            v-if="puede.importar_salarios"
            v-show="seccion === 'importar'"
            :importacion="salarios?.importacion ?? null"
        />
    </div>

    <PuestoFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :departamentos-disponibles="departamentosDisponibles"
    />
</template>
