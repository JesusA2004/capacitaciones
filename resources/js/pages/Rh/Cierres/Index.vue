<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    ClipboardCheck,
    DoorOpen,
    FileWarning,
    FolderOpen,
    Hourglass,
    ListTree,
    Lock,
    Search,
    UserMinus,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import PestanasBajasNav from '@/components/bajas/PestanasBajas.vue';
import TarjetaDocumentosBaja from '@/components/bajas/TarjetaDocumentosBaja.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import CausalesRotacionPanel from '@/components/Rh/CausalesRotacionPanel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { usePermisos } from '@/composables/usePermisos';
import { formatearFecha } from '@/lib/fechas';
import { cn, formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import rutasBajas from '@/routes/rh/bajas';
import documentosBaja from '@/routes/rh/bajas/documentos';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';
import type {
    ArchivoPrevisualizable,
    CausalRotacionAdmin,
    DocumentoRecopilacion,
    PestanasBajas,
    ResumenRecopilacion,
    TarjetaRecopilacion,
} from '@/types';

/**
 * Bajas y finiquitos (grupo «7 · Cierres laborales»):
 *  - «Documentos» (por defecto): recopilación por persona dada de baja —
 *    tarjetas con su forma · causal, estatus, avance N/M y todos sus
 *    documentos con vista previa en galería, descarga y «adjuntar el que
 *    falta» sin ir a Expedientes (BajaRecopilacionService).
 *  - «Cierres y finiquitos»: estado del cierre laboral y del finiquito; la
 *    acción se ejecuta en el ciclo laboral de la persona.
 *  - «Causales»: catálogo (solo RH).
 * Solo se calcula la pestaña abierta; el alcance lo aplica el backend.
 */
type FilaCierre = {
    id: number;
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
        /** Foto oficial del expediente (URL protegida); null → iniciales. */
        foto_url?: string | null;
    };
    tipo_baja: string;
    tipo_baja_etiqueta: string;
    causal_rotacion: string | null;
    fecha_efectiva: string;
    estado: string;
    estado_etiqueta: string;
    solicitado_por: string | null;
    finiquito: { estado: string; estado_etiqueta: string; neto: string } | null;
    siguiente_accion: string;
};

type Paginador<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

type Seccion = 'documentos' | 'cierres' | 'causales';
type Opcion = { value: string; label: string };

const props = defineProps<{
    pestanas: PestanasBajas;
    cierres: Paginador<FilaCierre> | null;
    recopilacion: Paginador<TarjetaRecopilacion> | null;
    resumen: ResumenRecopilacion;
    filtros: { estado: string | null; busqueda: string };
    filtros_documentos: {
        estatus: string;
        sucursal_id: number | null;
        forma: string | null;
        documentos: string | null;
        busqueda: string;
    };
    opciones_documentos: {
        estatus: Opcion[];
        formas: (Opcion & { token: string })[];
        sucursales: { value: number; label: string }[];
    };
    limites_documentos: { max_kb: number; mimes: string[] };
    estados: Opcion[];
    puede_ver_finiquito: boolean;
    /** null = sin permiso para administrar las causales de rotación. */
    causales: CausalRotacionAdmin[] | null;
    tipos_baja: Opcion[];
    seccion: Seccion;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas', href: rutasBajas.index() },
            { title: 'Documentos por persona', href: '' },
        ],
    },
});

const { tienePermiso } = usePermisos();
const puedeRegistrar =
    tienePermiso('bajas.solicitar') || tienePermiso('bajas.gestionar');

// ───── Pestañas (cada una pide sus datos al backend) ─────
function cambiarSeccion(valor: Seccion) {
    if (valor === props.seccion) {
        return;
    }

    router.get(
        window.location.pathname,
        valor === 'documentos' ? {} : { seccion: valor },
        { preserveScroll: true, replace: true },
    );
}

const claseTab = (activo: boolean) =>
    cn(
        '-mb-px inline-flex h-11 shrink-0 items-center gap-1.5 border-b-2 px-3 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50',
        activo
            ? 'border-primary text-foreground'
            : 'border-transparent text-muted-foreground hover:text-foreground',
    );

// ───── Documentos: filtros ─────
const fd = props.filtros_documentos;
const busquedaDoc = ref(fd.busqueda);
const estatusDoc = ref(fd.estatus);
const sucursalDoc = ref<number | null>(fd.sucursal_id);
const formaDoc = ref(fd.forma ?? '');
const documentosDoc = ref(fd.documentos ?? '');

