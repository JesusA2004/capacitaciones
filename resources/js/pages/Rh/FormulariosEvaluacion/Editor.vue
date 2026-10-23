<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CircleAlert,
    Cloud,
    CloudOff,
    Eye,
    History,
    ImagePlus,
    ListChecks,
    LoaderCircle,
    Monitor,
    Palette,
    PencilLine,
    Rocket,
    Smartphone,
    Trash2,
    Undo2,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import EditorInduccion from '@/components/formularios-evaluacion/editor/EditorInduccion.vue';
import EditorPeriodoPrueba from '@/components/formularios-evaluacion/editor/EditorPeriodoPrueba.vue';
import EncabezadoFormulario from '@/components/formularios-evaluacion/EncabezadoFormulario.vue';
import FormularioInduccion from '@/components/formularios-evaluacion/FormularioInduccion.vue';
import FormularioPeriodoPrueba from '@/components/formularios-evaluacion/FormularioPeriodoPrueba.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { clonarSerializable } from '@/lib/clonar';
import { formatearFechaHora } from '@/lib/fechas';
import {
    contenidoPeriodoPrueba,
    contenidoPublicoInduccion,
    estiloAcento,
    tonoAcento,
    totalInduccion,
} from '@/lib/formulariosEvaluacion';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    borrador as crearBorrador,
    descartar,
    editar,
    guardar,
    index,
    publicar,
    version as verVersion,
} from '@/routes/rh/formularios-evaluacion';
import banner from '@/routes/rh/formularios-evaluacion/banner';
import type {
    ClaveFormularioEvaluacion,
    DefinicionInduccion,
    DefinicionPeriodoPrueba,
    DisenoFormulario,
    FormularioEvaluacionDetalle,
    InduccionRespuestas,
    OpcionesDisenoFormulario,
} from '@/types';

/**
 * Editor tipo Google Forms de un formulario de evaluación: preguntas,
 * diseño, vista previa (el MISMO componente que usa quien la contesta: el
 * colaborador en Inducción, el jefe directo/evaluador en Periodo de Prueba
 * FO-RH-EPP-001) y versiones. El borrador se guarda solo; publicar crea una
 * versión inmutable que usan las evaluaciones nuevas.
 */
const props = defineProps<{
    clave: ClaveFormularioEvaluacion;
    etiqueta: string;
    descripcionModulo: string;
    formulario: FormularioEvaluacionDetalle;
    publicado: FormularioEvaluacionDetalle;
    editable: boolean;
    soloLectura: boolean;
    historial: FormularioEvaluacionDetalle[];
    opciones: OpcionesDisenoFormulario;
}>();

defineOptions({
    layout: (p: { etiqueta?: string }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Formularios de evaluación', href: index() },
            { title: p.etiqueta ?? 'Editor', href: '' },
        ],
    }),
});

type Pestana = 'preguntas' | 'diseno' | 'vista' | 'versiones';
const pestana = ref<Pestana>('preguntas');
const dispositivo = ref<'escritorio' | 'telefono'>('escritorio');

// ---- Estado local del borrador (fuente de verdad mientras se edita)
type Borrador = {
    titulo: string;
    descripcion: string;
    codigo: string;
    diseno: DisenoFormulario;
    definicion: DefinicionInduccion | DefinicionPeriodoPrueba;
};

function desdeProps(): Borrador {
    const f = clonarSerializable(props.formulario);

    return {
        titulo: f.titulo,
        descripcion: f.descripcion ?? '',
        codigo: f.codigo,
        diseno: f.diseno,
        definicion: f.definicion as
            DefinicionInduccion | DefinicionPeriodoPrueba,
    };
}

const local = reactive<Borrador>(desdeProps());
const esInduccion = computed(() => props.clave === 'induccion');
const defInduccion = computed({
    get: () => local.definicion as DefinicionInduccion,
    set: (v) => (local.definicion = v),
});
const defPrueba = computed({
    get: () => local.definicion as DefinicionPeriodoPrueba,
    set: (v) => (local.definicion = v),
});

