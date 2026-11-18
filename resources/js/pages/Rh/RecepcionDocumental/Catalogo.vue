<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    CalendarCheck,
    FileStack,
    GripVertical,
    Layers,
    Loader2,
    PackageCheck,
    Settings2,
    ToggleRight,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmptyState from '@/components/Common/EmptyState.vue';
import DashboardChartCard from '@/components/Dashboard/DashboardChartCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InterruptorRecepcion from '@/components/recepcion-documental/InterruptorRecepcion.vue';
import KpiRecepcion from '@/components/recepcion-documental/KpiRecepcion.vue';
import SeleccionMultipleBuscable from '@/components/recepcion-documental/SeleccionMultipleBuscable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { formatearFechaHora } from '@/lib/fechas';
import { enviarJson, ErrorSolicitud } from '@/lib/http';
import { dashboard } from '@/routes';
import { index as inicioRecepcion } from '@/routes/rh/recepcion-documental';
import {
    reordenar as rutaReordenar,
    update as rutaActualizar,
    vistaPrevia as rutaVistaPrevia,
} from '@/routes/rh/recepcion-documental/catalogo';
import type {
    EntradaCatalogoRecepcion,
    RecalculoCatalogoRecepcion,
    RespuestaCatalogoRecepcion,
    VistaPreviaRecepcion,
} from '@/types';

type Opcion = { value: number; label: string };

const props = defineProps<{
    entradas: EntradaCatalogoRecepcion[];
    previas: Record<number, VistaPreviaRecepcion>;
    periodoActual: { clave: string; etiqueta: string };
    sucursales: (Opcion & { empresa_id: number | null })[];
    empresas: Opcion[];
    puedeConfigurar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Recepción documental', href: inicioRecepcion() },
            { title: 'Lo que recibo', href: '' },
        ],
    },
});

// ───────────── Estado local (se actualiza con cada respuesta) ─────────────
const lista = ref<EntradaCatalogoRecepcion[]>([...props.entradas]);
const previas = ref<Record<number, VistaPreviaRecepcion>>({ ...props.previas });
const guardando = ref(new Set<number>());
const ordenando = ref(false);

watch(
    () => props.entradas,
    (nuevas) => (lista.value = [...nuevas]),
);
watch(
    () => props.previas,
    (nuevas) => (previas.value = { ...nuevas }),
);

function aplicar(respuesta: RespuestaCatalogoRecepcion) {
    lista.value = respuesta.entradas;
    previas.value = respuesta.previas;
    avisarRecalculo(respuesta.recalculo);
}

function avisarRecalculo(r: RecalculoCatalogoRecepcion | null) {
    if (r === null) {
        toast.success('Cambios guardados.');

        return;
    }

    if (r.cerrado) {
        toast.success(
            `Cambios guardados. El corte de ${r.periodo} está cerrado: no se modificó.`,
        );

        return;
    }

    toast.success(
        r.recalculado
            ? `Corte de ${r.periodo} recalculado: ${r.nuevos} nuevo(s), ${r.no_aplica} pasaron a «No aplica», ${r.reactivados} regresaron a pendiente.`
            : 'Cambios guardados. El corte del mes se actualizará en la próxima materialización.',
    );
}

// ───────────── KPI y gráfica ─────────────
const activas = computed(() => lista.value.filter((e) => e.activa));
const totalDocumentos = computed(() =>
    activas.value.reduce(
        (suma, e) => suma + (previas.value[e.id]?.documentos ?? 0),
        0,
    ),
);
const totalEventos = computed(() =>
    activas.value.reduce(
        (suma, e) => suma + (previas.value[e.id]?.eventos ?? 0),
        0,
    ),
);
const ultimoCambio = computed(() => {
    const fechas = lista.value
        .map((e) => e.actualizado_en)
        .filter((f): f is string => f !== null)
        .sort();

    return fechas.length ? fechas[fechas.length - 1] : null;
});
const datosGrafica = computed(() =>
    activas.value.map((e) => ({
        nombre: e.nombre,
        documentos: previas.value[e.id]?.documentos ?? 0,
    })),
);