function filtrarDocumentos() {
    router.get(
        window.location.pathname,
        {
            ...parametrosPorPaginaActuales(),
            busqueda: busquedaDoc.value || undefined,
            estatus:
                estatusDoc.value && estatusDoc.value !== 'abiertas'
                    ? estatusDoc.value
                    : undefined,
            sucursal_id: sucursalDoc.value ?? undefined,
            forma: formaDoc.value || undefined,
            documentos: documentosDoc.value || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['recopilacion', 'filtros_documentos'],
        },
    );
}

watch(busquedaDoc, useDebounceFn(filtrarDocumentos, 300));
watch([estatusDoc, sucursalDoc, formaDoc, documentosDoc], filtrarDocumentos);

const opcionesDocumentos: Opcion[] = [
    { value: 'incompletos', label: 'Faltan documentos' },
    { value: 'completos', label: 'Completos' },
];

/** Las tarjetas grandes también filtran (en la pestaña Documentos). */
function filtroRapido(
    clave: 'en_tramite' | 'listas' | 'pendientes' | 'cerradas',
) {
    if (props.seccion !== 'documentos') {
        router.get(window.location.pathname, rapidoAQuery(clave), {
            preserveScroll: true,
        });

        return;
    }

    const q = rapidoAQuery(clave);
    estatusDoc.value = q.estatus ?? 'abiertas';
    documentosDoc.value = q.documentos ?? '';
}

function rapidoAQuery(clave: string): {
    estatus?: string;
    documentos?: string;
} {
    switch (clave) {
        case 'listas':
            return { estatus: 'abiertas', documentos: 'completos' };
        case 'pendientes':
            return { documentos: 'incompletos' };
        case 'cerradas':
            return { estatus: 'cerrada' };
        default:
            return { estatus: 'en_revision_rh' };
    }
}

const rapidoActivo = computed(() => {
    if (props.seccion !== 'documentos') {
        return null;
    }

    if (estatusDoc.value === 'abiertas' && documentosDoc.value === 'incompletos') {
        return 'pendientes';
    }

    if (estatusDoc.value === 'abiertas' && documentosDoc.value === 'completos') {
        return 'listas';
    }

    return (
        (
            {
                en_revision_rh: 'en_tramite',
                cerrada: 'cerradas',
            } as Record<string, string>
        )[estatusDoc.value] ?? null
    );
});

// ───── Documentos: visor (galería por persona) ─────
const visorAbierto = ref(false);
const visorArchivos = ref<ArchivoPrevisualizable[]>([]);
const visorIndice = ref(0);

function verDocumentos(tarjeta: TarjetaRecopilacion, clave: string | null) {
    const archivos = tarjeta.documentos
        .filter((d) => d.url !== null)
        .map((d): ArchivoPrevisualizable => ({
            clave: d.clave,
            nombre: d.mime === 'application/pdf' ? `${d.nombre}.pdf` : d.nombre,
            previewUrl: d.url as string,
            downloadUrl: d.url_descarga ?? d.url,
            mime: d.mime,
            etiqueta: `${tarjeta.colaborador} · ${d.estado_etiqueta}`,
            fecha: d.fecha,
        }));

    if (!archivos.length) {
        return;
    }

    visorArchivos.value = archivos;
    visorIndice.value = Math.max(
        0,
        clave === null ? 0 : archivos.findIndex((a) => a.clave === clave),
    );
    visorAbierto.value = true;
}

// ───── Documentos: adjuntar el que falta ─────
const entrada = ref<HTMLInputElement | null>(null);
const pendiente = ref<{
    tarjeta: TarjetaRecopilacion;
    documento: DocumentoRecopilacion;
} | null>(null);
const subiendo = ref<string | null>(null);
// Tarjeta (baja) que está subiendo: el spinner solo se muestra en ella.
const subiendoTarjeta = ref<number | null>(null);
const errorCarga = ref<string | null>(null);
const accept = computed(() =>
    props.limites_documentos.mimes.map((m) => `.${m}`).join(','),
);

function pedirArchivo(
    tarjeta: TarjetaRecopilacion,
    documento: DocumentoRecopilacion,
) {
    pendiente.value = { tarjeta, documento };
    errorCarga.value = null;
    entrada.value?.click();
}

