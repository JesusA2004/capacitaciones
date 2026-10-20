<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Eye,
    FilePlus2,
    FileSignature,
    Files,
    PencilLine,
    ScrollText,
    Sparkles,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import CamposDatosFaltantes from '@/components/documentos/CamposDatosFaltantes.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFiltros } from '@/composables/useFiltros';
import { useVistaPreviaPdf } from '@/composables/useVistaPreviaPdf';
import { datosFaltantesCompletos } from '@/lib/datosFaltantes';
import { getJson } from '@/lib/http';
import {
    completarDatos as completarDatosRuta,
    generar as generarRuta,
    generarTodos as generarTodosRuta,
    index as indexContratos,
    lote as loteRuta,
    vistaPrevia as vistaPreviaRuta,
} from '@/routes/rh/contratos';
import { index as indexContratosIngreso } from '@/routes/rh/contratos-ingreso';
import type {
    DocumentoCorrespondiente,
    EstadoContratoCorrespondiente,
    LoteContratos,
    PlanContratos,
    RespuestaPaginada,
} from '@/types';

type Opcion = { id: number; nombre: string };

const props = defineProps<{
    filas: RespuestaPaginada<PlanContratos>;
    resumen: Record<string, number>;
    faltantesComunes: { etiqueta: string; total: number }[];
    opciones: {
        sucursales: Opcion[];
        puestos: Opcion[];
        estados: { value: string; label: string }[];
    };
    filtros: {
        busqueda?: string;
        sucursal_id?: string;
        puesto_id?: string;
        estado?: string;
    };
    lote: LoteContratos | null;
    puedeGenerar: boolean;
    puedeCompletar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: '/dashboard' },
            { title: 'Contratos', href: '/rh/contratos' },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    indexContratos.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        puesto_id: props.filtros.puesto_id ?? '',
        estado: props.filtros.estado ?? '',
    },
);

const filtrosActivos = computed(
    () =>
        [filtros.sucursal_id, filtros.puesto_id, filtros.estado].filter(
            (v) => v !== '',
        ).length,
);

function formatoFecha(fecha: string | null): string {
    if (!fecha) {
        return '—';
    }

    const [a, m, d] = fecha.split('-');

    return `${d}/${m}/${a}`;
}

type Variante = 'success' | 'warning' | 'info' | 'destructive' | 'secondary';

function variante(estado: EstadoContratoCorrespondiente): Variante {
    switch (estado) {
        case 'generado':
        case 'completo':
            return 'success';
        case 'por_generar':
            return 'info';
        case 'faltan_datos':
            return 'destructive';
        case 'formato_no_listo':
        case 'pendiente_evaluacion':
            return 'warning';
        default:
            return 'secondary';
    }
}

// ───────── Vista previa (motor documental, nada se guarda) ─────────
const visor = useVistaPreviaPdf();

function verDocumento(fila: PlanContratos, doc: DocumentoCorrespondiente) {
    visor.abrir([
        {
            clave: doc.clave,
            nombre: `${doc.nombre} · ${fila.nombre}`,
            url: vistaPreviaRuta.url({
                colaborador: fila.id,
                clave: doc.clave,
            }),
        },
    ]);
}

function puedeVerPrevia(doc: DocumentoCorrespondiente): boolean {
    return doc.estado === 'por_generar' || doc.estado === 'faltan_datos';
}

// ───────── Generar por colaborador ─────────
const generando = ref<number | null>(null);

function generar(fila: PlanContratos) {
    generando.value = fila.id;
    router.post(
        generarRuta.url(fila.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => (generando.value = null),
        },
    );
}

// ───────── Completar datos faltantes ─────────
const completando = ref<PlanContratos | null>(null);
const valores = ref<Record<string, string>>({});
const guardando = ref(false);
const editablesCompletos = computed(() =>
    completando.value
        ? datosFaltantesCompletos(completando.value.faltantes, valores.value)
        : false,
);
const hayEditables = computed(
    () => completando.value?.faltantes.some((f) => f.editable) ?? false,
);