// La imagen la cambia el servidor (subir/quitar): siempre la de props.
const diseno = computed<DisenoFormulario>(() => ({
    ...local.diseno,
    banner_url: props.formulario.diseno.banner_url,
}));

// Al cambiar de versión (publicar, crear borrador) se recarga el estado.
watch(
    () => props.formulario.id,
    () => Object.assign(local, desdeProps()),
);

// ---- Autoguardado
const estadoGuardado = ref<'guardado' | 'pendiente' | 'guardando' | 'error'>(
    'guardado',
);
const guardadoEn = ref<string | null>(props.formulario.actualizado_en);

const enviarGuardado = useDebounceFn(() => {
    if (!props.editable) {
        return;
    }

    estadoGuardado.value = 'guardando';
    router.put(
        guardar.url(props.formulario.id),
        {
            titulo: local.titulo,
            descripcion: local.descripcion,
            codigo: local.codigo,
            diseno: {
                acento: local.diseno.acento,
                estilo_encabezado: local.diseno.estilo_encabezado,
            },
            definicion: clonarSerializable(local.definicion),
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['formulario', 'historial'],
            onSuccess: () => {
                estadoGuardado.value = 'guardado';
                guardadoEn.value = new Date().toISOString();
            },
            onError: () => (estadoGuardado.value = 'error'),
        },
    );
}, 1200);

watch(
    () => [
        local.titulo,
        local.descripcion,
        local.codigo,
        local.diseno,
        local.definicion,
    ],
    () => {
        if (!props.editable) {
            return;
        }

        estadoGuardado.value = 'pendiente';
        enviarGuardado();
    },
    { deep: true },
);

// ---- Totales y problemas
const totalPuntos = computed(() =>
    esInduccion.value
        ? totalInduccion(defInduccion.value)
        : defPrueba.value.criterios.length * 4,
);
const minimo = computed(() =>
    esInduccion.value
        ? defInduccion.value.calificacion_aprobatoria
        : defPrueba.value.total_minimo_renovar,
);
const porcentajeMinimo = computed(() =>
    totalPuntos.value
        ? Math.min(
              100,
              Math.round((Number(minimo.value) / totalPuntos.value) * 100),
          )
        : 0,
);
const problemas = computed(() => props.formulario.problemas);

// ---- Vista previa (mismos componentes que la página real)
const meta = computed(() => ({
    id: props.formulario.id,
    clave: props.clave,
    version: props.formulario.etiqueta_version,
    codigo: local.codigo,
    titulo: local.titulo,
    descripcion: local.descripcion || null,
    diseno: diseno.value,
}));
const contenidoInduccion = computed(() =>
    contenidoPublicoInduccion(defInduccion.value, meta.value),
);
const contenidoPrueba = computed(() =>
    contenidoPeriodoPrueba(defPrueba.value, meta.value),
);

const respuestasPrueba = ref<InduccionRespuestas>({
    a: {},
    b: {},
    c: {},
    d: {},
});
const opinionPrueba = ref<Record<string, number | string | null>>({
    tema_reforzar: '',
});
const criteriosPrueba = ref<{ clave: string; calificacion: number }[]>([]);
const filtrosPrueba = ref<{ clave: string; respuesta: boolean }[]>([]);
const observacionesPrueba = ref('');

function reiniciarVistaPrevia(): void {
    respuestasPrueba.value = { a: {}, b: {}, c: {}, d: {} };
    opinionPrueba.value = { tema_reforzar: '' };
    criteriosPrueba.value = esInduccion.value
        ? []
        : defPrueba.value.criterios.map((c) => ({
              clave: c.clave,
              calificacion: 4,
          }));
    filtrosPrueba.value = esInduccion.value
        ? []
        : defPrueba.value.filtros.map((f) => ({
              clave: f.clave,
              respuesta: false,
          }));
    observacionesPrueba.value = '';
}

watch(pestana, (p) => {
    if (p === 'vista') {
        reiniciarVistaPrevia();
    }
});

// ---- Acciones
const confirmarPublicar = ref(false);
const confirmarDescartar = ref(false);
const procesando = ref(false);

