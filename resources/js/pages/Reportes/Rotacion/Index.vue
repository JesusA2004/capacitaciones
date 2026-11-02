<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BarChart3,
    ChevronDown,
    FileSpreadsheet,
    FileText,
    History,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import PestanasBajasNav from '@/components/bajas/PestanasBajas.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import FiltrosRotacionBarra from '@/components/rotacion/FiltrosRotacionBarra.vue';
import SeccionCausales from '@/components/rotacion/SeccionCausales.vue';
import SeccionHistorico from '@/components/rotacion/SeccionHistorico.vue';
import SeccionResumen from '@/components/rotacion/SeccionResumen.vue';
import SeccionSucursales from '@/components/rotacion/SeccionSucursales.vue';
import SeccionTendencias from '@/components/rotacion/SeccionTendencias.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import {
    esSeccion,
    etiquetaMes,
    proveerNavegacionRotacion,
    SECCIONES,
} from '@/lib/rotacion';
import { dashboard } from '@/routes';
import { index as rotacionExcel } from '@/routes/reportes/rotacion';
import rutasTablero from '@/routes/reportes/rotacion/tablero';
import rutasBajas from '@/routes/rh/bajas';
import type {
    AntiguedadRotacion,
    CalidadRotacion,
    CatalogoHomologacionRotacion,
    CausalesRotacionTablero,
    DesgloseRotacion,
    FiltrosRotacionTablero,
    HallazgoRotacionTablero,
    ImportacionHistoricoRotacion,
    MatrizRotacion,
    MetasRotacionPanel,
    OpcionesRotacion,
    PaginaHistoricoRotacion,
    PermisosRotacion,
    PestanasBajas,
    PosibleDuplicadoRotacion,
    ResumenRotacion,
    SucursalRotacionFila,
    TendenciaRotacion,
} from '@/types';

/**
 * Reportes → Rotación de personal (docs/ROTACION.md). Una sola pantalla con
 * cinco secciones conectadas: indicador → tendencia/causal → sucursal → baja
 * individual → expediente o acción. Todo vive en la query string (filtros
 * generales + desglose), así que al volver de un expediente la pantalla
 * queda donde estaba. Las cifras salen del motor único del backend; aquí no
 * se recalcula nada.
 */
const props = defineProps<{
    pestanas: PestanasBajas;
    filtros: FiltrosRotacionTablero;
    desglose: DesgloseRotacion;
    opciones: OpcionesRotacion;
    mesInicioSistema: string;
    resumen: ResumenRotacion;
    tendencia: TendenciaRotacion;
    causales: CausalesRotacionTablero;
    antiguedad: AntiguedadRotacion;
    sucursales: SucursalRotacionFila[];
    matriz: MatrizRotacion;
    hallazgos: HallazgoRotacionTablero[];
    calidad: CalidadRotacion;
    historico: PaginaHistoricoRotacion | null;
    conciliacion?: PosibleDuplicadoRotacion[];
    catalogo?: CatalogoHomologacionRotacion;
    importacion: ImportacionHistoricoRotacion | null;
    metas: MetasRotacionPanel;
    puede: PermisosRotacion;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas', href: rutasBajas.index() },
            { title: 'Rotación de personal', href: '' },
        ],
    },
});

const nav = proveerNavegacionRotacion(
    esSeccion(props.desglose.seccion) ? props.desglose.seccion : 'resumen',
);

// Una redirección (cargar histórico) o el botón «atrás» pueden traer otra sección.
watch(
    () => props.desglose.seccion,
    (s) => {
        if (esSeccion(s) && s !== nav.seccion.value) {
            nav.seccion.value = s;
        }
    },
);

const seccion = computed({
    get: () => nav.seccion.value,
    set: (s) => nav.cambiarSeccion(s),
});

const conDetalle = computed(
    () =>
        props.filtros.departamento_id !== null ||
        props.filtros.puesto_id !== null ||
        props.filtros.clasificacion !== null ||
        props.filtros.causal_analitica_id !== null ||
        props.filtros.antiguedad !== null,
);

const corteParcial = computed(
    () =>
        props.filtros.corte_parcial ||
        props.tendencia.meses.some((m) => m.corte_parcial),
);

const pendientesCalidad = computed(
    () =>
        props.calidad.sin_causal +
        props.calidad.sin_fecha_ingreso +
        props.calidad.fecha_inconsistente,
);

