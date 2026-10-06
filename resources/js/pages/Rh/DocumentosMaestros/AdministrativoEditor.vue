<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Eye,
    FilePlus2,
    History,
    Plus,
    RefreshCw,
    Save,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import SeccionesDocumentosMaestros from '@/components/documentos/maestros/SeccionesDocumentosMaestros.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index as indexMaestros } from '@/routes/rh/documentos-maestros';
import {
    activar,
    borrador as crearBorrador,
    descartar,
    guardar,
    index,
    vistaPrevia,
} from '@/routes/rh/documentos-maestros/administrativos';
import type {
    DisenoAdministrativo,
    RecursoDocumento,
    VersionPlantillaAdministrativa,
} from '@/types';

/**
 * Editor del DISEÑO de un documento administrativo (página, tipografía,
 * párrafo, encabezado, pie, fondo, tablas, firmas, secciones y textos).
 * Se edita un borrador; la versión activa sigue generando documentos
 * hasta que el borrador se activa. La vista previa es el PDF REAL (mismo
 * HTML y motor) con datos ficticios.
 */
const props = defineProps<{
    familia: {
        clave: string;
        nombre: string;
        descripcion: string;
        secciones: Record<string, string>;
        campos: string[];
    };
    vigente: { version: number; motor: string; diseno: DisenoAdministrativo };
    borrador: {
        id: number;
        version: number;
        motor: string | null;
        notas: string | null;
        diseno: DisenoAdministrativo;
    } | null;
    historial: VersionPlantillaAdministrativa[];
    motores: { valor: string; etiqueta: string }[];
    motorPorDefecto: string;
    fuentes: string[];
    recursos: RecursoDocumento[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Documentos maestros', href: indexMaestros.url() },
            { title: 'Documentos administrativos', href: index.url() },
            { title: 'Diseño' },
        ],
    },
});

function copia<T>(valor: T): T {
    return JSON.parse(JSON.stringify(valor)) as T;
}

const diseno = reactive<DisenoAdministrativo>(
    copia(props.borrador?.diseno ?? props.vigente.diseno),
);
const motor = ref<string>(props.borrador?.motor ?? '');
const notas = ref<string>(props.borrador?.notas ?? '');
const guardando = ref(false);
const cambiosSinGuardar = ref(false);
const versionPrevia = ref(Date.now());

watch(
    () => props.borrador,
    (nuevo) => {
        if (nuevo) {
            Object.assign(diseno, copia(nuevo.diseno));
            motor.value = nuevo.motor ?? '';
            notas.value = nuevo.notas ?? '';
            cambiosSinGuardar.value = false;
            versionPrevia.value = Date.now();
        }
    },
);

watch(
    diseno,
    () => {
        cambiosSinGuardar.value = true;
    },
    { deep: true },
);

const editable = computed(() => props.borrador !== null);

const urlPrevia = computed(
    () =>
        `${vistaPrevia.url(props.familia.clave)}?${props.borrador ? `plantilla=${props.borrador.id}&` : ''}t=${versionPrevia.value}`,
);

const fondos = computed(() => props.recursos.filter((r) => r.es_fondo));
const logos = computed(() => props.recursos.filter((r) => r.es_logo));

function iniciarBorrador(): void {
    router.post(
        crearBorrador.url(props.familia.clave),
        {},
        { preserveScroll: true },
    );
}

function guardarBorrador(onExito?: () => void): void {
    if (!props.borrador) {
        return;
    }

    guardando.value = true;
    router.put(
        guardar.url(props.borrador.id),
        { diseno: copia(diseno), motor: motor.value, notas: notas.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                cambiosSinGuardar.value = false;
                versionPrevia.value = Date.now();
                onExito?.();
            },
            onFinish: () => {
                guardando.value = false;
            },
        },
    );
}

function activarVersion(id: number): void {
    router.post(activar.url(id), {}, { preserveScroll: true });
}

function activarBorrador(): void {
    if (!props.borrador) {
        return;
    }

    const id = props.borrador.id;
    guardarBorrador(() => activarVersion(id));
}