function editarFormulario(desde?: number): void {
    router.post(crearBorrador.url(props.clave), desde ? { desde } : {}, {
        preserveScroll: true,
    });
}

function publicarVersion(): void {
    procesando.value = true;
    // Primero se guarda lo pendiente, luego se publica.
    router.put(
        guardar.url(props.formulario.id),
        {
            titulo: local.titulo,
            descripcion: local.descripcion,
            codigo: local.codigo,
            diseno: {
                acento: local.diseno.acento,
                estilo_encabezado: local.diseno.estilo_encabezado,
            },
            definicion: clonarSerializable(local.definicion),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () =>
                router.post(
                    publicar.url(props.formulario.id),
                    {},
                    {
                        preserveScroll: true,
                        onFinish: () => {
                            procesando.value = false;
                            confirmarPublicar.value = false;
                        },
                    },
                ),
            onError: () => (procesando.value = false),
        },
    );
}

function descartarBorrador(): void {
    router.delete(descartar.url(props.formulario.id), {
        preserveScroll: true,
        onFinish: () => (confirmarDescartar.value = false),
    });
}

const archivoBanner = ref<HTMLInputElement | null>(null);
const subiendoBanner = ref(false);
const errorBanner = ref<string | null>(null);

function subirBanner(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];

    if (!archivo) {
        return;
    }

    subiendoBanner.value = true;
    errorBanner.value = null;
    router.post(
        banner.subir.url(props.formulario.id),
        { banner: archivo },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            only: ['formulario'],
            onError: (e) =>
                (errorBanner.value = e.banner ?? 'No se pudo subir la imagen.'),
            onFinish: () => {
                subiendoBanner.value = false;

                if (archivoBanner.value) {
                    archivoBanner.value.value = '';
                }
            },
        },
    );
}

function quitarBanner(): void {
    router.delete(banner.quitar.url(props.formulario.id), {
        preserveScroll: true,
        preserveState: true,
        only: ['formulario'],
    });
}

const PESTANAS: { clave: Pestana; texto: string; icono: typeof Eye }[] = [
    { clave: 'preguntas', texto: 'Preguntas', icono: ListChecks },
    { clave: 'diseno', texto: 'Diseño', icono: Palette },
    { clave: 'vista', texto: 'Vista previa', icono: Eye },
    { clave: 'versiones', texto: 'Versiones', icono: History },
];

const campoTitulo =
    'w-full rounded-lg border border-transparent bg-transparent px-2 py-1 outline-none hover:border-border focus:border-[var(--acento)] focus:bg-background disabled:hover:border-transparent';
</script>

