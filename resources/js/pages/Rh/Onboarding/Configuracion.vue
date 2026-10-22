<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    ClipboardCheck,
    ExternalLink,
    Eye,
    GraduationCap,
    Package,
    PlayCircle,
    Plus,
    ShieldCheck,
    Trash2,
    UserCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import LeccionOnboardingContenido from '@/components/ciclo/LeccionOnboardingContenido.vue';
import Casilla from '@/components/Common/Casilla.vue';
import RadioMarca from '@/components/Common/RadioMarca.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import CapitulosInduccionVista from '@/components/onboarding/CapitulosInduccionVista.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import onboarding from '@/routes/rh/onboarding';
import { vistaPrevia as vistaPreviaEvaluacion } from '@/routes/rh/onboarding/induccion';
import type { CapituloInduccion } from '@/types';

/**
 * «Configuración de onboarding» (RH con onboarding.configurar): qué recorre
 * una persona nueva antes de empezar a laborar — los módulos de inducción
 * (institucional y por puesto) y los activos que se le entregan con carta
 * responsiva. La Inducción Mr. Lana (módulo con evaluación formal) se evalúa
 * con el FO-RH-EI, no con el quiz genérico; aquí solo se editan sus datos
 * generales. Reglas en OnboardingCatalogoService.
 */
type Pregunta = { pregunta: string; opciones: string[]; correcta: number };
type Modulo = {
    id: number;
    titulo: string;
    descripcion: string | null;
    tipo: string;
    puesto_id: number | null;
    puesto: string | null;
    orden: number;
    contenido_url: string | null;
    contenido: string | null;
    evaluacion_formal_clave: string | null;
    capitulos: CapituloInduccion[];
    preguntas: Pregunta[];
    calificacion_minima: number;
    obligatorio: boolean;
    activo: boolean;
};
type TipoActivo = {
    id: number;
    clave: string;
    nombre: string;
    requiere_identificador: boolean;
    puesto_ids: number[] | null;
    obligatorio: boolean;
    activo: boolean;
};

const props = defineProps<{
    modulos: Modulo[];
    tiposActivo: TipoActivo[];
    puestos: { id: number; nombre: string }[];
    evaluacionFormal: {
        titulo: string;
        codigo: string;
        version: string;
        total_puntos: number;
        calificacion_aprobatoria: number;
        configurar_url: string | null;
    };
    rutas: { induccion_institucional: string; vista_colaborador: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Configuración de onboarding', href: '' },
        ],
    },
});

const institucionales = computed(() =>
    props.modulos.filter((m) => m.tipo === 'institucional'),
);
const dePuesto = computed(() =>
    props.modulos.filter((m) => m.tipo !== 'institucional'),
);

// --- Módulo ---
const editandoModulo = ref<number | 'nuevo' | null>(null);
const editandoFormal = computed(
    () =>
        typeof editandoModulo.value === 'number' &&
        props.modulos.find((m) => m.id === editandoModulo.value)
            ?.evaluacion_formal_clave != null,
);
const modulo = useForm({
    titulo: '',
    descripcion: '',
    tipo: 'institucional',
    puesto_id: null as number | null,
    orden: 1,
    contenido_url: '',
    contenido: '',
    preguntas: [] as Pregunta[],
    calificacion_minima: 8,
    obligatorio: true,
    activo: true,
});

function editarModulo(
    m: Modulo | null,
    tipo: 'institucional' | 'puesto' = 'institucional',
) {
    modulo.clearErrors();

    if (m === null) {
        modulo.reset();
        modulo.tipo = tipo;
        modulo.preguntas = [{ pregunta: '', opciones: ['', ''], correcta: 0 }];
        editandoModulo.value = 'nuevo';

        return;
    }

    Object.assign(modulo, {
        titulo: m.titulo,
        descripcion: m.descripcion ?? '',
        tipo: m.tipo,
        puesto_id: m.puesto_id,
        orden: m.orden,
        contenido_url: m.contenido_url ?? '',
        contenido: m.contenido ?? '',
        preguntas: JSON.parse(JSON.stringify(m.preguntas)),
        calificacion_minima: m.calificacion_minima,
        obligatorio: m.obligatorio,
        activo: m.activo,
    });
    editandoModulo.value = m.id;
}