const opcionesSeccion = computed(() =>
    SECCIONES.map((s) => ({
        ...s,
        contador:
            s.valor === 'historico' && props.puede.ver_personas
                ? pendientesCalidad.value
                : undefined,
    })),
);

const urlExcel = computed(() =>
    rutasTablero.excel.url({ query: nav.consultaExportacion.value }),
);
const urlPdf = computed(() =>
    rutasTablero.pdf.url({ query: nav.consultaExportacion.value }),
);
const urlPdfDetallado = computed(() =>
    rutasTablero.pdfDetallado.url({ query: nav.consultaExportacion.value }),
);
</script>

<template>
    <Head title="Rotación de personal" />

    <div class="rotacion pagina-ancha flex flex-col gap-4">
        <CrudPageHeader
            titulo="Rotación de personal"
            descripcion="Bajas, tendencias, causales y sucursales con los mismos filtros."
            :icono="BarChart3"
        >
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="outline">
                        <FileText class="size-4" />Exportar
                        <ChevronDown class="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-64">
                    <DropdownMenuItem as-child>
                        <a :href="urlExcel" class="cursor-pointer">
                            <FileSpreadsheet class="size-4" />
                            <span class="flex flex-col">
                                Excel
                                <span class="text-[11px] text-muted-foreground"
                                    >Una hoja por sección{{
                                        puede.exportar_detalle
                                            ? ' + relación individual'
                                            : ''
                                    }}</span
                                >
                            </span>
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <a :href="urlPdf" class="cursor-pointer">
                            <FileText class="size-4" />
                            <span class="flex flex-col">
                                PDF resumen ejecutivo
                                <span class="text-[11px] text-muted-foreground"
                                    >Horizontal, para Dirección</span
                                >
                            </span>
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <a :href="urlPdfDetallado" class="cursor-pointer">
                            <FileText class="size-4" />
                            <span class="flex flex-col">
                                PDF reporte detallado
                                <span class="text-[11px] text-muted-foreground"
                                    >Con el mismo desglose del histórico</span
                                >
                            </span>
                        </a>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <Button v-if="puede.ver_historico_excel" variant="outline" as-child>
                <Link :href="rotacionExcel()"
                    ><History class="size-4" />Totales del Excel</Link
                >
            </Button>
        </CrudPageHeader>

        <PestanasBajasNav :pestanas="pestanas" actual="rotacion" />

        <FiltrosRotacionBarra
            :filtros="filtros"
            :opciones="opciones"
            :corte-parcial="corteParcial"
            :no-desglosables="resumen.no_desglosables"
            :meses-recortados="calidad.meses_recortados"
        />

        <SelectorVista
            v-model="seccion"
            :opciones="opcionesSeccion"
            etiqueta="Secciones de rotación"
        />

        <div v-show="seccion === 'resumen'">
            <SeccionResumen
                :resumen="resumen"
                :hallazgos="hallazgos"
                :metas="metas"
                :puede="puede"
                :con-detalle="conDetalle"
                :mes-filtro="filtros.hasta.slice(0, 7)"
            />
        </div>
        <div v-show="seccion === 'tendencias'">
            <SeccionTendencias
                :tendencia="tendencia"
                :con-detalle="conDetalle"
            />
        </div>
        <div v-show="seccion === 'causales'">
            <SeccionCausales :causales="causales" :antiguedad="antiguedad" />
        </div>
        <div v-show="seccion === 'sucursales'">
            <SeccionSucursales
                :sucursales="sucursales"
                :matriz="matriz"
                :con-detalle="conDetalle"
            />
        </div>
        <div v-show="seccion === 'historico'">
            <SeccionHistorico
                :historico="historico"
                :desglose="desglose"
                :opciones="opciones"
                :puede="puede"
                :calidad="calidad"
                :conciliacion="conciliacion"
                :catalogo="catalogo"
                :importacion="importacion"
            />
        </div>

        <p class="text-[11px] text-muted-foreground">
            Desde {{ etiquetaMes(mesInicioSistema, true) }} las bajas salen del
            sistema; antes, del histórico individual y de los totales mensuales
            del Excel. Un mes sin ninguna fuente se muestra «Sin datos», nunca
            como cero.
        </p>
    </div>
</template>

<style scoped>
@media (prefers-reduced-motion: reduce) {
    .rotacion :deep(.aparece-escalonado) {
        animation: none;
    }
}
</style>