// ───────────── Encender / apagar (optimista) ─────────────
async function alternar(entrada: EntradaCatalogoRecepcion, activa: boolean) {
    if (activa && !entrada.documentos.some((d) => d.elegido)) {
        toast.error('Elige al menos un documento antes de encenderla.');
        abrirEditor(entrada);

        return;
    }

    const anterior = entrada.activa;
    entrada.activa = activa;
    guardando.value.add(entrada.id);

    try {
        aplicar(
            await enviarJson<RespuestaCatalogoRecepcion>(
                'PATCH',
                rutaActualizar.url(entrada.id),
                { activa },
            ),
        );
    } catch (e) {
        entrada.activa = anterior;
        toast.error(
            e instanceof ErrorSolicitud
                ? e.message
                : 'No se pudo guardar el cambio.',
        );
    } finally {
        guardando.value.delete(entrada.id);
    }
}

// ───────────── Ordenar: arrastrar o flechas ─────────────
const arrastrando = ref<number | null>(null);

function alIniciarArrastre(id: number, evento: DragEvent) {
    arrastrando.value = id;
    evento.dataTransfer?.setData('text/plain', String(id));

    if (evento.dataTransfer) {
        evento.dataTransfer.effectAllowed = 'move';
    }
}

function alPasarSobre(id: number) {
    const origen = arrastrando.value;

    if (origen === null || origen === id) {
        return;
    }

    const desde = lista.value.findIndex((e) => e.id === origen);
    const hasta = lista.value.findIndex((e) => e.id === id);

    if (desde < 0 || hasta < 0) {
        return;
    }

    const copia = [...lista.value];
    const [movida] = copia.splice(desde, 1);
    copia.splice(hasta, 0, movida);
    lista.value = copia;
}

function alSoltar() {
    if (arrastrando.value !== null) {
        arrastrando.value = null;
        void guardarOrden();
    }
}

function mover(indice: number, delta: -1 | 1) {
    const destino = indice + delta;

    if (destino < 0 || destino >= lista.value.length) {
        return;
    }

    const copia = [...lista.value];
    [copia[indice], copia[destino]] = [copia[destino], copia[indice]];
    lista.value = copia;
    void guardarOrden();
}

async function guardarOrden() {
    ordenando.value = true;

    try {
        const respuesta = await enviarJson<RespuestaCatalogoRecepcion>(
            'POST',
            rutaReordenar.url(),
            { ids: lista.value.map((e) => e.id) },
        );
        lista.value = respuesta.entradas;
        toast.success('Orden guardado: así se verán los bloques del corte.');
    } catch (e) {
        lista.value = [...props.entradas];
        toast.error(
            e instanceof ErrorSolicitud
                ? e.message
                : 'No se pudo guardar el orden.',
        );
    } finally {
        ordenando.value = false;
    }
}

// ───────────── Editor (Sheet) con vista previa en vivo ─────────────
const editor = reactive({
    abierto: false,
    entrada: null as EntradaCatalogoRecepcion | null,
    nombre: '',
    descripcion: '',
    documentos: [] as string[],
    sucursal_ids: [] as number[],
    empresa_ids: [] as number[],
    guardando: false,
    errores: {} as Record<string, string>,
});
const previa = ref<VistaPreviaRecepcion | null>(null);
const calculandoPrevia = ref(false);

function abrirEditor(entrada: EntradaCatalogoRecepcion) {
    Object.assign(editor, {
        abierto: true,
        entrada,
        nombre: entrada.nombre,
        descripcion: entrada.descripcion ?? '',
        documentos: entrada.documentos
            .filter((d) => d.elegido)
            .map((d) => d.clave),
        sucursal_ids: [...entrada.sucursal_ids],
        empresa_ids: [...entrada.empresa_ids],
        guardando: false,
        errores: {},
    });
    previa.value = previas.value[entrada.id] ?? null;
}