function guardarModulo() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (editandoModulo.value = null),
    };

    if (editandoModulo.value === 'nuevo') {
        modulo.post(onboarding.modulos.store.url(), opciones);
    } else if (typeof editandoModulo.value === 'number') {
        modulo.put(
            onboarding.modulos.update.url(editandoModulo.value),
            opciones,
        );
    }
}

// --- Activo ---
const activo = useForm({
    clave: '',
    nombre: '',
    requiere_identificador: false,
    obligatorio: true,
    activo: true,
    puesto_ids: [] as number[],
});
const editandoActivo = ref<number | 'nuevo' | null>(null);

function editarActivo(t: TipoActivo | null) {
    activo.clearErrors();
    activo.reset();

    if (t !== null) {
        Object.assign(activo, { ...t, puesto_ids: t.puesto_ids ?? [] });
    }

    editandoActivo.value = t?.id ?? 'nuevo';
}

function guardarActivo() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (editandoActivo.value = null),
    };

    if (editandoActivo.value === 'nuevo') {
        activo.post(onboarding.tiposActivo.store.url(), opciones);
    } else if (typeof editandoActivo.value === 'number') {
        activo.put(
            onboarding.tiposActivo.update.url(editandoActivo.value),
            opciones,
        );
    }
}

// --- Vista previa: lección (mismo componente que «Mis pendientes») o, para
// la Inducción Mr. Lana, sus capítulos legibles + la vista real.
type VistaPreviaLeccion = {
    titulo: string;
    descripcion: string | null;
    contenido_url: string | null;
    contenido: string | null;
    preguntas: { indice: number; pregunta: string; opciones: string[] }[];
    puede_presentar: boolean;
};

const vistaPrevia = ref<VistaPreviaLeccion | null>(null);
const vistaFormal = ref<Modulo | null>(null);
const respuestasPrueba = ref<Record<number, number>>({});

function texto(valor: string | null | undefined): string | null {
    const limpio = (valor ?? '').trim();

    return limpio === '' ? null : limpio;
}

function abrirVistaPrevia(m: {
    titulo: string;
    descripcion: string | null;
    contenido_url: string | null;
    contenido: string | null;
    preguntas: Pregunta[];
}) {
    respuestasPrueba.value = {};
    vistaPrevia.value = {
        titulo: m.titulo.trim() || 'Lección sin título',
        descripcion: texto(m.descripcion),
        contenido_url: texto(m.contenido_url),
        contenido: texto(m.contenido),
        preguntas: m.preguntas.map((p, indice) => ({
            indice,
            pregunta: p.pregunta.trim(),
            opciones: p.opciones.map((o) => o.trim()).filter((o) => o !== ''),
        })),
        puede_presentar: true,
    };
}

function vistaDe(m: Modulo) {
    if (m.evaluacion_formal_clave) {
        vistaFormal.value = m;

        return;
    }

    abrirVistaPrevia(m);
}

const pasosIngreso = [
    {
        titulo: 'Inducción institucional',
        texto: 'La Inducción Mr. Lana: presentación, video y evaluación FO-RH-EI.',
        icono: Building2,
    },
    {
        titulo: 'Inducción al puesto',
        texto: 'Lecciones de su puesto (con quiz) y su descriptivo de puesto.',
        icono: Briefcase,
    },
    {
        titulo: 'Entrega de activos',
        texto: 'Uniforme y equipo con carta responsiva firmada.',
        icono: Package,
    },
    {
        titulo: 'Laborando',
        texto: 'Con todo aprobado y entregado empieza a trabajar.',
        icono: UserCheck,
    },
];
</script>