function abrirCompletar(fila: PlanContratos) {
    completando.value = fila;
    valores.value = {};
}

function guardarDatos() {
    if (!completando.value) {
        return;
    }

    guardando.value = true;
    router.post(
        completarDatosRuta.url(completando.value.id),
        { datos: valores.value },
        {
            preserveScroll: true,
            onSuccess: () => (completando.value = null),
            onFinish: () => (guardando.value = false),
        },
    );
}

// ───────── Generar todos (lote en la cola) ─────────
const confirmarTodos = ref(false);
const lote = ref<LoteContratos | null>(props.lote);
let sondeo: ReturnType<typeof setInterval> | undefined;

watch(
    () => props.lote,
    (valor) => {
        lote.value = valor;
        vigilarLote();
    },
);

const generablesVisibles = computed(() =>
    props.filas.data.filter((f) => f.generable),
);
const ejemploPrevia = computed(() => {
    for (const fila of generablesVisibles.value) {
        const doc = fila.contratos
            .flatMap((e) => e.documentos)
            .find((d) => d.estado === 'por_generar');

        if (doc) {
            return { fila, doc };
        }
    }

    return null;
});

const porcentaje = computed(() =>
    lote.value && lote.value.total > 0
        ? Math.round((lote.value.procesados / lote.value.total) * 100)
        : 0,
);

function vigilarLote() {
    clearInterval(sondeo);

    if (!lote.value?.en_curso) {
        return;
    }

    const id = lote.value.id;

    sondeo = setInterval(async () => {
        try {
            const respuesta = await getJson<{ data: LoteContratos }>(
                loteRuta.url(id),
            );
            lote.value = respuesta.data;

            if (!respuesta.data.en_curso) {
                clearInterval(sondeo);
                router.reload({
                    only: ['filas', 'resumen', 'faltantesComunes'],
                });
            }
        } catch {
            clearInterval(sondeo);
        }
    }, 3000);
}

vigilarLote();
onBeforeUnmount(() => clearInterval(sondeo));

function generarTodos() {
    router.post(
        generarTodosRuta.url(),
        {},
        {
            preserveScroll: true,
            onSuccess: () => (confirmarTodos.value = false),
        },
    );
}

const resultadosVisibles = computed(() =>
    (lote.value?.resultados ?? []).slice(0, 200),
);
</script>