function alternarDocumento(clave: string, elegido: boolean) {
    editor.documentos = elegido
        ? [...new Set([...editor.documentos, clave])]
        : editor.documentos.filter((c) => c !== clave);
}

const calcularPrevia = useDebounceFn(async () => {
    const entrada = editor.entrada;

    if (!entrada || !props.puedeConfigurar) {
        return;
    }

    calculandoPrevia.value = true;

    try {
        const respuesta = await enviarJson<{ data: VistaPreviaRecepcion }>(
            'POST',
            rutaVistaPrevia.url(entrada.id),
            {
                activa: true,
                documentos: editor.documentos,
                sucursal_ids: editor.sucursal_ids,
                empresa_ids: editor.empresa_ids,
            },
        );
        previa.value = respuesta.data;
    } catch {
        previa.value = null;
    } finally {
        calculandoPrevia.value = false;
    }
}, 350);

watch(
    () => [
        editor.abierto,
        editor.documentos.join('|'),
        editor.sucursal_ids.join('|'),
        editor.empresa_ids.join('|'),
    ],
    () => {
        if (editor.abierto) {
            void calcularPrevia();
        }
    },
);

const sucursalesFiltradas = computed(() =>
    editor.empresa_ids.length
        ? props.sucursales.filter(
              (s) =>
                  s.empresa_id !== null &&
                  editor.empresa_ids.includes(s.empresa_id),
          )
        : props.sucursales,
);

async function guardarEditor() {
    const entrada = editor.entrada;

    if (!entrada) {
        return;
    }

    editor.guardando = true;
    editor.errores = {};

    try {
        aplicar(
            await enviarJson<RespuestaCatalogoRecepcion>(
                'PATCH',
                rutaActualizar.url(entrada.id),
                {
                    nombre: editor.nombre,
                    descripcion: editor.descripcion,
                    documentos: editor.documentos,
                    sucursal_ids: editor.sucursal_ids,
                    empresa_ids: editor.empresa_ids,
                },
            ),
        );
        editor.abierto = false;
    } catch (e) {
        if (e instanceof ErrorSolicitud) {
            editor.errores = e.errores;
            toast.error(e.message);
        } else {
            toast.error('No se pudo guardar.');
        }
    } finally {
        editor.guardando = false;
    }
}

function etiquetasElegidas(entrada: EntradaCatalogoRecepcion): string[] {
    return entrada.documentos.filter((d) => d.elegido).map((d) => d.etiqueta);
}

function alcance(entrada: EntradaCatalogoRecepcion): string {
    const partes: string[] = [];

    if (entrada.empresa_ids.length) {
        partes.push(
            `${entrada.empresa_ids.length} empresa${entrada.empresa_ids.length === 1 ? '' : 's'}`,
        );
    }

    if (entrada.sucursal_ids.length) {
        partes.push(
            `${entrada.sucursal_ids.length} sucursal${entrada.sucursal_ids.length === 1 ? '' : 'es'}`,
        );
    }

    return partes.length
        ? `Solo ${partes.join(' y ')}`
        : 'Todas las sucursales';
}
</script>