function alElegirArchivo(evento: Event) {
    const input = evento.target as HTMLInputElement;
    const archivo = input.files?.[0];
    input.value = '';
    const destino = pendiente.value;

    if (!archivo || !destino || destino.documento.requerido_id === null) {
        return;
    }

    if (archivo.size > props.limites_documentos.max_kb * 1024) {
        errorCarga.value = `El archivo pesa más de ${Math.round(props.limites_documentos.max_kb / 1024)} MB.`;

        return;
    }

    subiendo.value = destino.documento.clave;
    subiendoTarjeta.value = destino.tarjeta.id;
    router.post(
        documentosBaja.store.url(destino.tarjeta.id),
        { archivo, documento_requerido_id: destino.documento.requerido_id },
        {
            forceFormData: true,
            preserveScroll: true,
            only: ['recopilacion', 'resumen', 'flash'],
            onError: (e) =>
                (errorCarga.value =
                    e.archivo ??
                    e.documento_requerido_id ??
                    'No se pudo subir el archivo.'),
            onFinish: () => {
                subiendo.value = null;
                subiendoTarjeta.value = null;
                pendiente.value = null;
            },
        },
    );
}

// ───── Cierres y finiquitos ─────
const termino = ref(props.filtros.busqueda);
const estado = ref(props.filtros.estado ?? '');

