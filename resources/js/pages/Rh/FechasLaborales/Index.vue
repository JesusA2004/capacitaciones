<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarRange,
    CheckCircle2,
    ExternalLink,
    Save,
    Undo2,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { pedirCorreccion } from '@/lib/correcciones';
import { formatearFecha } from '@/lib/fechas';
import { vTablaTarjetas } from '@/lib/tablaTarjetas';
import { dashboard } from '@/routes';
import { index, lote } from '@/routes/rh/fechas-laborales';
import type { FilaCapturaFechas, RespuestaPaginada } from '@/types';

/**
 * Captura masiva de fechas laborales (docs/FECHAS_LABORALES.md): sobre todo
 * para la plantilla importada por Excel. Edición en línea; al guardar, cada
 * fila es su propia corrección auditada con el mismo motivo.
 */
const props = defineProps<{
    filas: RespuestaPaginada<FilaCapturaFechas>;
    filtros: {
        busqueda: string;
        sucursal_id: string;
        puesto_id: string;
        solo_faltantes: string;
    };
    sucursales: { id: number; nombre: string }[];
    puestos: {
        id: number;
        nombre: string;
        meses_periodo_prueba: number | null;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Fechas laborales', href: '' },
        ],
    },
});

const CAMPOS = [
    'fecha_ingreso',
    'periodo_prueba_inicio',
    'fecha_induccion',
    'fecha_alta_imss',
    'periodo_prueba_cantidad',
    'periodo_prueba_unidad',
] as const;
type Campo = (typeof CAMPOS)[number];
type Valores = Record<Campo, string | null>;

function valoresDe(f: FilaCapturaFechas): Valores {
    return {
        fecha_ingreso: f.fecha_ingreso ?? '',
        periodo_prueba_inicio: f.periodo_prueba_inicio ?? '',
        fecha_induccion: f.fecha_induccion ?? '',
        fecha_alta_imss: f.fecha_alta_imss ?? '',
        periodo_prueba_cantidad: f.periodo_prueba_cantidad?.toString() ?? '',
        periodo_prueba_unidad: f.periodo_prueba_unidad ?? '',
    };
}

const edicion = reactive<Record<number, Valores>>({});
const errores = ref<Record<number, string>>({});
const guardadosRecientes = ref<number[]>([]);
const motivo = ref('');
const enviando = ref(false);
const mensaje = ref<string | null>(null);

function reiniciar(): void {
    for (const k of Object.keys(edicion)) {
        delete edicion[Number(k)];
    }

    for (const f of props.filas.data) {
        edicion[f.id] = valoresDe(f);
    }
}

watch(() => props.filas.data, reiniciar, { immediate: true });

function cambiosDe(
    f: FilaCapturaFechas,
): Partial<Record<Campo, string | null>> {
    const original = valoresDe(f);
    const actual = edicion[f.id];
    const salida: Partial<Record<Campo, string | null>> = {};

    if (!actual) {
        return salida;
    }

    for (const c of CAMPOS) {
        const valor =
            actual[c] === null || actual[c] === undefined
                ? ''
                : String(actual[c]);

        if (valor !== original[c]) {
            salida[c] = valor === '' ? null : valor;
        }
    }

    // Periodo específico: cantidad y unidad viajan juntas.
    if (
        'periodo_prueba_cantidad' in salida ||
        'periodo_prueba_unidad' in salida
    ) {
        const cantidad = String(actual.periodo_prueba_cantidad ?? '');
        salida.periodo_prueba_cantidad = cantidad === '' ? null : cantidad;
        salida.periodo_prueba_unidad =
            cantidad === '' ? null : actual.periodo_prueba_unidad || 'meses';
    }

    return salida;
}

const filasConCambios = computed(() =>
    props.filas.data.filter((f) => Object.keys(cambiosDe(f)).length > 0),
);

// ───────── Filtros ─────────

const busqueda = ref(props.filtros.busqueda);
const sucursalId = ref<string>(props.filtros.sucursal_id);
const puestoId = ref<string>(props.filtros.puesto_id);
const soloFaltantes = ref(props.filtros.solo_faltantes !== '0');