<template>
    <Head title="Lo que recibo · Recepción documental" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Lo que recibo cada mes"
            :descripcion="`Elige qué originales se esperan en Recepción documental. Con esto, cada mes el sistema calcula solo lo que debe llegar. Vista previa con los eventos reales de ${periodoActual.etiqueta}.`"
            :icono="Settings2"
            detalle
        >
            <Button variant="outline" as-child>
                <Link
                    data-tour="recepcion-catalogo-volver"
                    :href="inicioRecepcion.url()"
                    ><ArrowLeft class="size-4" /> Volver a los cortes</Link
                >
            </Button>
        </CrudPageHeader>

        <div
            data-tour="recepcion-catalogo-indicadores"
            class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <KpiRecepcion
                etiqueta="Entradas encendidas"
                :valor="activas.length"
                :icono="ToggleRight"
                tono="success"
                :subtitulo="`de ${lista.length} reglas definidas`"
                :avance="
                    lista.length ? (activas.length * 100) / lista.length : 0
                "
            />
            <KpiRecepcion
                :etiqueta="`Originales esperados en ${periodoActual.etiqueta}`"
                :valor="totalDocumentos"
                :icono="FileStack"
                tono="info"
                subtitulo="Con la configuración guardada"
            />
            <KpiRecepcion
                etiqueta="Eventos detectados"
                :valor="totalEventos"
                :icono="CalendarCheck"
                tono="warning"
                subtitulo="Altas, bajas, recibos, vacaciones…"
            />
            <KpiRecepcion
                etiqueta="Documentos por evento"
                :valor="
                    totalEventos
                        ? Math.round((totalDocumentos / totalEventos) * 10) / 10
                        : 0
                "
                :decimales="1"
                :icono="Layers"
                tono="neutro"
                :subtitulo="
                    ultimoCambio
                        ? `Último cambio: ${formatearFechaHora(ultimoCambio)}`
                        : 'Sin cambios todavía'
                "
            />
        </div>

        <DashboardChartCard
            :title="`Cuánto se espera en ${periodoActual.etiqueta}`"
            description="Originales por entrada encendida, calculados con los eventos reales del mes."
            type="bar"
            :data="datosGrafica"
            x-key="nombre"
            y-key="documentos"
            :height="200"
            empty-title="Nada encendido"
            empty-description="Enciende al menos una entrada para ver lo que se esperaría."
        />

        <section
            data-tour="recepcion-catalogo-entradas"
            class="flex flex-col gap-2"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Entradas del catálogo</h2>
                <p
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Loader2 v-if="ordenando" class="size-3.5 animate-spin" />
                    {{
                        puedeConfigurar
                            ? 'Arrastra para cambiar el orden de los bloques del corte. Al encender o apagar, el corte del mes en curso se recalcula; los meses cerrados no cambian.'
                            : 'Solo lectura: para cambiar el catálogo se necesita el permiso «Configurar el catálogo de lo que se recibe».'
                    }}
                </p>
            </div>

            <ListaAnimada
                v-if="lista.length"
                tag="ul"
                :cantidad="lista.length"
                class="flex flex-col gap-2.5"
            >
                <li
                    v-for="(entrada, i) in lista"
                    :key="entrada.id"
                    :draggable="puedeConfigurar"
                    class="group flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm transicion-ui sm:flex-row sm:items-center"
                    :class="[
                        entrada.activa
                            ? 'border-border/60'
                            : 'border-dashed border-border bg-crema/30',
                        arrastrando === entrada.id
                            ? 'scale-[0.99] opacity-60 ring-2 ring-oro/50'
                            : 'hover:border-oro/40 hover:shadow-md',
                    ]"
                    @dragstart="alIniciarArrastre(entrada.id, $event)"
                    @dragover.prevent="alPasarSobre(entrada.id)"
                    @drop.prevent="alSoltar"
                    @dragend="alSoltar"
                >
                    <div class="flex min-w-0 flex-1 items-start gap-3">
                        <span
                            v-if="puedeConfigurar"
                            class="mt-1 hidden cursor-grab text-muted-foreground active:cursor-grabbing sm:block"
                            aria-hidden="true"
                        >
                            <GripVertical class="size-4" />
                        </span>
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                            :class="
                                entrada.activa
                                    ? 'bg-salvia text-primary'
                                    : 'bg-muted text-muted-foreground'
                            "
                            aria-hidden="true"
                        >
                            <PackageCheck class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{
                                    entrada.nombre
                                }}</span>
                                <Badge
                                    v-if="entrada.nombre !== entrada.etiqueta"
                                    variant="outline"
                                    >{{ entrada.etiqueta }}</Badge
                                >
                                <Badge
                                    :variant="
                                        entrada.activa ? 'success' : 'secondary'
                                    "
                                    >{{
                                        entrada.activa ? 'Encendida' : 'Apagada'
                                    }}</Badge
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ entrada.descripcion || entrada.evento }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="etiqueta in etiquetasElegidas(
                                        entrada,
                                    )"
                                    :key="etiqueta"
                                    class="rounded-full bg-menta px-2 py-0.5 text-xs"
                                    >{{ etiqueta }}</span
                                >
                                <span
                                    class="rounded-full border border-border/70 px-2 py-0.5 text-xs text-muted-foreground"
                                    >{{ alcance(entrada) }}</span
                                >
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center justify-between gap-4 sm:justify-end"
                    >
                        <div class="text-right">
                            <span
                                class="block text-2xl leading-none font-bold tabular-nums"
                                :class="
                                    entrada.activa
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                <NumeroAnimado
                                    :valor="
                                        entrada.activa
                                            ? (previas[entrada.id]
                                                  ?.documentos ?? 0)
                                            : 0
                                    "
                                />
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{
                                    entrada.activa
                                        ? `esperados · ${previas[entrada.id]?.eventos ?? 0} evento(s)`
                                        : 'no se espera nada'
                                }}
                            </span>
                        </div>
                        <div class="flex items-center gap-1">
                            <template v-if="puedeConfigurar">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    :disabled="i === 0 || ordenando"
                                    :aria-label="`Subir ${entrada.nombre}`"
                                    @click="mover(i, -1)"
                                >
                                    <ArrowUp class="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    :disabled="
                                        i === lista.length - 1 || ordenando
                                    "
                                    :aria-label="`Bajar ${entrada.nombre}`"
                                    @click="mover(i, 1)"
                                >
                                    <ArrowDown class="size-4" />
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="abrirEditor(entrada)"
                                >
                                    <Settings2 class="size-4" /> Configurar
                                </Button>
                            </template>
                            <InterruptorRecepcion
                                :model-value="entrada.activa"
                                :disabled="!puedeConfigurar"
                                :guardando="guardando.has(entrada.id)"
                                :etiqueta="`${entrada.activa ? 'Apagar' : 'Encender'} ${entrada.nombre}`"
                                class="ml-2"
                                @update:model-value="alternar(entrada, $event)"
                            />
                        </div>
                    </div>
                </li>
            </ListaAnimada>
            <EmptyState
                v-else
                :icono="PackageCheck"
                titulo="El catálogo está vacío"
                descripcion="Las entradas se crean solas con los valores por defecto la primera vez que se abre esta pantalla."
            />
        </section>
    </div>

    <Sheet v-model:open="editor.abierto">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-xl"
        >
            <SheetHeader>
                <SheetTitle
                    >Configurar «{{ editor.entrada?.etiqueta }}»</SheetTitle
                >
                <SheetDescription>{{
                    editor.entrada?.evento
                }}</SheetDescription>
            </SheetHeader>

            <div class="flex flex-col gap-5 px-4 pb-4">
                <div class="flex flex-col gap-1.5">
                    <Label for="catalogo-nombre">Nombre visible</Label>
                    <Input
                        id="catalogo-nombre"
                        v-model="editor.nombre"
                        maxlength="160"
                        placeholder="p. ej. Contratos de alta"
                    />
                    <p
                        v-if="editor.errores.nombre"
                        class="text-xs text-destructive"
                    >
                        {{ editor.errores.nombre }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="catalogo-descripcion"
                        >Nota para el equipo (opcional)</Label
                    >
                    <Textarea
                        id="catalogo-descripcion"
                        v-model="editor.descripcion"
                        maxlength="500"
                        rows="2"
                        placeholder="Qué llega y de dónde, para quien recibe la paquetería."
                    />
                </div>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Documentos que genera cada evento
                    </legend>
                    <label
                        v-for="doc in editor.entrada?.documentos ?? []"
                        :key="doc.clave"
                        class="flex fila-interactiva cursor-pointer items-center gap-3 rounded-xl border border-border/60 px-3 py-2.5 text-sm"
                        :class="{
                            'border-primary/40 bg-menta/50':
                                editor.documentos.includes(doc.clave),
                        }"
                    >
                        <Checkbox
                            :model-value="editor.documentos.includes(doc.clave)"
                            @update:model-value="
                                alternarDocumento(doc.clave, $event === true)
                            "
                        />
                        <span class="flex-1">{{ doc.etiqueta }}</span>
                        <span
                            v-if="previa"
                            class="text-xs text-muted-foreground tabular-nums"
                            >{{
                                previa.por_documento.find(
                                    (p) => p.clave === doc.clave,
                                )?.cantidad ?? 0
                            }}
                            este mes</span
                        >
                    </label>
                    <p
                        v-if="editor.errores.documentos"
                        class="text-xs text-destructive"
                    >
                        {{ editor.errores.documentos }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        En altas, cada persona espera solo los contratos que su
                        puesto firma (paquete de alta); aquí eliges cuáles de
                        esos se reciben en paquetería.
                    </p>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label>Empresas</Label>
                        <SeleccionMultipleBuscable
                            v-model="editor.empresa_ids"
                            :opciones="empresas"
                            placeholder="Agregar empresa…"
                            texto-todos="Todas las empresas"
                            nombre-campo="Agregar empresa"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>Sucursales</Label>
                        <SeleccionMultipleBuscable
                            v-model="editor.sucursal_ids"
                            :opciones="sucursalesFiltradas"
                            placeholder="Agregar sucursal…"
                            texto-todos="Todas las sucursales"
                            nombre-campo="Agregar sucursal"
                        />
                    </div>
                </div>

                <section
                    class="rounded-2xl border border-border/60 bg-crema/40 p-4"
                    aria-live="polite"
                >
                    <h3 class="text-sm font-medium">
                        Vista previa · {{ periodoActual.etiqueta }}
                    </h3>
                    <CambioSuave>
                        <div
                            v-if="calculandoPrevia && !previa"
                            key="cargando"
                            class="mt-3 flex flex-col gap-2"
                        >
                            <Skeleton class="h-8 w-24 rounded-lg" />
                            <Skeleton class="h-4 w-full rounded" />
                        </div>
                        <div v-else-if="previa" key="previa" class="mt-2">
                            <p class="flex items-baseline gap-2">
                                <span
                                    class="text-3xl font-bold tabular-nums"
                                    :class="{ 'opacity-60': calculandoPrevia }"
                                    ><NumeroAnimado :valor="previa.documentos"
                                /></span>
                                <span class="text-sm text-muted-foreground"
                                    >original(es) de
                                    {{ previa.eventos }} evento(s)</span
                                >
                            </p>
                            <ul
                                v-if="previa.por_documento.length"
                                class="mt-2 space-y-1 text-xs"
                            >
                                <li
                                    v-for="p in previa.por_documento"
                                    :key="p.clave"
                                    class="flex justify-between gap-2"
                                >
                                    <span class="truncate">{{
                                        p.etiqueta
                                    }}</span>
                                    <span class="tabular-nums">{{
                                        p.cantidad
                                    }}</span>
                                </li>
                            </ul>
                            <p
                                v-else
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                Con esta configuración no se espera nada este
                                mes.
                            </p>
                        </div>
                        <p
                            v-else
                            key="sin"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Elige documentos para ver cuánto se esperaría.
                        </p>
                    </CambioSuave>
                </section>
            </div>

            <SheetFooter class="mt-auto flex-row justify-end gap-2 border-t">
                <Button variant="ghost" @click="editor.abierto = false"
                    >Cancelar</Button
                >
                <Button :disabled="editor.guardando" @click="guardarEditor">
                    <Loader2
                        v-if="editor.guardando"
                        class="size-4 animate-spin"
                    />
                    Guardar y recalcular el mes
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