function descartarBorrador(): void {
    if (props.borrador) {
        router.delete(descartar.url(props.borrador.id), {
            preserveScroll: true,
        });
    }
}

function agregarFirma(): void {
    if (diseno.signatures.blocks.length < 4) {
        diseno.signatures.blocks.push({ label: 'Firma' });
    }
}

function vistaPreviaVersion(id: number): string {
    return `${vistaPrevia.url(props.familia.clave)}?plantilla=${id}`;
}

const ESTADO_VERSION: Record<string, string> = {
    borrador: 'Borrador',
    activa: 'Activa',
    archivada: 'Archivada',
};

const claseCampo =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-60';
</script>

<template>
    <Head :title="`Diseño: ${familia.nombre}`" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            :titulo="familia.nombre"
            :descripcion="familia.descripcion"
            detalle
        >
            <template v-if="borrador">
                <Badge variant="outline"
                    >Borrador v{{ borrador.version }}</Badge
                >
                <Button
                    variant="outline"
                    :disabled="guardando"
                    @click="guardarBorrador()"
                >
                    <Spinner v-if="guardando" />
                    <Save v-else class="size-4" />
                    Guardar
                </Button>
                <Button :disabled="guardando" @click="activarBorrador">
                    <CheckCircle2 class="size-4" />
                    Guardar y activar
                </Button>
            </template>
            <Button v-else @click="iniciarBorrador">
                <FilePlus2 class="size-4" />
                Editar (crear borrador)
            </Button>
        </CrudPageHeader>

        <SeccionesDocumentosMaestros actual="administrativos" />

        <p
            class="rounded-xl border border-sky-500/30 bg-sky-500/5 p-3 text-sm text-muted-foreground"
        >
            El diseño solo controla cómo se ve el documento. Montos, conceptos,
            fechas y datos laborales salen siempre del proceso real y no se
            pueden cambiar aquí. Activar una versión nueva no modifica los PDF
            ya generados (cada uno guarda la versión con la que se hizo).
        </p>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <!-- Formulario de diseño -->
            <fieldset
                :disabled="!editable"
                class="flex flex-col gap-4"
                :class="{ 'opacity-70': !editable }"
            >
                <p v-if="!editable" class="text-sm text-muted-foreground">
                    Viendo la versión
                    {{
                        vigente.version ? `v${vigente.version}` : 'de fábrica'
                    }}. Crea un borrador para editar.
                </p>

                <details class="group rounded-2xl border bg-card p-4" open>
                    <summary class="cursor-pointer font-semibold">
                        Motor y textos
                    </summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="motor">Motor de PDF</Label>
                            <select
                                id="motor"
                                v-model="motor"
                                :class="claseCampo"
                            >
                                <option value="">
                                    Predeterminado del servidor ({{
                                        motores.find(
                                            (m) => m.valor === motorPorDefecto,
                                        )?.etiqueta
                                    }})
                                </option>
                                <option
                                    v-for="m in motores"
                                    :key="m.valor"
                                    :value="m.valor"
                                >
                                    {{ m.etiqueta }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="notas">Notas de la versión</Label>
                            <Input id="notas" v-model="notas" maxlength="500" />
                        </div>
                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label for="titulo">Título</Label>
                            <Input
                                id="titulo"
                                v-model="diseno.content.titulo"
                                maxlength="150"
                            />
                        </div>
                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label for="subtitulo">Subtítulo</Label>
                            <Input
                                id="subtitulo"
                                v-model="diseno.content.subtitulo"
                                maxlength="250"
                            />
                        </div>
                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label for="leyenda">Leyenda</Label>
                            <Textarea
                                id="leyenda"
                                v-model="diseno.content.leyenda"
                                maxlength="1000"
                            />
                        </div>
                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label for="nota">Nota</Label>
                            <Textarea
                                id="nota"
                                v-model="diseno.content.nota"
                                maxlength="1500"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground sm:col-span-2">
                            Puedes usar estos campos en los textos:
                            <code
                                v-for="campo in familia.campos"
                                :key="campo"
                                class="mr-1 rounded bg-muted px-1 py-0.5"
                                >{{ `\{\{ ${campo} \}\}` }}</code
                            >
                        </p>
                    </div>
                </details>

                <details class="rounded-2xl border bg-card p-4">
                    <summary class="cursor-pointer font-semibold">
                        Página
                    </summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label>Tamaño</Label>
                            <select
                                v-model="diseno.page.size"
                                :class="claseCampo"
                            >
                                <option value="letter">Carta</option>
                                <option value="a4">A4</option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Orientación</Label>
                            <select
                                v-model="diseno.page.orientation"
                                :class="claseCampo"
                            >
                                <option value="portrait">Vertical</option>
                                <option value="landscape">Horizontal</option>
                            </select>
                        </div>
                        <div
                            v-for="(etiqueta, lado) in {
                                top: 'Margen superior (mm)',
                                bottom: 'Margen inferior (mm)',
                                left: 'Margen izquierdo (mm)',
                                right: 'Margen derecho (mm)',
                            }"
                            :key="lado"
                            class="grid gap-1.5"
                        >
                            <Label>{{ etiqueta }}</Label>
                            <Input
                                v-model.number="diseno.page.margins_mm[lado]"
                                type="number"
                                min="0"
                                max="60"
                                step="0.5"
                            />
                        </div>
                    </div>
                </details>

                <details class="rounded-2xl border bg-card p-4">
                    <summary class="cursor-pointer font-semibold">
                        Tipografía y párrafo
                    </summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label>Fuente</Label>
                            <select
                                v-model="diseno.typography.font_family"
                                :class="claseCampo"
                            >
                                <option
                                    v-for="f in fuentes"
                                    :key="f"
                                    :value="f"
                                >
                                    {{ f }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Tamaño base (pt)</Label>
                            <Input
                                v-model.number="diseno.typography.base_size_pt"
                                type="number"
                                min="6"
                                max="16"
                                step="0.5"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Interlineado</Label>
                            <Input
                                v-model.number="diseno.typography.line_height"
                                type="number"
                                min="1"
                                max="2.5"
                                step="0.05"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Color del texto</Label>
                            <input
                                v-model="diseno.typography.color"
                                type="color"
                                class="h-9 w-full cursor-pointer rounded-md border"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Peso</Label>
                            <select
                                v-model.number="diseno.typography.weight"
                                :class="claseCampo"
                            >
                                <option :value="300">Ligero</option>
                                <option :value="400">Normal</option>
                                <option :value="500">Medio</option>
                                <option :value="600">Seminegrita</option>
                                <option :value="700">Negrita</option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Alineación</Label>
                            <select
                                v-model="diseno.paragraph.align"
                                :class="claseCampo"
                            >
                                <option value="left">Izquierda</option>
                                <option value="justify">Justificado</option>
                                <option value="center">Centrado</option>
                                <option value="right">Derecha</option>
                            </select>
                        </div>
                        <div
                            v-for="(etiqueta, campo) in {
                                indent_left_mm: 'Sangría izquierda (mm)',
                                indent_right_mm: 'Sangría derecha (mm)',
                                first_line_mm: 'Primera línea (mm)',
                                space_before_pt: 'Espacio antes (pt)',
                                space_after_pt: 'Espacio después (pt)',
                            }"
                            :key="campo"
                            class="grid gap-1.5"
                        >
                            <Label>{{ etiqueta }}</Label>
                            <Input
                                v-model.number="diseno.paragraph[campo]"
                                type="number"
                                min="0"
                                max="40"
                                step="0.5"
                            />
                        </div>
                    </div>
                </details>

                <details class="rounded-2xl border bg-card p-4">
                    <summary class="cursor-pointer font-semibold">
                        Colores
                    </summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-4">
                        <div
                            v-for="(etiqueta, clave) in {
                                primary: 'Principal',
                                accent: 'Acento',
                                muted: 'Texto suave',
                                table_header_bg: 'Encabezado de tabla',
                                table_header_text: 'Texto encabezado tabla',
                                total_bg: 'Fondo del total',
                                total_text: 'Texto del total',
                            }"
                            :key="clave"
                            class="grid gap-1.5"
                        >
                            <Label>{{ etiqueta }}</Label>
                            <input
                                v-model="diseno.colors[clave]"
                                type="color"
                                class="h-9 w-full cursor-pointer rounded-md border"
                            />
                        </div>
                    </div>
                </details>

                <details class="rounded-2xl border bg-card p-4">
                    <summary class="cursor-pointer font-semibold">
                        Encabezado y pie
                    </summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <label
                            class="flex items-center gap-2 text-sm sm:col-span-3"
                        >
                            <input
                                v-model="diseno.header.show"
                                type="checkbox"
                            />
                            Mostrar encabezado
                        </label>
                        <div class="grid gap-1.5">
                            <Label>Logo</Label>
                            <select
                                v-model="diseno.header.logo_asset_id"
                                :class="claseCampo"
                            >
                                <option :value="null">Sin logo</option>
                                <option
                                    v-for="r in logos"
                                    :key="r.id"
                                    :value="r.id"
                                >
                                    {{ r.nombre }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Altura del logo (mm)</Label>
                            <Input
                                v-model.number="diseno.header.logo_height_mm"
                                type="number"
                                min="4"
                                max="50"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Posición</Label>
                            <select
                                v-model="diseno.header.logo_position"
                                :class="claseCampo"
                            >
                                <option value="left">Izquierda</option>
                                <option value="center">Centro</option>
                                <option value="right">Derecha</option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Altura del encabezado (mm)</Label>
                            <Input
                                v-model.number="diseno.header.height_mm"
                                type="number"
                                min="0"
                                max="80"
                            />
                        </div>
                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label>Texto de marca</Label>
                            <Input
                                v-model="diseno.header.brand_text"
                                maxlength="80"
                            />
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="diseno.footer.show"
                                type="checkbox"
                            />
                            Mostrar pie
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="diseno.footer.page_numbers"
                                type="checkbox"
                            />
                            Numerar páginas
                        </label>
                        <div class="grid gap-1.5">
                            <Label>Distancia al borde (mm)</Label>
                            <Input
                                v-model.number="diseno.footer.distance_mm"
                                type="number"
                                min="2"
                                max="30"
                            />
                        </div>
                        <div class="grid gap-1.5 sm:col-span-3">
                            <Label>Texto del pie</Label>
                            <Input
                                v-model="diseno.footer.text"
                                maxlength="200"
                            />
                        </div>
                    </div>
                </details>

                <details class="rounded-2xl border bg-card p-4">
                    <summary class="cursor-pointer font-semibold">
                        Fondo
                    </summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-1.5 sm:col-span-3">
                            <Label
                                >Imagen de fondo (PNG/JPG de Fondos y
                                recursos)</Label
                            >
                            <select
                                v-model="diseno.background.asset_id"
                                :class="claseCampo"
                            >
                                <option :value="null">Sin fondo</option>
                                <option
                                    v-for="r in fondos"
                                    :key="r.id"
                                    :value="r.id"
                                >
                                    {{ r.nombre }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Aplicar en</Label>
                            <select
                                v-model="diseno.background.apply_to"
                                :class="claseCampo"
                            >
                                <option value="all_pages">
                                    Todas las páginas
                                </option>
                                <option value="first_page">
                                    Solo primera página
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Ajuste</Label>
                            <select
                                v-model="diseno.background.fit"
                                :class="claseCampo"
                            >
                                <option value="stretch">Estirar</option>
                                <option value="contain">Contener</option>
                                <option value="cover">Cubrir</option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Posición</Label>
                            <select
                                v-model="diseno.background.position"
                                :class="claseCampo"
                            >
                                <option value="center">Centro</option>
                                <option value="top">Arriba</option>
                                <option value="bottom">Abajo</option>
                                <option value="left">Izquierda</option>
                                <option value="right">Derecha</option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Opacidad (%)</Label>
                            <Input
                                v-model.number="diseno.background.opacity"
                                type="number"
                                min="0"
                                max="100"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Área segura (mm)</Label>
                            <Input
                                v-model.number="diseno.background.safe_area_mm"
                                type="number"
                                min="0"
                                max="40"
                            />
                        </div>
                    </div>
                </details>

                <details class="rounded-2xl border bg-card p-4">
                    <summary class="cursor-pointer font-semibold">
                        Tablas
                    </summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label>Tamaño de fuente (pt)</Label>
                            <Input
                                v-model.number="diseno.tables.font_size_pt"
                                type="number"
                                min="6"
                                max="14"
                                step="0.5"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Relleno de celda (mm)</Label>
                            <Input
                                v-model.number="diseno.tables.padding_mm"
                                type="number"
                                min="0"
                                max="6"
                                step="0.2"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Grosor del borde (px)</Label>
                            <Input
                                v-model.number="diseno.tables.border_width_px"
                                type="number"
                                min="0"
                                max="4"
                                step="0.5"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Color del borde</Label>
                            <input
                                v-model="diseno.tables.border_color"
                                type="color"
                                class="h-9 w-full cursor-pointer rounded-md border"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Encabezado</Label>
                            <select
                                v-model="diseno.tables.header_style"
                                :class="claseCampo"
                            >
                                <option value="solid">Relleno</option>
                                <option value="light">Línea</option>
                                <option value="none">Sin estilo</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-2 text-sm">
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="diseno.tables.repeat_header"
                                    type="checkbox"
                                />
                                Repetir encabezado en cada página
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="diseno.tables.avoid_row_break"
                                    type="checkbox"
                                />
                                Evitar cortar filas
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="diseno.tables.zebra"
                                    type="checkbox"
                                />
                                Filas alternadas
                            </label>
                        </div>
                    </div>
                </details>

                <details class="rounded-2xl border bg-card p-4">
                    <summary class="cursor-pointer font-semibold">
                        Secciones y firmas
                    </summary>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label>Espaciado entre secciones (mm)</Label>
                            <Input
                                v-model.number="diseno.sections.spacing_mm"
                                type="number"
                                min="0"
                                max="20"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Tamaño de títulos (pt)</Label>
                            <Input
                                v-model.number="diseno.sections.title_size_pt"
                                type="number"
                                min="7"
                                max="24"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Color de títulos</Label>
                            <input
                                v-model="diseno.sections.title_color"
                                type="color"
                                class="h-9 w-full cursor-pointer rounded-md border"
                            />
                        </div>
                        <label
                            class="flex items-center gap-2 text-sm sm:col-span-3"
                        >
                            <input
                                v-model="diseno.sections.divider"
                                type="checkbox"
                            />
                            Línea divisoria en títulos
                        </label>
                        <div class="sm:col-span-3">
                            <p class="mb-2 text-sm font-medium">
                                Secciones visibles
                            </p>
                            <div class="flex flex-wrap gap-x-4 gap-y-2">
                                <label
                                    v-for="(
                                        etiqueta, clave
                                    ) in familia.secciones"
                                    :key="clave"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        v-model="
                                            diseno.sections.visibles[clave]
                                        "
                                        type="checkbox"
                                    />
                                    {{ etiqueta }}
                                </label>
                            </div>
                        </div>

                        <label
                            class="flex items-center gap-2 text-sm sm:col-span-3"
                        >
                            <input
                                v-model="diseno.signatures.show"
                                type="checkbox"
                            />
                            Mostrar firmas
                        </label>
                        <div class="grid gap-1.5">
                            <Label>Ancho de línea (mm)</Label>
                            <Input
                                v-model.number="diseno.signatures.line_width_mm"
                                type="number"
                                min="20"
                                max="120"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Separación (mm)</Label>
                            <Input
                                v-model.number="diseno.signatures.gap_mm"
                                type="number"
                                min="5"
                                max="80"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Posición</Label>
                            <select
                                v-model="diseno.signatures.position"
                                :class="claseCampo"
                            >
                                <option value="split">Repartidas</option>
                                <option value="left">Izquierda</option>
                                <option value="center">Centro</option>
                                <option value="right">Derecha</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-2 sm:col-span-3">
                            <div
                                v-for="(bloque, i) in diseno.signatures.blocks"
                                :key="i"
                                class="flex items-center gap-2"
                            >
                                <Input v-model="bloque.label" maxlength="80" />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Quitar firma"
                                    @click="
                                        diseno.signatures.blocks.splice(i, 1)
                                    "
                                >
                                    <X class="size-4" />
                                </Button>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="w-fit"
                                :disabled="diseno.signatures.blocks.length >= 4"
                                @click="agregarFirma"
                            >
                                <Plus class="size-4" />
                                Agregar bloque de firma
                            </Button>
                        </div>
                    </div>
                </details>

                <div v-if="borrador" class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        :disabled="guardando"
                        @click="guardarBorrador()"
                    >
                        <Spinner v-if="guardando" />
                        <RefreshCw v-else class="size-4" />
                        Guardar y generar vista previa
                    </Button>
                    <Button
                        variant="ghost"
                        class="text-destructive"
                        @click="descartarBorrador"
                    >
                        <Trash2 class="size-4" />
                        Descartar borrador
                    </Button>
                </div>
            </fieldset>

            <!-- Vista previa real (PDF) -->
            <section
                class="flex flex-col gap-3 xl:sticky xl:top-4 xl:self-start"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold">Vista previa</h2>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="versionPrevia = Date.now()"
                    >
                        <Eye class="size-4" />
                        Generar vista previa
                    </Button>
                </div>
                <p
                    v-if="cambiosSinGuardar && borrador"
                    class="text-xs text-amber-700 dark:text-amber-400"
                >
                    Hay cambios sin guardar: guarda para verlos en la vista
                    previa.
                </p>
                <iframe
                    :key="urlPrevia"
                    :src="urlPrevia"
                    title="Vista previa del documento"
                    class="h-[78vh] w-full rounded-2xl border bg-white"
                />
                <p class="text-xs text-muted-foreground">
                    PDF real con el mismo motor y diseño que la generación, con
                    datos ficticios de ejemplo.
                </p>
            </section>
        </div>

        <!-- Historial de versiones -->
        <section class="rounded-2xl border bg-card p-4">
            <h2 class="mb-3 flex items-center gap-2 font-semibold">
                <History class="size-4" />
                Historial de versiones
            </h2>
            <p v-if="!historial.length" class="text-sm text-muted-foreground">
                Aún no hay versiones: se usa el diseño de fábrica.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3">Versión</th>
                            <th class="py-2 pr-3">Estado</th>
                            <th class="py-2 pr-3">Motor</th>
                            <th class="py-2 pr-3">Creada</th>
                            <th class="py-2 pr-3">Activada</th>
                            <th class="py-2 pr-3">Documentos</th>
                            <th class="py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="v in historial"
                            :key="v.id"
                            class="border-t transition-colors hover:bg-muted/40"
                        >
                            <td class="py-2 pr-3 font-medium">
                                v{{ v.version }}
                                <span
                                    v-if="v.notas"
                                    class="block text-xs font-normal text-muted-foreground"
                                    >{{ v.notas }}</span
                                >
                            </td>
                            <td class="py-2 pr-3">
                                <Badge
                                    :variant="
                                        v.estado === 'activa'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    >{{ ESTADO_VERSION[v.estado] }}</Badge
                                >
                            </td>
                            <td class="py-2 pr-3">{{ v.motor_etiqueta }}</td>
                            <td class="py-2 pr-3">
                                {{ formatearFecha(v.creado_en) }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ v.creado_por }}</span
                                >
                            </td>
                            <td class="py-2 pr-3">
                                {{
                                    v.activado_en
                                        ? formatearFecha(v.activado_en)
                                        : '—'
                                }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ v.activado_por }}</span
                                >
                            </td>
                            <td class="py-2 pr-3 tabular-nums">
                                {{ v.documentos_generados }}
                            </td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <Button as-child size="sm" variant="ghost">
                                    <a
                                        :href="vistaPreviaVersion(v.id)"
                                        target="_blank"
                                        rel="noopener"
                                        >Ver</a
                                    >
                                </Button>
                                <Button
                                    v-if="v.estado === 'archivada'"
                                    size="sm"
                                    variant="outline"
                                    @click="activarVersion(v.id)"
                                >
                                    Activar
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