<template>
    <Head title="Contratos" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Contratos"
            descripcion="Contratos que le corresponden a cada colaborador activo según su fecha de ingreso y su puesto: contrato inicial (capacitación inicial) con su paquete y, si ya pasó su periodo de prueba, el contrato por tiempo indeterminado. Genera lo que falte y únelo a su expediente."
            :icono="FileSignature"
        >
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="indexContratosIngreso()">
                        <ScrollText class="size-4" />
                        Contratos de ingreso
                    </Link>
                </Button>
                <Button
                    v-if="puedeGenerar"
                    :disabled="lote?.en_curso === true"
                    @click="confirmarTodos = true"
                >
                    <Sparkles class="size-4" />
                    Generar todos
                </Button>
            </div>
        </CrudPageHeader>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <MetricCard
                etiqueta="Colaboradores activos"
                :valor="resumen.total ?? 0"
                :icono="ClipboardList"
            />
            <MetricCard
                etiqueta="Contratos completos"
                :valor="resumen.completo ?? 0"
                :icono="CheckCircle2"
                color-clase="bg-success-soft text-success"
            />
            <MetricCard
                etiqueta="Por generar"
                :valor="resumen.por_generar ?? 0"
                :icono="FilePlus2"
                color-clase="bg-info-soft text-info"
            />
            <MetricCard
                etiqueta="Faltan datos"
                :valor="resumen.faltan_datos ?? 0"
                :icono="AlertTriangle"
                color-clase="bg-danger-soft text-destructive"
            />
            <MetricCard
                etiqueta="Formato no listo"
                :valor="resumen.formato_no_listo ?? 0"
                :icono="Files"
                color-clase="bg-warning-soft text-warning"
            />
            <MetricCard
                etiqueta="Pendiente de evaluación"
                :valor="resumen.pendiente_evaluacion ?? 0"
                :icono="ScrollText"
                color-clase="bg-warning-soft text-warning"
            />
        </div>

        <!-- Avance / resumen de la última generación en lote -->
        <section
            v-if="lote"
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="font-medium">
                        Generación en lote · {{ lote.estado_etiqueta }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ lote.iniciado_por ?? 'Usuario' }} ·
                        {{ lote.procesados }} de {{ lote.total }} revisados
                    </p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    <Badge variant="success"
                        >{{ lote.generados }} generados</Badge
                    >
                    <Badge variant="secondary"
                        >{{ lote.sin_cambios }} ya los tenían</Badge
                    >
                    <Badge variant="warning"
                        >{{ lote.omitidos }} omitidos</Badge
                    >
                    <Badge variant="destructive"
                        >{{ lote.errores }} con error</Badge
                    >
                </div>
            </div>
            <Progress v-if="lote.en_curso" :model-value="porcentaje" />
            <p v-if="lote.error" class="text-sm text-destructive">
                {{ lote.error }}
            </p>
            <details v-if="resultadosVisibles.length" class="text-sm">
                <summary class="cursor-pointer text-muted-foreground">
                    Ver detalle (generados, omitidos con motivo y errores)
                </summary>
                <ul class="mt-2 flex max-h-72 flex-col gap-1 overflow-y-auto">
                    <li
                        v-for="r in resultadosVisibles"
                        :key="r.colaborador_id"
                        class="flex flex-wrap items-start gap-2 border-b border-border/40 py-1"
                    >
                        <Badge
                            :variant="
                                r.resultado === 'generado'
                                    ? 'success'
                                    : r.resultado === 'omitido'
                                      ? 'warning'
                                      : 'destructive'
                            "
                            >{{ r.resultado_etiqueta }}</Badge
                        >
                        <span class="font-medium">{{ r.nombre }}</span>
                        <span
                            v-if="r.documentos.length"
                            class="text-xs text-muted-foreground"
                            >{{ r.documentos.join(', ') }}</span
                        >
                        <span v-if="r.motivo" class="text-xs">{{
                            r.motivo
                        }}</span>
                    </li>
                </ul>
            </details>
        </section>

        <section
            v-if="faltantesComunes.length"
            class="rounded-2xl border border-border/60 bg-crema p-4"
        >
            <p class="mb-2 text-sm font-medium">
                Datos que más faltan para generar contratos
            </p>
            <div class="flex flex-wrap gap-2">
                <Badge
                    v-for="f in faltantesComunes"
                    :key="f.etiqueta"
                    variant="outline"
                    >{{ f.etiqueta }} · {{ f.total }}</Badge
                >
            </div>
        </section>

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar por colaborador o número de empleado..."
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
                <div
                    class="flex flex-col gap-3 [&_[data-slot=select-trigger]]:w-full"
                >
                    <Select
                        :model-value="filtros.estado"
                        @update:model-value="
                            (v) => {
                                filtros.estado = String(v ?? '');
                                aplicar();
                            }
                        "
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Estado" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="e in opciones.estados"
                                :key="e.value"
                                :value="e.value"
                                >{{ e.label }}</SelectItem
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
                        <SelectTrigger>
                            <SelectValue placeholder="Sucursal" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="s in opciones.sucursales"
                                :key="s.id"
                                :value="String(s.id)"
                                >{{ s.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <Select
                        :model-value="filtros.puesto_id"
                        @update:model-value="
                            (v) => {
                                filtros.puesto_id = String(v ?? '');
                                aplicar();
                            }
                        "
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Puesto" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="p in opciones.puestos"
                                :key="p.id"
                                :value="String(p.id)"
                                >{{ p.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </template>
        </CrudToolbar>

        <EmptyState
            v-if="!filas.data.length"
            :icono="FileSignature"
            titulo="Sin colaboradores"
            descripcion="No hay colaboradores activos con esos filtros dentro de tu alcance."
        />

        <div v-else class="tabla-scroll rounded-2xl border border-border/60">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Colaborador</TableHead>
                        <TableHead>Ingreso / periodo de prueba</TableHead>
                        <TableHead>Contratos que le corresponden</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead class="text-right">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="fila in filas.data"
                        :key="fila.id"
                        class="align-top"
                    >
                        <TableCell>
                            <Link
                                :href="fila.expediente_url"
                                class="font-medium hover:underline"
                                >{{ fila.nombre }}</Link
                            >
                            <p class="text-xs text-muted-foreground">
                                <span v-if="fila.numero_empleado"
                                    >#{{ fila.numero_empleado }} · </span
                                >{{ fila.puesto ?? 'Sin puesto' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ fila.sucursal ?? 'Sin sucursal' }}
                            </p>
                        </TableCell>
                        <TableCell class="text-xs">
                            <p>
                                Ingreso: {{ formatoFecha(fila.fecha_ingreso) }}
                            </p>
                            <p v-if="fila.meses_periodo_prueba">
                                Prueba: {{ fila.meses_periodo_prueba }}
                                {{
                                    fila.meses_periodo_prueba === 1
                                        ? 'mes'
                                        : 'meses'
                                }}
                            </p>
                            <p v-if="fila.fin_periodo_prueba">
                                {{
                                    fila.periodo_vencido
                                        ? 'Terminó'
                                        : 'Termina'
                                }}: {{ formatoFecha(fila.fin_periodo_prueba) }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <p
                                v-if="fila.bloqueos.length"
                                class="text-xs text-destructive"
                            >
                                <span
                                    v-for="b in fila.bloqueos"
                                    :key="b.etiqueta"
                                    class="block"
                                    >{{ b.detalle }}
                                    <Link
                                        v-if="b.configurar_url"
                                        :href="b.configurar_url"
                                        class="font-medium underline"
                                        >Configurar duración del puesto</Link
                                    ></span
                                >
                            </p>
                            <p
                                v-else-if="fila.estado === 'no_aplica'"
                                class="text-xs text-muted-foreground"
                            >
                                {{ fila.motivo }}
                            </p>
                            <div
                                v-for="etapa in fila.contratos"
                                :key="etapa.etapa"
                                class="mb-2 flex flex-col gap-1 last:mb-0"
                            >
                                <p class="text-xs font-semibold">
                                    {{ etapa.etiqueta }} ·
                                    {{ formatoFecha(etapa.fecha_inicio) }}
                                    <template v-if="etapa.fecha_fin">
                                        al {{ formatoFecha(etapa.fecha_fin) }}
                                    </template>
                                    <span
                                        class="font-normal text-muted-foreground"
                                    >
                                        ·
                                        {{
                                            etapa.contrato_id
                                                ? `Registrado (${etapa.estado_contrato})`
                                                : 'Sin registrar'
                                        }}
                                    </span>
                                </p>
                                <div
                                    v-for="doc in etapa.documentos"
                                    :key="doc.clave"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <Badge :variant="variante(doc.estado)">{{
                                        doc.estado_etiqueta
                                    }}</Badge>
                                    <a
                                        v-if="doc.descargar_url"
                                        :href="doc.descargar_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-xs hover:underline"
                                        >{{ doc.nombre }}</a
                                    >
                                    <span v-else class="text-xs">{{
                                        doc.nombre
                                    }}</span>
                                    <Button
                                        v-if="puedeVerPrevia(doc)"
                                        variant="ghost"
                                        size="sm"
                                        class="h-6 px-2"
                                        :disabled="visor.cargando.value"
                                        @click="verDocumento(fila, doc)"
                                    >
                                        <Eye class="size-3.5" />
                                        Vista previa
                                    </Button>
                                    <span
                                        v-if="doc.motivo"
                                        class="w-full text-xs text-muted-foreground"
                                        >{{ doc.motivo }}</span
                                    >
                                    <a
                                        v-if="doc.firmado_cargado"
                                        :href="doc.firmado_cargado.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="w-full text-xs text-success hover:underline"
                                        >{{ doc.firmado_cargado.etiqueta }}</a
                                    >
                                </div>
                            </div>
                        </TableCell>
                        <TableCell>
                            <Badge :variant="variante(fila.estado)">{{
                                fila.estado_etiqueta
                            }}</Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex flex-col items-end gap-1">
                                <Button
                                    v-if="puedeGenerar && fila.generable"
                                    size="sm"
                                    :disabled="
                                        generando !== null ||
                                        lote?.en_curso === true
                                    "
                                    @click="generar(fila)"
                                >
                                    <FilePlus2 class="size-4" />
                                    {{
                                        generando === fila.id
                                            ? 'Generando…'
                                            : 'Generar'
                                    }}
                                </Button>
                                <Button
                                    v-if="fila.faltantes.length"
                                    variant="outline"
                                    size="sm"
                                    @click="abrirCompletar(fila)"
                                >
                                    <PencilLine class="size-4" />
                                    Completar datos
                                </Button>
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="fila.ciclo_url"
                                        >Ciclo laboral</Link
                                    >
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <PaginacionTabla :datos="filas" />
    </div>

    <DocumentPreviewDialog
        :open="visor.abierto.value"
        :archivos="visor.archivos.value"
        :indice="visor.indice.value"
        @update:open="visor.alCambiarAbierto"
        @update:indice="(i: number) => (visor.indice.value = i)"
    />

    <!-- Confirmar «Generar todos» -->
    <Dialog
        :open="confirmarTodos"
        @update:open="(v: boolean) => (confirmarTodos = v)"
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >Generar todos los contratos pendientes</DialogTitle
                >
                <DialogDescription>
                    Se revisa a cada colaborador activo de tu alcance y se
                    generan solo los contratos que aún no están en su expediente
                    (nunca se duplica uno existente). Quien tenga datos
                    faltantes se omite y queda listado con el motivo. Corre en
                    segundo plano; el avance se ve en esta pantalla.
                </DialogDescription>
            </DialogHeader>
            <div
                v-if="ejemploPrevia"
                class="rounded-xl border border-border/60 bg-crema p-3 text-sm"
            >
                <p>
                    Antes de continuar, revisa cómo queda uno:
                    <strong>{{ ejemploPrevia.doc.nombre }}</strong> de
                    {{ ejemploPrevia.fila.nombre }}.
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-2"
                    :disabled="visor.cargando.value"
                    @click="verDocumento(ejemploPrevia.fila, ejemploPrevia.doc)"
                >
                    <Eye class="size-4" />
                    Vista previa
                </Button>
            </div>
            <DialogFooter>
                <Button variant="ghost" @click="confirmarTodos = false"
                    >Cancelar</Button
                >
                <Button @click="generarTodos">
                    <Sparkles class="size-4" />
                    Generar todos
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Completar datos faltantes -->
    <Dialog
        :open="completando !== null"
        @update:open="(v: boolean) => !v && !guardando && (completando = null)"
    >
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >Datos faltantes de {{ completando?.nombre }}</DialogTitle
                >
                <DialogDescription>
                    Se guardan en su expediente y sirven para todos sus
                    documentos.
                </DialogDescription>
            </DialogHeader>
            <CamposDatosFaltantes
                v-if="completando"
                v-model="valores"
                :faltantes="completando.faltantes"
            />
            <p
                v-if="hayEditables && !puedeCompletar"
                class="text-sm text-destructive"
            >
                No tienes permiso para editar el expediente; pídeselo a RH.
            </p>
            <DialogFooter>
                <Button
                    variant="ghost"
                    :disabled="guardando"
                    @click="completando = null"
                    >Cancelar</Button
                >
                <Button
                    v-if="hayEditables && puedeCompletar"
                    :disabled="!editablesCompletos || guardando"
                    @click="guardarDatos"
                >
                    {{ guardando ? 'Guardando…' : 'Guardar datos' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