<template>
    <Head title="Configuración de onboarding" />

    <div class="pagina-ancha flex flex-col gap-6 pb-10">
        <CrudPageHeader
            titulo="Configuración de onboarding"
            :icono="GraduationCap"
        >
            <Button as-child variant="outline" class="w-full sm:w-auto">
                <Link :href="rutas.vista_colaborador"
                    ><Eye class="size-4" /> Ver como colaborador nuevo</Link
                >
            </Button>
        </CrudPageHeader>

        <!-- Qué es, en lenguaje llano -->
        <section
            class="rounded-3xl border border-border/60 bg-gradient-to-br from-crema via-card to-salvia p-5 shadow-sm sm:p-6"
        >
            <h1 class="text-xl font-semibold">¿Qué es el onboarding?</h1>
            <p class="mt-1 max-w-3xl text-sm text-pretty text-muted-foreground">
                Es todo lo que hace una persona nueva entre que firma sus
                contratos y empieza a laborar. Empieza solo al firmar y avanza
                en este orden. Aquí configuras qué ve y qué se le entrega; los
                cambios aplican a quienes empiecen su onboarding después.
            </p>
            <ol class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <li
                    v-for="(p, i) in pasosIngreso"
                    :key="p.titulo"
                    class="flex items-start gap-3 rounded-2xl bg-card/80 p-3"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                    >
                        <component :is="p.icono" class="size-5" />
                    </span>
                    <span>
                        <span class="block text-sm font-semibold"
                            >{{ i + 1 }} · {{ p.titulo }}</span
                        >
                        <span class="block text-xs text-muted-foreground">{{
                            p.texto
                        }}</span>
                    </span>
                </li>
            </ol>
        </section>

        <!-- Formulario de módulo -->
        <form
            v-if="editandoModulo !== null"
            class="flex flex-col gap-4 rounded-3xl border-2 border-primary/30 bg-card p-5 shadow-sm"
            @submit.prevent="guardarModulo"
        >
            <div>
                <h2 class="text-lg font-semibold">
                    {{
                        editandoModulo === 'nuevo'
                            ? 'Nuevo módulo de inducción'
                            : 'Editar módulo'
                    }}
                </h2>
                <p v-if="editandoFormal" class="text-sm text-muted-foreground">
                    Este es el módulo de la Inducción Mr. Lana: su contenido es
                    el PDF oficial y el video/audio (se administran en
                    «Inducción institucional») y se evalúa con el
                    {{ evaluacionFormal.codigo }}. Aquí solo cambias sus datos
                    generales.
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="m-titulo">Título</Label>
                    <Input
                        id="m-titulo"
                        v-model="modulo.titulo"
                        placeholder="Ej. Atención al cliente en sucursal"
                    />
                    <p class="text-xs text-muted-foreground">
                        Así lo verá la persona en Mis pendientes.
                    </p>
                    <InputError :message="modulo.errors.titulo" />
                </div>
                <div v-if="!editandoFormal" class="grid gap-1.5">
                    <Label>¿Para quién es?</Label>
                    <SelectSimple
                        v-model="modulo.tipo"
                        :opciones="[
                            {
                                value: 'institucional',
                                label: 'Institucional (todas las personas)',
                            },
                            {
                                value: 'puesto',
                                label: 'Inducción al puesto (un puesto)',
                            },
                        ]"
                    />
                    <p class="text-xs text-muted-foreground">
                        Institucional lo ve todo el personal nuevo; al puesto,
                        solo ese puesto.
                    </p>
                </div>
                <div
                    v-if="!editandoFormal && modulo.tipo === 'puesto'"
                    class="grid gap-1.5"
                >
                    <Label>Puesto</Label>
                    <SelectSimple
                        v-model="modulo.puesto_id"
                        numerico
                        :opciones="
                            puestos.map((p) => ({
                                value: p.id,
                                label: p.nombre,
                            }))
                        "
                        placeholder="Elige el puesto"
                    />
                    <InputError :message="modulo.errors.puesto_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="m-orden">Orden</Label>
                    <Input
                        id="m-orden"
                        v-model.number="modulo.orden"
                        type="number"
                        min="1"
                    />
                    <p class="text-xs text-muted-foreground">
                        Los módulos se abren en este orden, uno al aprobar el
                        anterior.
                    </p>
                </div>
                <div v-if="!editandoFormal" class="grid gap-1.5">
                    <Label for="m-minima"
                        >Calificación mínima para aprobar (0 a 10)</Label
                    >
                    <Input
                        id="m-minima"
                        v-model.number="modulo.calificacion_minima"
                        type="number"
                        min="0"
                        max="10"
                        step="0.5"
                    />
                    <p class="text-xs text-muted-foreground">
                        Con menos, RH da retroalimentación y habilita otro
                        intento.
                    </p>
                    <InputError :message="modulo.errors.calificacion_minima" />
                </div>
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="m-desc">Descripción (opcional)</Label>
                    <Textarea
                        id="m-desc"
                        v-model="modulo.descripcion"
                        rows="2"
                        placeholder="Para qué sirve este módulo, en una o dos líneas."
                    />
                </div>
                <template v-if="!editandoFormal">
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="m-url"
                            >Enlace al video o material (opcional)</Label
                        >
                        <Input
                            id="m-url"
                            v-model="modulo.contenido_url"
                            type="url"
                            placeholder="https://…"
                        />
                        <InputError :message="modulo.errors.contenido_url" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="m-contenido"
                            >Contenido o instrucciones (opcional)</Label
                        >
                        <Textarea
                            id="m-contenido"
                            v-model="modulo.contenido"
                            rows="3"
                            placeholder="Lo que la persona debe leer antes de responder."
                        />
                    </div>
                </template>
            </div>

            <div v-if="!editandoFormal" class="flex flex-col gap-3">
                <div>
                    <Label>Preguntas de evaluación</Label>
                    <p class="text-xs text-muted-foreground">
                        Marca con el círculo la respuesta correcta de cada
                        pregunta. La persona nunca la ve.
                    </p>
                </div>
                <div
                    v-for="(p, i) in modulo.preguntas"
                    :key="i"
                    class="flex flex-col gap-2 rounded-2xl bg-muted/40 p-3"
                >
                    <div class="flex gap-2">
                        <Input
                            v-model="p.pregunta"
                            :placeholder="`Pregunta ${i + 1}`"
                            :aria-label="`Pregunta ${i + 1}`"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Quitar pregunta ${i + 1}`"
                            @click="modulo.preguntas.splice(i, 1)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                    <label
                        v-for="(_, j) in p.opciones"
                        :key="j"
                        class="flex items-center gap-2 text-sm"
                    >
                        <RadioMarca
                            v-model="p.correcta"
                            :value="j"
                            :name="`correcta-${i}`"
                        />
                        <Input
                            v-model="p.opciones[j]"
                            :placeholder="`Opción ${j + 1}`"
                        />
                    </label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="w-fit"
                        @click="p.opciones.push('')"
                        >+ Agregar opción</Button
                    >
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="w-fit"
                    @click="
                        modulo.preguntas.push({
                            pregunta: '',
                            opciones: ['', ''],
                            correcta: 0,
                        })
                    "
                >
                    + Agregar pregunta
                </Button>
                <InputError :message="modulo.errors.preguntas" />
            </div>

            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 text-sm"
                    ><Casilla v-model="modulo.activo" /> Activo (se asigna a
                    quien empiece su onboarding)</label
                >
                <label class="flex items-center gap-2 text-sm"
                    ><Casilla v-model="modulo.obligatorio" /> Obligatorio para
                    cerrar el onboarding</label
                >
            </div>
            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="modulo.processing"
                    >Guardar</Button
                >
                <Button
                    v-if="!editandoFormal"
                    type="button"
                    variant="outline"
                    @click="abrirVistaPrevia(modulo)"
                >
                    <Eye class="size-4" /> Vista previa
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    @click="editandoModulo = null"
                    >Cancelar</Button
                >
            </div>
        </form>

        <!-- Módulos de inducción -->
        <section data-tour="onboarding-modulos" class="flex flex-col gap-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Módulos de inducción</h2>
                    <p class="text-sm text-muted-foreground">
                        Lo que la persona estudia y en qué se le evalúa. Toca un
                        módulo para editarlo.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        @click="editarModulo(null, 'institucional')"
                        ><Plus class="size-4" /> Módulo institucional</Button
                    >
                    <Button @click="editarModulo(null, 'puesto')"
                        ><Plus class="size-4" /> Módulo al puesto</Button
                    >
                </div>
            </div>

            <div
                v-for="grupo in [
                    {
                        titulo: 'Inducción institucional',
                        ayuda: 'Para todo el personal nuevo.',
                        lista: institucionales,
                    },
                    {
                        titulo: 'Inducción al puesto',
                        ayuda: 'Solo para el puesto indicado en cada módulo.',
                        lista: dePuesto,
                    },
                ]"
                :key="grupo.titulo"
                class="flex flex-col gap-2"
            >
                <h3 class="text-sm font-semibold text-muted-foreground">
                    {{ grupo.titulo }} · {{ grupo.ayuda }}
                </h3>
                <p
                    v-if="!grupo.lista.length"
                    class="rounded-2xl border border-dashed border-border/60 p-5 text-center text-sm text-muted-foreground"
                >
                    Todavía no hay módulos aquí.
                </p>
                <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="m in grupo.lista"
                        :key="m.id"
                        class="flex tarjeta-interactiva flex-col rounded-2xl border border-border/60 bg-card shadow-sm"
                        :class="m.activo ? '' : 'opacity-60'"
                    >
                        <button
                            type="button"
                            class="flex flex-1 flex-col gap-3 rounded-2xl p-4 text-left"
                            @click="editarModulo(m)"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                                >
                                    <component
                                        :is="
                                            m.tipo === 'institucional'
                                                ? Building2
                                                : Briefcase
                                        "
                                        class="size-5"
                                    />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="text-xs font-semibold text-muted-foreground"
                                    >
                                        Módulo {{ m.orden }} ·
                                        {{
                                            m.tipo === 'institucional'
                                                ? 'Institucional'
                                                : (m.puesto ?? 'Al puesto')
                                        }}
                                    </p>
                                    <p class="truncate font-semibold">
                                        {{ m.titulo }}
                                    </p>
                                    <p
                                        v-if="m.descripcion"
                                        class="line-clamp-2 text-xs text-muted-foreground"
                                    >
                                        {{ m.descripcion }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <template v-if="m.evaluacion_formal_clave">
                                    <ChipEstado
                                        :etiqueta="`Evaluación oficial ${evaluacionFormal.codigo} · ${evaluacionFormal.total_puntos} puntos · aprueba con ${evaluacionFormal.calificacion_aprobatoria}`"
                                        tono="info"
                                        :icono="ShieldCheck"
                                    />
                                    <ChipEstado
                                        :etiqueta="`${m.capitulos.length} capítulos + PDF oficial`"
                                        tono="neutro"
                                    />
                                </template>
                                <template v-else>
                                    <ChipEstado
                                        :etiqueta="`${m.preguntas.length} ${m.preguntas.length === 1 ? 'pregunta' : 'preguntas'}`"
                                        tono="neutro"
                                        :icono="ClipboardCheck"
                                    />
                                    <ChipEstado
                                        :etiqueta="`Aprueba con ${m.calificacion_minima}/10`"
                                        tono="neutro"
                                    />
                                    <ChipEstado
                                        v-if="m.contenido_url"
                                        etiqueta="Con material"
                                        tono="oro"
                                        :icono="PlayCircle"
                                    />
                                </template>
                                <ChipEstado
                                    v-if="!m.activo"
                                    etiqueta="Inactivo"
                                    tono="peligro"
                                />
                            </div>
                        </button>
                        <div
                            class="flex flex-wrap gap-1 border-t border-border/50 px-3 py-2"
                        >
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="vistaDe(m)"
                                ><Eye class="size-4" /> Vista previa</Button
                            >
                            <template v-if="m.evaluacion_formal_clave">
                                <Button as-child variant="ghost" size="sm">
                                    <Link :href="rutas.induccion_institucional"
                                        ><PlayCircle class="size-4" /> PDF y
                                        video</Link
                                    >
                                </Button>
                                <Button
                                    v-if="evaluacionFormal.configurar_url"
                                    as-child
                                    variant="ghost"
                                    size="sm"
                                >
                                    <Link
                                        :href="evaluacionFormal.configurar_url"
                                        ><ExternalLink class="size-4" />
                                        Configurar evaluación</Link
                                    >
                                </Button>
                                <Button
                                    v-else
                                    as-child
                                    variant="ghost"
                                    size="sm"
                                >
                                    <Link :href="vistaPreviaEvaluacion()"
                                        ><ClipboardCheck class="size-4" /> Ver
                                        evaluación</Link
                                    >
                                </Button>
                            </template>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <!-- Activos con responsiva -->
        <section data-tour="onboarding-activos" class="flex flex-col gap-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">
                        Activos que se entregan con responsiva
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Uniforme, equipo o material que el jefe entrega en
                        persona. Cada entrega genera su carta responsiva; los
                        obligatorios deben entregarse para cerrar el onboarding.
                    </p>
                </div>
                <Button variant="outline" @click="editarActivo(null)"
                    ><Plus class="size-4" /> Activo</Button
                >
            </div>

            <form
                v-if="editandoActivo !== null"
                class="flex flex-col gap-4 rounded-3xl border-2 border-primary/30 bg-card p-5 shadow-sm"
                @submit.prevent="guardarActivo"
            >
                <h3 class="font-semibold">
                    {{
                        editandoActivo === 'nuevo'
                            ? 'Nuevo activo'
                            : 'Editar activo'
                    }}
                </h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="a-nombre">Nombre</Label>
                        <Input
                            id="a-nombre"
                            v-model="activo.nombre"
                            placeholder="Ej. Uniforme, Celular, Motocicleta"
                        />
                        <InputError :message="activo.errors.nombre" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="a-clave">Clave interna</Label>
                        <Input
                            id="a-clave"
                            v-model="activo.clave"
                            placeholder="uniforme"
                        />
                        <p class="text-xs text-muted-foreground">
                            Una palabra en minúsculas, sin espacios. No se
                            muestra a la persona.
                        </p>
                        <InputError :message="activo.errors.clave" />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"
                    ><Casilla v-model="activo.requiere_identificador" /> Pide
                    número de serie o identificador al entregarlo</label
                >
                <label class="flex items-center gap-2 text-sm"
                    ><Casilla v-model="activo.obligatorio" /> Obligatorio para
                    cerrar el onboarding</label
                >
                <label class="flex items-center gap-2 text-sm"
                    ><Casilla v-model="activo.activo" /> Activo</label
                >
                <fieldset class="flex flex-col gap-1.5 text-sm">
                    <legend class="mb-1 font-medium">
                        ¿A qué puestos se entrega? (sin marcar = a todos)
                    </legend>
                    <div
                        class="grid max-h-56 gap-1 overflow-y-auto sm:grid-cols-2 xl:grid-cols-3"
                    >
                        <label
                            v-for="p in puestos"
                            :key="p.id"
                            class="flex items-center gap-2"
                            ><Casilla
                                v-model="activo.puesto_ids"
                                :value="p.id"
                            />
                            {{ p.nombre }}</label
                        >
                    </div>
                </fieldset>
                <div class="flex gap-2">
                    <Button type="submit" :disabled="activo.processing"
                        >Guardar</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        @click="editandoActivo = null"
                        >Cancelar</Button
                    >
                </div>
            </form>

            <p
                v-if="!tiposActivo.length"
                class="rounded-2xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
            >
                Todavía no hay activos configurados. Agrega el primero con
                «Activo».
            </p>
            <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <button
                    v-for="t in tiposActivo"
                    :key="t.id"
                    type="button"
                    class="flex tarjeta-interactiva items-start gap-3 rounded-2xl border border-border/60 bg-card p-4 text-left shadow-sm"
                    :class="t.activo ? '' : 'opacity-60'"
                    @click="editarActivo(t)"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-crema text-bronce"
                    >
                        <Package class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">{{ t.nombre }}</p>
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <ChipEstado
                                :etiqueta="
                                    t.obligatorio ? 'Obligatorio' : 'Opcional'
                                "
                                :tono="t.obligatorio ? 'alerta' : 'neutro'"
                            />
                            <ChipEstado
                                v-if="t.requiere_identificador"
                                etiqueta="Con serie"
                                tono="neutro"
                            />
                            <ChipEstado
                                :etiqueta="
                                    t.puesto_ids?.length
                                        ? `${t.puesto_ids.length} puesto(s)`
                                        : 'Todos los puestos'
                                "
                                tono="neutro"
                            />
                            <ChipEstado
                                v-if="!t.activo"
                                etiqueta="Inactivo"
                                tono="peligro"
                            />
                        </div>
                    </div>
                </button>
            </div>
        </section>
    </div>

    <!-- Vista previa: lección -->
    <Dialog
        :open="vistaPrevia !== null"
        @update:open="(v: boolean) => !v && (vistaPrevia = null)"
    >
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle
                    >Vista previa — así lo verá el colaborador</DialogTitle
                >
                <DialogDescription>
                    Puedes marcar respuestas para probarla; no se envía ni se
                    guarda nada. Las opciones vacías no se publican.
                </DialogDescription>
            </DialogHeader>
            <article
                v-if="vistaPrevia"
                class="rounded-2xl border border-primary/40 p-4"
            >
                <div class="flex items-center gap-3">
                    <PlayCircle class="size-5 shrink-0 text-primary" />
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{
                            vistaPrevia.titulo
                        }}</span>
                        <span class="block text-xs text-muted-foreground"
                            >Lista para responder</span
                        >
                    </span>
                </div>
                <div class="mt-4 border-t border-border/60 pt-4">
                    <LeccionOnboardingContenido
                        v-model:respuestas="respuestasPrueba"
                        :leccion="vistaPrevia"
                        grupo="vista-previa"
                        vista-previa
                    />
                </div>
            </article>
        </DialogContent>
    </Dialog>

    <!-- Vista previa: Inducción Mr. Lana (capítulos legibles + vista real) -->
    <Dialog
        :open="vistaFormal !== null"
        @update:open="(v: boolean) => !v && (vistaFormal = null)"
    >
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle
                    >Vista previa — {{ vistaFormal?.titulo }}</DialogTitle
                >
                <DialogDescription>
                    La persona la ve en el PDF oficial con su video/audio y la
                    evalúa con el
                    {{ evaluacionFormal.codigo }} ({{
                        evaluacionFormal.total_puntos
                    }}
                    puntos, aprueba con
                    {{ evaluacionFormal.calificacion_aprobatoria }}). Abajo, su
                    contenido por capítulos.
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-wrap gap-2">
                <Button as-child>
                    <Link :href="rutas.vista_colaborador"
                        ><Eye class="size-4" /> Ver exactamente lo que ve el
                        colaborador</Link
                    >
                </Button>
                <Button as-child variant="outline">
                    <Link :href="vistaPreviaEvaluacion()"
                        ><ClipboardCheck class="size-4" /> Ver la
                        evaluación</Link
                    >
                </Button>
            </div>
            <CapitulosInduccionVista
                v-if="vistaFormal"
                :capitulos="vistaFormal.capitulos"
            />
        </DialogContent>
    </Dialog>
</template>