function filtrarCierres() {
    router.get(
        window.location.pathname,
        {
            ...parametrosPorPaginaActuales(),
            seccion: 'cierres',
            busqueda: termino.value || undefined,
            estado: estado.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(termino, useDebounceFn(filtrarCierres, 300));
watch(estado, filtrarCierres);

function neto(fila: FilaCierre): string {
    return fila.finiquito ? formatoMoneda(Number(fila.finiquito.neto)) : '—';
}
</script>

<template>
    <Head title="Documentos por persona" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Documentos por persona"
            descripcion="Documentos, finiquito y cierre de cada persona dada de baja."
            :icono="DoorOpen"
        >
            <Button v-if="puedeRegistrar" as-child>
                <Link :href="rutasBajas.create()"
                    ><UserMinus class="size-4" />Registrar baja</Link
                >
            </Button>
        </CrudPageHeader>

        <PestanasBajasNav :pestanas="pestanas" />

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <TarjetaMetrica
                etiqueta="En trámite"
                :valor="resumen.en_tramite"
                :icono="Hourglass"
                tono="info"
                clicable
                :activa="rapidoActivo === 'en_tramite'"
                @click="filtroRapido('en_tramite')"
            />
            <TarjetaMetrica
                etiqueta="Faltan documentos"
                :valor="resumen.documentos_pendientes"
                :icono="FileWarning"
                tono="alerta"
                clicable
                :activa="rapidoActivo === 'pendientes'"
                @click="filtroRapido('pendientes')"
            />
            <TarjetaMetrica
                etiqueta="Listas para cerrar"
                :valor="resumen.lista_para_cerrar"
                :icono="ClipboardCheck"
                tono="info"
                clicable
                :activa="rapidoActivo === 'listas'"
                @click="filtroRapido('listas')"
            />
            <TarjetaMetrica
                etiqueta="Cerradas"
                :valor="resumen.cerradas"
                :icono="Lock"
                tono="exito"
                clicable
                :activa="rapidoActivo === 'cerradas'"
                @click="filtroRapido('cerradas')"
            />
        </div>

        <nav
            class="scroll-x-limpio flex max-w-full border-b"
            aria-label="Secciones de bajas y finiquitos"
        >
            <button
                type="button"
                :class="claseTab(seccion === 'documentos')"
                :aria-current="seccion === 'documentos' ? 'page' : undefined"
                @click="cambiarSeccion('documentos')"
            >
                <FolderOpen class="size-4" />Documentos
            </button>
            <button
                type="button"
                :class="claseTab(seccion === 'cierres')"
                :aria-current="seccion === 'cierres' ? 'page' : undefined"
                @click="cambiarSeccion('cierres')"
            >
                <DoorOpen class="size-4" />Cierres y finiquitos
            </button>
            <button
                v-if="causales"
                type="button"
                :class="claseTab(seccion === 'causales')"
                :aria-current="seccion === 'causales' ? 'page' : undefined"
                @click="cambiarSeccion('causales')"
            >
                <ListTree class="size-4" />Causales
            </button>
        </nav>

        <!-- ───── Documentos (recopilación por persona) ───── -->
        <template v-if="seccion === 'documentos' && recopilacion">
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                <div class="sm:col-span-2 xl:col-span-1">
                    <Label
                        for="buscar-baja-doc"
                        class="mb-1.5 text-xs text-muted-foreground"
                        >Buscar</Label
                    >
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            id="buscar-baja-doc"
                            v-model="busquedaDoc"
                            class="pl-9"
                            placeholder="Nombre, número o folio"
                        />
                    </div>
                </div>
                <div>
                    <Label class="mb-1.5 text-xs text-muted-foreground"
                        >Estatus</Label
                    >
                    <SelectSimple
                        v-model="estatusDoc"
                        :opciones="opciones_documentos.estatus"
                    />
                </div>
                <div>
                    <Label class="mb-1.5 text-xs text-muted-foreground"
                        >Sucursal</Label
                    >
                    <SelectSimple
                        v-model="sucursalDoc"
                        :opciones="opciones_documentos.sucursales"
                        numerico
                        opcion-vacia="Todas"
                        placeholder="Todas"
                    />
                </div>
                <div>
                    <Label class="mb-1.5 text-xs text-muted-foreground"
                        >Forma de salida</Label
                    >
                    <SelectSimple
                        v-model="formaDoc"
                        :opciones="opciones_documentos.formas"
                        opcion-vacia="Todas"
                        placeholder="Todas"
                    />
                </div>
                <div>
                    <Label class="mb-1.5 text-xs text-muted-foreground"
                        >Documentos</Label
                    >
                    <SelectSimple
                        v-model="documentosDoc"
                        :opciones="opcionesDocumentos"
                        opcion-vacia="Todos"
                        placeholder="Todos"
                    />
                </div>
            </div>

            <p
                v-if="errorCarga"
                class="rounded-2xl bg-danger-soft px-4 py-3 text-sm text-destructive"
                role="alert"
            >
                {{ errorCarga }}
            </p>

            <p
                v-if="!recopilacion.data.length"
                class="rounded-2xl border border-border/60 bg-card px-4 py-12 text-center text-sm text-muted-foreground"
            >
                No hay bajas con estos filtros.
            </p>

            <div
                v-else
                class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,22rem),1fr))] gap-4"
            >
                <TarjetaDocumentosBaja
                    v-for="t in recopilacion.data"
                    :key="t.id"
                    :tarjeta="t"
                    :subiendo="subiendoTarjeta === t.id ? subiendo : null"
                    :ocupado="subiendo !== null"
                    @ver="verDocumentos"
                    @adjuntar="pedirArchivo"
                />
            </div>

            <PaginacionTabla :datos="recopilacion" clave="cierres-documentos" />

            <input
                ref="entrada"
                type="file"
                class="hidden"
                :accept="accept"
                aria-hidden="true"
                tabindex="-1"
                @change="alElegirArchivo"
            />
        </template>

        <CausalesRotacionPanel
            v-else-if="seccion === 'causales' && causales"
            :causales="causales"
            :tipos="tipos_baja"
        />

        <!-- ───── Cierres y finiquitos ───── -->
        <template v-else-if="seccion === 'cierres' && cierres">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <Label class="mb-1.5 text-xs text-muted-foreground"
                        >Buscar colaborador</Label
                    >
                    <Input
                        v-model="termino"
                        placeholder="Nombre o número de empleado"
                    />
                </div>
                <div class="w-full sm:w-64">
                    <Label class="mb-1.5 text-xs text-muted-foreground"
                        >Estado del cierre</Label
                    >
                    <SelectSimple
                        v-model="estado"
                        :opciones="[
                            { value: 'abiertos', label: 'Solo abiertos' },
                            ...estados.map((e) => ({
                                value: e.value,
                                label: e.label,
                            })),
                        ]"
                        opcion-vacia="Todos"
                        placeholder="Todos"
                    />
                </div>
            </div>

            <p
                v-if="!cierres.data.length"
                class="rounded-2xl border border-border/60 bg-card px-4 py-12 text-center text-sm text-muted-foreground"
            >
                No hay cierres laborales en tu alcance con estos filtros.
            </p>

            <template v-else>
                <div
                    class="hidden tabla-scroll rounded-2xl border border-border/60 bg-card shadow-sm xl:block"
                >
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b border-border/60 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="min-w-48 px-4 py-3 font-medium">
                                    Colaborador
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Puesto / Sucursal
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Tipo de baja
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Fecha efectiva
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Estado del cierre
                                </th>
                                <th
                                    v-if="puede_ver_finiquito"
                                    class="px-4 py-3 font-medium"
                                >
                                    Finiquito
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Siguiente paso
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="c in cierres.data"
                                :key="c.id"
                                class="border-b border-border/60 last:border-0"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <ColaboradorAvatar
                                            :nombre="c.colaborador.nombre"
                                            :foto-url="c.colaborador.foto_url"
                                        />
                                        <div class="min-w-0">
                                            <p class="font-medium">
                                                {{ c.colaborador.nombre }}
                                            </p>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    c.colaborador
                                                        .numero_empleado ?? '—'
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    {{ c.colaborador.puesto ?? '—' }}<br />
                                    <span class="text-muted-foreground">{{
                                        c.colaborador.sucursal ?? '—'
                                    }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    {{ c.tipo_baja_etiqueta
                                    }}<span
                                        v-if="c.causal_rotacion"
                                        class="block text-muted-foreground"
                                        >{{ c.causal_rotacion }}</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    {{ formatearFecha(c.fecha_efectiva) }}
                                </td>
                                <td class="px-4 py-3">
                                    <ChipEstado
                                        :etiqueta="c.estado_etiqueta"
                                        tono="info"
                                    />
                                </td>
                                <td
                                    v-if="puede_ver_finiquito"
                                    class="px-4 py-3 text-xs"
                                >
                                    <template v-if="c.finiquito">
                                        {{ c.finiquito.estado_etiqueta }}<br />
                                        <span
                                            class="font-semibold tabular-nums"
                                            >{{ neto(c) }}</span
                                        >
                                    </template>
                                    <span v-else class="text-muted-foreground"
                                        >Sin calcular</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        as-child
                                    >
                                        <Link
                                            :href="
                                                cicloColaborador.url(
                                                    c.colaborador.id,
                                                )
                                            "
                                        >
                                            {{ c.siguiente_accion }}
                                            <ArrowRight class="size-3.5" />
                                        </Link>
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="grid gap-3 md:grid-cols-2 xl:hidden">
                    <li
                        v-for="c in cierres.data"
                        :key="c.id"
                        class="flex flex-col rounded-2xl border border-border/60 bg-card p-4 text-sm shadow-sm"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-2.5">
                                <ColaboradorAvatar
                                    :nombre="c.colaborador.nombre"
                                    :foto-url="c.colaborador.foto_url"
                                />
                                <span class="font-semibold">{{
                                    c.colaborador.nombre
                                }}</span>
                            </span>
                            <ChipEstado
                                :etiqueta="c.estado_etiqueta"
                                tono="info"
                            />
                        </div>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ c.colaborador.puesto ?? '—' }} ·
                            {{ c.colaborador.sucursal ?? '—' }}
                        </p>
                        <p class="mt-1 text-xs">
                            {{ c.tipo_baja_etiqueta
                            }}<template v-if="c.causal_rotacion">
                                ({{ c.causal_rotacion }})</template
                            >
                            · {{ formatearFecha(c.fecha_efectiva) }}
                        </p>
                        <p v-if="puede_ver_finiquito" class="mt-1 mb-3 text-xs">
                            Finiquito:
                            <template v-if="c.finiquito">
                                {{ c.finiquito.estado_etiqueta }} ·
                                <span class="font-semibold tabular-nums">{{
                                    neto(c)
                                }}</span>
                            </template>
                            <span v-else class="text-muted-foreground"
                                >sin calcular</span
                            >
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            class="mt-auto w-full"
                            as-child
                        >
                            <Link
                                :href="cicloColaborador.url(c.colaborador.id)"
                            >
                                {{ c.siguiente_accion }}
                                <ArrowRight class="size-3.5" />
                            </Link>
                        </Button>
                    </li>
                </ul>

                <PaginacionTabla :datos="cierres" clave="cierres-finiquitos" />
            </template>
        </template>
    </div>

    <DocumentPreviewDialog
        :open="visorAbierto"
        :archivos="visorArchivos"
        :indice="visorIndice"
        @update:open="(v: boolean) => (visorAbierto = v)"
        @update:indice="(i: number) => (visorIndice = i)"
    />
</template>