<template>
    <Head :title="`Editor · ${etiqueta}`" />

    <div
        class="pagina-ancha flex flex-col gap-5 pb-16"
        :style="estiloAcento(diseno)"
    >
        <CrudPageHeader :titulo="etiqueta" :descripcion="descripcionModulo">
            <template v-if="editable">
                <Button
                    variant="outline"
                    class="text-destructive hover:text-destructive"
                    @click="confirmarDescartar = true"
                >
                    <Trash2 class="size-4" /> Descartar borrador
                </Button>
                <Button @click="confirmarPublicar = true">
                    <Rocket class="size-4" /> Publicar versión
                    {{ formulario.version }}
                </Button>
            </template>
            <template v-else-if="soloLectura">
                <Button variant="outline" as-child>
                    <Link :href="editar(clave)"
                        ><Undo2 class="size-4" /> Volver a la vigente</Link
                    >
                </Button>
                <Button @click="editarFormulario(formulario.id)">
                    <PencilLine class="size-4" /> Restaurar como borrador
                </Button>
            </template>
            <Button v-else @click="editarFormulario()">
                <PencilLine class="size-4" /> Editar formulario
            </Button>
        </CrudPageHeader>

        <!-- Barra de estado -->
        <div
            data-tour="formulario-estado"
            class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-border bg-card px-4 py-3 text-sm shadow-xs"
        >
            <span
                :class="
                    cn(
                        'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        formulario.estado === 'borrador'
                            ? 'bg-warning-soft'
                            : formulario.estado === 'publicado'
                              ? 'bg-success-soft'
                              : 'bg-muted',
                    )
                "
                >{{ formulario.estado_etiqueta }}</span
            >
            <span class="font-medium">Versión {{ formulario.version }}</span>
            <span class="text-muted-foreground">{{
                formulario.etiqueta_version
            }}</span>
            <span
                v-if="editable"
                class="flex items-center gap-1.5 text-muted-foreground"
                role="status"
            >
                <LoaderCircle
                    v-if="estadoGuardado === 'guardando'"
                    class="size-4 animate-spin"
                />
                <CloudOff
                    v-else-if="estadoGuardado === 'error'"
                    class="size-4 text-destructive"
                />
                <Cloud v-else class="size-4" />
                {{
                    estadoGuardado === 'guardando'
                        ? 'Guardando…'
                        : estadoGuardado === 'pendiente'
                          ? 'Cambios sin guardar…'
                          : estadoGuardado === 'error'
                            ? 'No se pudo guardar: revisa los campos'
                            : `Borrador guardado${guardadoEn ? ' · ' + formatearFechaHora(guardadoEn) : ''}`
                }}
            </span>
            <span v-else-if="!soloLectura" class="text-muted-foreground">
                Es la versión vigente: para cambiarla, «Editar formulario» crea
                un borrador. Nadie ve los cambios hasta que publiques.
            </span>
            <span v-else class="text-muted-foreground">
                Versión anterior (solo lectura). La vigente es la
                {{ publicado.version }}.
            </span>
            <span class="ml-auto flex items-center gap-3">
                <span class="tabular-nums"
                    ><strong>{{ totalPuntos }}</strong> puntos</span
                >
                <span
                    class="hidden h-2 w-28 overflow-hidden rounded-full bg-muted sm:block"
                >
                    <span
                        class="block h-full rounded-full bg-[var(--acento)]"
                        :style="{ width: `${porcentajeMinimo}%` }"
                    />
                </span>
                <span class="text-muted-foreground tabular-nums"
                    >{{ esInduccion ? 'aprueba con' : 'renueva con' }}
                    <strong class="text-foreground">{{ minimo }}</strong></span
                >
            </span>
        </div>

        <!-- Pestañas -->
        <nav
            data-tour="formulario-pestanas"
            class="scroll-x-limpio flex gap-1 rounded-2xl border border-border bg-card p-1 shadow-xs"
            aria-label="Secciones del editor"
        >
            <button
                v-for="p in PESTANAS"
                :key="p.clave"
                type="button"
                :class="
                    cn(
                        'flex shrink-0 items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition-colors',
                        pestana === p.clave
                            ? 'bg-[var(--acento)] text-[var(--acento-texto)] shadow-sm'
                            : 'text-muted-foreground hover:bg-muted/50',
                    )
                "
                :aria-current="pestana === p.clave ? 'page' : undefined"
                @click="pestana = p.clave"
            >
                <component :is="p.icono" class="size-4" />
                {{ p.texto }}
            </button>
        </nav>

        <!-- PREGUNTAS -->
        <div
            v-if="pestana === 'preguntas'"
            class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_320px]"
        >
            <div class="flex min-w-0 flex-col gap-6">
                <header
                    class="overflow-hidden rounded-3xl border border-border bg-card shadow-sm"
                >
                    <div class="h-2.5 bg-[var(--acento)]" aria-hidden="true" />
                    <div class="flex flex-col gap-2 p-5">
                        <input
                            v-model="local.titulo"
                            :class="[
                                campoTitulo,
                                'font-display text-2xl font-semibold',
                            ]"
                            placeholder="Título del formulario"
                            :disabled="!editable"
                            aria-label="Título del formulario"
                        />
                        <textarea
                            v-model="local.descripcion"
                            rows="2"
                            :class="[
                                campoTitulo,
                                'resize-y text-sm text-muted-foreground',
                            ]"
                            placeholder="Descripción o instrucciones generales"
                            :disabled="!editable"
                            aria-label="Descripción del formulario"
                        />
                        <label
                            class="flex items-center gap-2 px-2 text-xs text-muted-foreground"
                            >Código del formato
                            <input
                                v-model="local.codigo"
                                :class="[campoTitulo, 'w-44 text-xs']"
                                maxlength="40"
                                :disabled="!editable"
                        /></label>
                    </div>
                </header>

                <EditorInduccion
                    v-if="esInduccion"
                    v-model="defInduccion"
                    :solo-lectura="!editable"
                />
                <EditorPeriodoPrueba
                    v-else
                    v-model="defPrueba"
                    :solo-lectura="!editable"
                />
            </div>

            <aside class="flex flex-col gap-4 xl:sticky xl:top-20">
                <section
                    class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 shadow-xs"
                >
                    <h3 class="text-sm font-semibold">Antes de publicar</h3>
                    <p v-if="!editable" class="text-sm text-muted-foreground">
                        Esta versión ya está publicada y no se modifica.
                    </p>
                    <p
                        v-else-if="problemas.length === 0"
                        class="flex items-center gap-2 text-sm"
                    >
                        <CheckCircle2
                            class="size-4 text-[var(--mrl-success)]"
                        />
                        Todo listo para publicar.
                    </p>
                    <ul v-else class="flex flex-col gap-2 text-sm">
                        <li
                            v-for="(p, i) in problemas"
                            :key="i"
                            class="flex items-start gap-2"
                        >
                            <CircleAlert
                                class="mt-0.5 size-4 shrink-0 text-[var(--mrl-danger)]"
                            />
                            {{ p }}
                        </li>
                    </ul>
                </section>
                <section
                    class="flex flex-col gap-2 rounded-2xl border border-border bg-crema/50 p-4 text-sm"
                >
                    <h3 class="font-semibold">Cómo funciona</h3>
                    <ul
                        class="flex list-disc flex-col gap-1.5 pl-4 text-muted-foreground"
                    >
                        <template v-if="esInduccion">
                            <li>
                                Las secciones A a E son fijas: así las leen la
                                app y el PDF. Dentro de cada una puedes agregar,
                                quitar, reordenar y editar preguntas.
                            </li>
                            <li>
                                Las respuestas correctas y la rúbrica nunca se
                                muestran al colaborador.
                            </li>
                        </template>
                        <template v-else>
                            <li>
                                La escala siempre es de 4 a 1; puedes cambiar el
                                nombre de cada valor.
                            </li>
                            <li>
                                Si cambias cuántos criterios hay, revisa que el
                                formato para firma (Documentos maestros) tenga
                                renglones para todos.
                            </li>
                        </template>
                        <li>
                            Los cambios se guardan solos en el borrador. Nadie
                            los ve hasta que publiques.
                        </li>
                        <li>
                            Las evaluaciones ya abiertas o contestadas conservan
                            la versión con la que empezaron.
                        </li>
                    </ul>
                </section>
            </aside>
        </div>

        <!-- DISEÑO -->
        <div
            v-else-if="pestana === 'diseno'"
            class="grid items-start gap-5 xl:grid-cols-[360px_minmax(0,1fr)]"
        >
            <section
                class="flex flex-col gap-5 rounded-2xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold">Color de acento</h3>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="a in opciones.acentos"
                            :key="a.value"
                            type="button"
                            :title="a.label"
                            :aria-label="a.label"
                            :aria-pressed="local.diseno.acento === a.value"
                            :disabled="!editable"
                            :class="
                                cn(
                                    'flex size-11 items-center justify-center rounded-full border-2 transition-transform hover:scale-105 disabled:cursor-not-allowed disabled:hover:scale-100',
                                    local.diseno.acento === a.value
                                        ? 'border-foreground'
                                        : 'border-transparent',
                                )
                            "
                            :style="{ background: tonoAcento(a.value).fondo }"
                            @click="local.diseno.acento = a.value"
                        >
                            <CheckCircle2
                                v-if="local.diseno.acento === a.value"
                                class="size-5"
                                :style="{ color: tonoAcento(a.value).texto }"
                            />
                        </button>
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold">Estilo del encabezado</h3>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="e in opciones.estilos"
                            :key="e.value"
                            type="button"
                            :disabled="!editable"
                            :class="
                                cn(
                                    'flex flex-col gap-1.5 rounded-xl border p-2 text-xs font-medium transition-colors',
                                    local.diseno.estilo_encabezado === e.value
                                        ? 'border-[var(--primary)] bg-[color-mix(in_oklab,var(--acento)_10%,transparent)]'
                                        : 'border-border hover:bg-muted/40',
                                )
                            "
                            @click="local.diseno.estilo_encabezado = e.value"
                        >
                            <span
                                :class="
                                    cn(
                                        'block h-8 overflow-hidden rounded-md border border-border',
                                        e.value === 'solido'
                                            ? 'bg-[var(--acento)]'
                                            : e.value === 'degradado'
                                              ? 'bg-gradient-to-br from-[color-mix(in_oklab,var(--acento)_40%,transparent)] to-card'
                                              : 'bg-card',
                                    )
                                "
                                ><span
                                    v-if="e.value === 'franja'"
                                    class="block h-1.5 bg-[var(--acento)]"
                            /></span>
                            {{ e.label }}
                        </button>
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold">
                        Imagen de portada (opcional)
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        JPG, PNG o WEBP de hasta 4 MB. Se recomienda horizontal
                        (por ejemplo 1600 × 400).
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <input
                            ref="archivoBanner"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="hidden"
                            @change="subirBanner"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="!editable || subiendoBanner"
                            @click="archivoBanner?.click()"
                        >
                            <LoaderCircle
                                v-if="subiendoBanner"
                                class="size-4 animate-spin"
                            />
                            <ImagePlus v-else class="size-4" />
                            {{
                                diseno.banner_url
                                    ? 'Cambiar imagen'
                                    : 'Subir imagen'
                            }}
                        </Button>
                        <Button
                            v-if="diseno.banner_url"
                            type="button"
                            variant="ghost"
                            :disabled="!editable"
                            @click="quitarBanner"
                        >
                            Quitar
                        </Button>
                    </div>
                    <p v-if="errorBanner" class="text-sm text-destructive">
                        {{ errorBanner }}
                    </p>
                </div>
            </section>
            <div class="flex flex-col gap-2">
                <p
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Así se verá el encabezado
                </p>
                <EncabezadoFormulario
                    :titulo="local.titulo"
                    :descripcion="local.descripcion"
                    :diseno="diseno"
                />
            </div>
        </div>

        <!-- VISTA PREVIA -->
        <div v-else-if="pestana === 'vista'" class="flex flex-col gap-4">
            <div
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-info-soft px-4 py-3 text-sm"
            >
                <p class="flex items-start gap-2">
                    <Eye class="mt-0.5 size-4 shrink-0" />
                    <span v-if="esInduccion">
                        <strong>Vista previa como colaborador.</strong>
                        Es la misma pantalla que verá; puedes marcar respuestas
                        para probarla, pero no se guarda nada.
                    </span>
                    <span v-else>
                        <strong
                            >Vista previa como jefe directo / evaluador.</strong
                        >
                        Esta es la pantalla que utilizará el responsable de
                        evaluar al colaborador. Puedes marcar respuestas para
                        probar el formulario; la vista previa no guarda
                        información.
                    </span>
                </p>
                <div class="flex gap-1 rounded-xl bg-card p-1">
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            dispositivo === 'escritorio' ? 'default' : 'ghost'
                        "
                        @click="dispositivo = 'escritorio'"
                    >
                        <Monitor class="size-4" /> Computadora
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            dispositivo === 'telefono' ? 'default' : 'ghost'
                        "
                        @click="dispositivo = 'telefono'"
                    >
                        <Smartphone class="size-4" /> Teléfono
                    </Button>
                </div>
            </div>

            <div
                :class="
                    dispositivo === 'telefono'
                        ? 'mx-auto h-[760px] w-full max-w-[400px] overflow-y-auto rounded-[2.5rem] border-[10px] border-foreground/85 bg-background p-3 shadow-xl'
                        : 'rounded-3xl border border-dashed border-border bg-background p-4 sm:p-6'
                "
            >
                <FormularioInduccion
                    v-if="esInduccion"
                    v-model:respuestas="respuestasPrueba"
                    v-model:opinion="opinionPrueba"
                    :contenido="contenidoInduccion"
                >
                    <template #acciones>
                        <Button type="button" size="lg" class="w-fit" disabled>
                            Enviar evaluación
                        </Button>
                    </template>
                </FormularioInduccion>
                <FormularioPeriodoPrueba
                    v-else
                    v-model:criterios="criteriosPrueba"
                    v-model:filtros="filtrosPrueba"
                    v-model:observaciones="observacionesPrueba"
                    :contenido="contenidoPrueba"
                    :compacto="dispositivo === 'telefono'"
                />
            </div>
        </div>

        <!-- VERSIONES -->
        <section v-else class="flex flex-col gap-3">
            <article
                v-for="v in historial"
                :key="v.id"
                :class="
                    cn(
                        'flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border bg-card px-4 py-3 shadow-xs',
                        v.id === formulario.id
                            ? 'border-[var(--primary)]'
                            : 'border-border',
                    )
                "
            >
                <span
                    class="flex size-10 items-center justify-center rounded-full bg-[color-mix(in_oklab,var(--acento)_16%,transparent)] font-semibold"
                    >v{{ v.version }}</span
                >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ v.titulo }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ v.estado_etiqueta }}
                        <template v-if="v.publicado_en">
                            · publicada {{ formatearFechaHora(v.publicado_en) }}
                            <template v-if="v.publicado_por"
                                >por {{ v.publicado_por }}</template
                            >
                        </template>
                        <template v-else-if="v.actualizado_en">
                            · editada {{ formatearFechaHora(v.actualizado_en) }}
                        </template>
                        · {{ v.evaluaciones ?? 0 }}
                        {{
                            (v.evaluaciones ?? 0) === 1
                                ? 'evaluación la usa'
                                : 'evaluaciones la usan'
                        }}
                    </p>
                </div>
                <Button variant="outline" size="sm" as-child>
                    <Link
                        :href="
                            v.estado === 'borrador'
                                ? editar(clave)
                                : verVersion(v.id)
                        "
                    >
                        {{ v.estado === 'borrador' ? 'Editar' : 'Ver' }}
                    </Link>
                </Button>
            </article>
        </section>
    </div>

    <Dialog v-model:open="confirmarPublicar">
        <DialogContent>
            <DialogHeader>
                <DialogTitle
                    >¿Publicar la versión {{ formulario.version }}?</DialogTitle
                >
                <DialogDescription>
                    Desde ahora, las evaluaciones que se abran usarán esta
                    versión. Las que ya están en curso o contestadas conservan
                    la suya. Una versión publicada ya no se edita: para
                    cambiarla se crea otra.
                </DialogDescription>
            </DialogHeader>
            <ul
                v-if="problemas.length"
                class="flex flex-col gap-1.5 rounded-xl bg-danger-soft p-3 text-sm"
            >
                <li v-for="(p, i) in problemas" :key="i">{{ p }}</li>
            </ul>
            <DialogFooter>
                <Button variant="outline" @click="confirmarPublicar = false">
                    Cancelar
                </Button>
                <Button
                    :disabled="procesando || problemas.length > 0"
                    @click="publicarVersion"
                >
                    <LoaderCircle
                        v-if="procesando"
                        class="size-4 animate-spin"
                    />
                    Publicar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="confirmarDescartar">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>¿Descartar el borrador?</DialogTitle>
                <DialogDescription>
                    Se pierden los cambios de la versión
                    {{ formulario.version }}
                    sin publicar. La versión vigente no cambia.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="confirmarDescartar = false">
                    Cancelar
                </Button>
                <Button variant="destructive" @click="descartarBorrador">
                    Descartar borrador
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