function filtrar(): void {
    router.get(
        index.url(),
        {
            busqueda: busqueda.value || undefined,
            sucursal_id: sucursalId.value || undefined,
            puesto_id: puestoId.value || undefined,
            solo_faltantes: soloFaltantes.value ? '1' : '0',
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(filtrar, 350);
});
watch([sucursalId, puestoId, soloFaltantes], filtrar);

const opcionesSucursal = computed(() =>
    props.sucursales.map((s) => ({ value: String(s.id), label: s.nombre })),
);
const opcionesPuesto = computed(() =>
    props.puestos.map((p) => ({
        value: String(p.id),
        label: p.nombre,
        descripcion: p.meses_periodo_prueba
            ? `Periodo de prueba: ${p.meses_periodo_prueba} meses`
            : 'Sin periodo de prueba configurado',
    })),
);

// ───────── Guardar ─────────

async function guardar(): Promise<void> {
    if (
        filasConCambios.value.length === 0 ||
        motivo.value.trim().length < 5 ||
        enviando.value
    ) {
        return;
    }

    enviando.value = true;
    mensaje.value = null;
    errores.value = {};

    try {
        const resultado = await pedirCorreccion<{
            guardados: number[];
            errores: { colaborador_id: number; mensaje: string }[];
        }>(lote.url(), {
            motivo: motivo.value,
            filas: filasConCambios.value.map((f) => ({
                colaborador_id: f.id,
                valores: cambiosDe(f),
            })),
        });

        for (const e of resultado.errores) {
            errores.value[e.colaborador_id] = e.mensaje;
        }

        guardadosRecientes.value = resultado.guardados;
        mensaje.value =
            resultado.errores.length === 0
                ? `Se guardaron ${resultado.guardados.length} persona(s).`
                : `Se guardaron ${resultado.guardados.length}; ${resultado.errores.length} con error (revisa las filas marcadas).`;

        if (resultado.guardados.length > 0) {
            const conError = new Set(
                resultado.errores.map((e) => e.colaborador_id),
            );
            const pendientes: Record<number, Valores> = {};

            for (const f of props.filas.data) {
                if (conError.has(f.id) && edicion[f.id]) {
                    pendientes[f.id] = { ...edicion[f.id] };
                }
            }

            router.reload({
                only: ['filas'],
                onSuccess: () => {
                    Object.assign(edicion, pendientes);
                },
            });
        }
    } catch (e) {
        mensaje.value = e instanceof Error ? e.message : 'No se pudo guardar.';
    } finally {
        enviando.value = false;
    }
}

function deshacer(f: FilaCapturaFechas): void {
    edicion[f.id] = valoresDe(f);
}
</script>

<template>
    <Head title="Fechas laborales" />

    <div class="pagina-ancha flex flex-col gap-5 pb-28">
        <CrudPageHeader
            titulo="Fechas laborales"
            descripcion="Captura en lote las fechas del personal activo (sobre todo de la plantilla importada por Excel): ingreso, inicio de capacitación inicial, inducción, alta IMSS y periodo de prueba específico."
            :icono="CalendarRange"
        />

        <!-- Filtros -->
        <section
            data-tour="fechas-laborales-filtros"
            class="flex flex-col gap-3 rounded-3xl border border-border bg-gradient-to-br from-crema/60 via-card to-salvia/30 p-4 shadow-xs lg:flex-row lg:flex-wrap lg:items-center"
        >
            <div
                data-tour="fechas-laborales-buscar"
                class="min-w-0 flex-1 lg:min-w-64"
            >
                <CrudSearchInput
                    v-model="busqueda"
                    placeholder="Buscar por nombre o número de empleado"
                />
            </div>
            <SelectSimple
                v-model="sucursalId"
                :opciones="opcionesSucursal"
                opcion-vacia="Todas las sucursales"
                placeholder="Sucursal"
                buscable
                class="lg:w-60"
                aria-label="Sucursal"
            />
            <SelectSimple
                v-model="puestoId"
                :opciones="opcionesPuesto"
                opcion-vacia="Todos los puestos"
                placeholder="Puesto"
                buscable
                class="lg:w-60"
                aria-label="Puesto"
            />
            <div
                data-tour="fechas-laborales-mostrar"
                class="inline-flex rounded-xl border border-border bg-card p-1"
                role="radiogroup"
                aria-label="Qué mostrar"
            >
                <button
                    v-for="o in [
                        { valor: true, etiqueta: 'Con fechas faltantes' },
                        { valor: false, etiqueta: 'Todos' },
                    ]"
                    :key="o.etiqueta"
                    type="button"
                    role="radio"
                    :aria-checked="soloFaltantes === o.valor"
                    class="rounded-lg px-3 py-1.5 text-sm whitespace-nowrap transition-colors"
                    :class="
                        soloFaltantes === o.valor
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="soloFaltantes = o.valor"
                >
                    {{ o.etiqueta }}
                </button>
            </div>
        </section>

        <EmptyState
            v-if="filas.data.length === 0"
            titulo="Nadie con fechas faltantes"
            descripcion="Con estos filtros, todo el personal activo ya tiene sus fechas. Cambia a «Todos» para revisarlas."
        />

        <!-- Tabla -->
        <div
            v-else
            data-tour="fechas-laborales-tabla"
            class="tabla-scroll rounded-3xl border border-border bg-card p-2 shadow-xs sm:p-0"
        >
            <table v-tabla-tarjetas class="w-full min-w-[1100px] text-sm">
                <thead
                    class="bg-muted/40 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="min-w-64 px-4 py-3 font-medium">
                            Colaborador
                        </th>
                        <th class="px-2 py-3 font-medium">Ingreso</th>
                        <th class="px-2 py-3 font-medium">
                            Inicio capacitación
                        </th>
                        <th class="px-2 py-3 font-medium">Inducción</th>
                        <th class="px-2 py-3 font-medium">Alta IMSS</th>
                        <th class="px-2 py-3 font-medium">Periodo de prueba</th>
                        <th class="px-4 py-3 font-medium">Fin del periodo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="f in filas.data"
                        :key="f.id"
                        class="border-t border-border align-top transition-colors"
                        :class="[
                            Object.keys(cambiosDe(f)).length > 0
                                ? 'bg-crema/40'
                                : '',
                            errores[f.id] ? 'bg-danger-soft/40' : '',
                        ]"
                    >
                        <td class="px-4 py-3">
                            <div class="flex items-start gap-2">
                                <div class="min-w-0">
                                    <Link
                                        :href="f.expediente_url"
                                        class="inline-flex items-center gap-1 font-medium hover:underline"
                                    >
                                        {{ f.nombre }}
                                        <ExternalLink
                                            class="size-3 text-muted-foreground"
                                        />
                                    </Link>
                                    <p class="text-xs text-muted-foreground">
                                        {{ f.puesto ?? 'Sin puesto'
                                        }}<template v-if="f.sucursal">
                                            · {{ f.sucursal }}</template
                                        >
                                    </p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span
                                            v-if="f.importado"
                                            class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground"
                                            >Importado</span
                                        >
                                        <span
                                            v-for="x in f.faltantes"
                                            :key="x"
                                            class="rounded-full bg-warning-soft px-1.5 py-0.5 text-[10px] font-medium text-warning"
                                            >Falta {{ x.toLowerCase() }}</span
                                        >
                                        <span
                                            v-if="
                                                guardadosRecientes.includes(
                                                    f.id,
                                                )
                                            "
                                            class="inline-flex items-center gap-0.5 rounded-full bg-success-soft px-1.5 py-0.5 text-[10px] font-medium text-success"
                                            ><CheckCircle2 class="size-3" />
                                            Guardado</span
                                        >
                                    </div>
                                    <p
                                        v-if="errores[f.id]"
                                        class="mt-1 flex items-start gap-1 text-xs text-danger"
                                    >
                                        <AlertTriangle
                                            class="mt-0.5 size-3 shrink-0"
                                        />
                                        {{ errores[f.id] }}
                                    </p>
                                </div>
                                <button
                                    v-if="Object.keys(cambiosDe(f)).length > 0"
                                    type="button"
                                    class="ml-auto text-muted-foreground hover:text-foreground"
                                    :aria-label="`Deshacer cambios de ${f.nombre}`"
                                    @click="deshacer(f)"
                                >
                                    <Undo2 class="size-4" />
                                </button>
                            </div>
                        </td>
                        <td class="w-44 px-2 py-3">
                            <div v-if="edicion[f.id]" class="min-w-0 flex-1">
                                <DatePicker
                                    v-model="edicion[f.id].fecha_ingreso"
                                    :anio-desde="1980"
                                    placeholder="Falta"
                                />
                            </div>
                        </td>
                        <td class="w-44 px-2 py-3">
                            <div v-if="edicion[f.id]" class="min-w-0 flex-1">
                                <DatePicker
                                    v-model="
                                        edicion[f.id].periodo_prueba_inicio
                                    "
                                    :anio-desde="1980"
                                    limpiable
                                    :placeholder="
                                        f.fecha_ingreso
                                            ? `= ingreso (${formatearFecha(f.fecha_ingreso)})`
                                            : 'Igual al ingreso'
                                    "
                                />
                            </div>
                        </td>
                        <td class="w-44 px-2 py-3">
                            <div v-if="edicion[f.id]" class="min-w-0 flex-1">
                                <DatePicker
                                    v-model="edicion[f.id].fecha_induccion"
                                    :anio-desde="1980"
                                    limpiable
                                    :placeholder="
                                        f.fecha_induccion_sistema
                                            ? `PEOPLE: ${formatearFecha(f.fecha_induccion_sistema)}`
                                            : 'Falta'
                                    "
                                />
                            </div>
                        </td>
                        <td class="w-44 px-2 py-3">
                            <div v-if="edicion[f.id]" class="min-w-0 flex-1">
                                <DatePicker
                                    v-model="edicion[f.id].fecha_alta_imss"
                                    :anio-desde="1980"
                                    limpiable
                                    placeholder="Falta"
                                />
                            </div>
                        </td>
                        <td class="w-56 px-2 py-3">
                            <div
                                v-if="edicion[f.id]"
                                class="flex min-w-0 flex-1 items-center gap-1.5"
                            >
                                <Input
                                    :model-value="
                                        edicion[f.id].periodo_prueba_cantidad ??
                                        ''
                                    "
                                    type="number"
                                    min="1"
                                    max="730"
                                    class="h-9 w-16"
                                    placeholder="—"
                                    :aria-label="`Periodo específico de ${f.nombre}`"
                                    @update:model-value="
                                        (v: string | number) =>
                                            (edicion[
                                                f.id
                                            ].periodo_prueba_cantidad =
                                                String(v))
                                    "
                                />
                                <SelectSimple
                                    v-model="
                                        edicion[f.id].periodo_prueba_unidad
                                    "
                                    :opciones="[
                                        { value: 'meses', label: 'Meses' },
                                        { value: 'dias', label: 'Días' },
                                    ]"
                                    placeholder="Meses"
                                    size="sm"
                                    :buscable="false"
                                    class="w-24"
                                    :aria-label="`Unidad del periodo de ${f.nombre}`"
                                />
                            </div>
                            <p class="mt-1 text-[11px] text-muted-foreground">
                                <template
                                    v-if="f.periodo.origen === 'especifico'"
                                    >Específico:
                                    {{ f.periodo.etiqueta }}</template
                                >
                                <template
                                    v-else-if="f.periodo.origen === 'puesto'"
                                    >Vacío = hereda del puesto ({{
                                        f.periodo.etiqueta
                                    }})</template
                                >
                                <template v-else
                                    >Sin periodo en el puesto</template
                                >
                            </p>
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{
                                f.fin_periodo
                                    ? formatearFecha(f.fin_periodo)
                                    : '—'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <PaginacionTabla :datos="filas" />

        <!-- Barra de guardado -->
        <div
            v-if="filasConCambios.length > 0 || mensaje"
            data-tour="fechas-laborales-guardar"
            class="sticky bottom-4 z-10 flex flex-col gap-3 rounded-2xl border border-border bg-card/95 p-4 shadow-lg backdrop-blur md:flex-row md:items-center"
        >
            <p class="text-sm font-medium md:w-56">
                <template v-if="filasConCambios.length > 0"
                    >{{ filasConCambios.length }} persona(s) con
                    cambios</template
                >
                <template v-else>{{ mensaje }}</template>
            </p>
            <Input
                v-if="filasConCambios.length > 0"
                v-model="motivo"
                class="flex-1"
                placeholder="Motivo (obligatorio, queda en el historial de cada persona). Ej. Captura inicial desde el archivo de RH."
                aria-label="Motivo del cambio"
            />
            <p
                v-if="filasConCambios.length > 0 && mensaje"
                class="text-xs text-muted-foreground"
            >
                {{ mensaje }}
            </p>
            <Button
                v-if="filasConCambios.length > 0"
                :disabled="motivo.trim().length < 5 || enviando"
                @click="guardar"
            >
                <Spinner v-if="enviando" />
                <Save v-else class="size-4" />
                Guardar
            </Button>
        </div>
    </div>
</template>
