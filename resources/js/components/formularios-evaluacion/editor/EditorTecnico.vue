<script setup lang="ts">
import {
    AlignLeft,
    ArrowDown,
    ArrowUp,
    CheckCircle2,
    CheckSquare,
    CircleDot,
    Copy,
    Gauge,
    Info,
    Layers,
    Minus,
    Plus,
    Text,
    ToggleLeft,
    Trash2,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import RadioMarca from '@/components/Common/RadioMarca.vue';
import TarjetaPregunta from '@/components/formularios-evaluacion/editor/TarjetaPregunta.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Textarea } from '@/components/ui/textarea';
import {
    claveNueva,
    duplicarEn,
    moverEn,
    puntosPreguntaTecnica,
} from '@/lib/formulariosEvaluacion';
import { cn } from '@/lib/utils';
import type {
    DefinicionTecnica,
    PreguntaTecnica,
    SeccionTecnica,
    TipoPreguntaTecnica,
} from '@/types';

/**
 * Editor del formulario técnico («Seguimiento N»), libre como Google Forms:
 * secciones → preguntas de seis tipos, con respuesta correcta y puntaje.
 * El servidor (DefinicionFormularioTecnicoService) vuelve a limpiar y valida
 * todo al guardar y publicar; aquí solo se recalculan los totales locales.
 */
const props = defineProps<{ soloLectura?: boolean }>();
const d = defineModel<DefinicionTecnica>({ required: true });

const TIPOS: {
    valor: TipoPreguntaTecnica;
    texto: string;
    icono: Component;
}[] = [
    { valor: 'opcion_multiple', texto: 'Opción múltiple', icono: CircleDot },
    { valor: 'casillas', texto: 'Casillas', icono: CheckSquare },
    { valor: 'verdadero_falso', texto: 'Verdadero o falso', icono: ToggleLeft },
    { valor: 'escala', texto: 'Escala lineal', icono: Gauge },
    { valor: 'respuesta_corta', texto: 'Respuesta corta', icono: Text },
    { valor: 'parrafo', texto: 'Párrafo', icono: AlignLeft },
];

function tipoDe(valor: TipoPreguntaTecnica) {
    return TIPOS.find((t) => t.valor === valor) ?? TIPOS[0];
}

const OPCIONES_VF = [
    { clave: 'verdadero', texto: 'Verdadero' },
    { clave: 'falso', texto: 'Falso' },
];

const activa = ref<string | null>(null);

// ---- Totales locales (el servidor los recalcula al guardar)
watch(
    () => d.value.secciones,
    (secciones) => {
        const preguntas = secciones.flatMap((s) => s.preguntas);
        d.value.total_preguntas = preguntas.length;
        d.value.total_puntos = preguntas.reduce(
            (suma, p) => suma + puntosPreguntaTecnica(p),
            0,
        );
    },
    { deep: true, immediate: true },
);

const inicioSeccion = computed(() => {
    let acumulado = 0;

    return d.value.secciones.map((s) => {
        const inicio = acumulado;
        acumulado += s.preguntas.length;

        return inicio;
    });
});

// ---- Secciones
function nuevaPregunta(): PreguntaTecnica {
    return {
        clave: claveNueva('p'),
        tipo: 'opcion_multiple',
        enunciado: '',
        ayuda: '',
        obligatoria: true,
        puntos: 10,
        opciones: [
            { clave: claveNueva('o'), texto: 'Opción 1' },
            { clave: claveNueva('o'), texto: 'Opción 2' },
        ],
        correctas: [],
        escala_min: 1,
        escala_max: 5,
        etiqueta_min: '',
        etiqueta_max: '',
    };
}

function agregarSeccion(): void {
    d.value.secciones.push({
        clave: claveNueva('s'),
        titulo: `Sección ${d.value.secciones.length + 1}`,
        descripcion: '',
        preguntas: [nuevaPregunta()],
    });
    const s = d.value.secciones[d.value.secciones.length - 1];
    activa.value = s.preguntas[0].clave;
}

function moverSeccion(i: number, delta: number): void {
    moverEn(d.value.secciones, i, delta);
}

function duplicarSeccion(i: number): void {
    duplicarEn(d.value.secciones, i, (copia: SeccionTecnica) => ({
        ...copia,
        clave: claveNueva('s'),
        titulo: copia.titulo ? `${copia.titulo} (copia)` : '',
        preguntas: copia.preguntas.map((p) => conClavesNuevas(p)),
    }));
}

function eliminarSeccion(i: number): void {
    d.value.secciones.splice(i, 1);
}

// ---- Preguntas
function conClavesNuevas(p: PreguntaTecnica): PreguntaTecnica {
    const mapa = new Map<string, string>();
    const opciones =
        p.tipo === 'verdadero_falso'
            ? p.opciones
            : p.opciones.map((o) => {
                  const clave = claveNueva('o');
                  mapa.set(o.clave, clave);

                  return { ...o, clave };
              });

    return {
        ...p,
        clave: claveNueva('p'),
        opciones,
        correctas: (p.correctas ?? []).map((c) => mapa.get(c) ?? c),
    };
}

function agregarPregunta(s: SeccionTecnica): void {
    const p = nuevaPregunta();
    s.preguntas.push(p);
    activa.value = p.clave;
}

function moverPregunta(s: SeccionTecnica, i: number, delta: number): void {
    moverEn(s.preguntas, i, delta);
}

function duplicarPregunta(s: SeccionTecnica, i: number): void {
    const nuevo = duplicarEn(s.preguntas, i, conClavesNuevas);
    activa.value = s.preguntas[nuevo].clave;
}

function eliminarPregunta(s: SeccionTecnica, i: number): void {
    s.preguntas.splice(i, 1);
    activa.value = s.preguntas[Math.max(0, i - 1)]?.clave ?? null;
}

function cambiarTipo(p: PreguntaTecnica, tipo: TipoPreguntaTecnica): void {
    const antes = p.tipo;
    p.tipo = tipo;

    if (tipo === 'verdadero_falso') {
        p.opciones = OPCIONES_VF.map((o) => ({ ...o }));
        p.correctas = [];
    } else if (tipo === 'opcion_multiple' || tipo === 'casillas') {
        if (antes === 'verdadero_falso' || p.opciones.length === 0) {
            p.opciones = [
                { clave: claveNueva('o'), texto: 'Opción 1' },
                { clave: claveNueva('o'), texto: 'Opción 2' },
            ];
            p.correctas = [];
        } else if (tipo === 'opcion_multiple') {
            p.correctas = (p.correctas ?? []).slice(0, 1);
        }
    } else {
        p.opciones = [];
        p.correctas = [];
    }

    if (tipo === 'respuesta_corta' || tipo === 'parrafo') {
        p.puntos = 0;
    } else if (p.puntos === 0 && (antes === 'respuesta_corta' || antes === 'parrafo')) {
        p.puntos = 10;
    }
}

function esAbierta(p: PreguntaTecnica): boolean {
    return p.tipo === 'respuesta_corta' || p.tipo === 'parrafo';
}

function esCorrecta(p: PreguntaTecnica, clave: string): boolean {
    return (p.correctas ?? []).includes(clave);
}

function marcarCorrecta(p: PreguntaTecnica, clave: string, marcar = true) {
    if (p.tipo === 'casillas') {
        const resto = (p.correctas ?? []).filter((c) => c !== clave);
        p.correctas = marcar ? [...resto, clave] : resto;
    } else {
        p.correctas = [clave];
    }
}

function agregarOpcion(p: PreguntaTecnica): void {
    p.opciones.push({
        clave: claveNueva('o'),
        texto: `Opción ${p.opciones.length + 1}`,
    });
}

function quitarOpcion(p: PreguntaTecnica, i: number): void {
    const [quitada] = p.opciones.splice(i, 1);
    p.correctas = (p.correctas ?? []).filter((c) => c !== quitada?.clave);
}

function limitar(valor: unknown, min: number, max: number): number {
    const n = Math.round(Number(valor));

    return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : min;
}

function sinCorrecta(p: PreguntaTecnica): boolean {
    return (
        p.puntos > 0 &&
        ['opcion_multiple', 'casillas', 'verdadero_falso'].includes(p.tipo) &&
        (p.correctas ?? []).length === 0
    );
}

function textoCorrectas(p: PreguntaTecnica): string {
    const textos = p.opciones
        .filter((o) => esCorrecta(p, o.clave))
        .map((o) => o.texto);

    return textos.join(' · ');
}

const campo =
    'w-full rounded-lg border border-transparent bg-transparent px-2 py-1 outline-none hover:border-border focus:border-[var(--acento)] focus:bg-background disabled:hover:border-transparent';
</script>

<template>
    <div class="flex flex-col gap-8">
        <!-- Reglas -->
        <section
            class="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-xs sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
        >
            <div class="grid gap-1.5">
                <Label for="tecnica-aprobatoria"
                    >Calificación aprobatoria sugerida</Label
                >
                <div class="flex items-center gap-3">
                    <Input
                        id="tecnica-aprobatoria"
                        :model-value="d.calificacion_aprobatoria"
                        type="number"
                        min="0"
                        max="100"
                        class="w-28"
                        :disabled="props.soloLectura"
                        @update:model-value="
                            d.calificacion_aprobatoria = limitar($event, 0, 100)
                        "
                    />
                    <span class="text-sm text-muted-foreground">% de los puntos</span>
                </div>
                <span
                    class="mt-1 block h-2 overflow-hidden rounded-full bg-muted"
                    aria-hidden="true"
                >
                    <span
                        class="block h-full rounded-full bg-[var(--acento)] transition-[width] duration-500 ease-out"
                        :style="{ width: `${d.calificacion_aprobatoria}%` }"
                    />
                </span>
                <p class="text-xs text-muted-foreground">
                    Es la sugerencia del formulario: la mínima real la fija
                    cada prueba en el calendario del puesto.
                </p>
            </div>
            <div class="flex flex-col gap-3">
                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        :model-value="d.mostrar_resultado"
                        :disabled="props.soloLectura"
                        class="mt-0.5"
                        @update:model-value="
                            (v) => (d.mostrar_resultado = v === true)
                        "
                    />
                    <span
                        >Mostrar la calificación a quien contesta al terminar
                        <span class="block text-xs text-muted-foreground"
                            >Si lo apagas, el colaborador solo ve
                            «Contestada»; RH y su jefe sí ven el
                            resultado.</span
                        ></span
                    >
                </label>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span
                        class="rounded-full bg-[color-mix(in_oklab,var(--acento)_14%,transparent)] px-3 py-1 font-medium tabular-nums"
                        >{{ d.secciones.length }}
                        {{ d.secciones.length === 1 ? 'sección' : 'secciones' }}</span
                    >
                    <span
                        class="rounded-full bg-[color-mix(in_oklab,var(--acento)_14%,transparent)] px-3 py-1 font-medium tabular-nums"
                        >{{ d.total_preguntas }}
                        {{ d.total_preguntas === 1 ? 'pregunta' : 'preguntas' }}</span
                    >
                    <span
                        class="rounded-full bg-[color-mix(in_oklab,var(--acento)_14%,transparent)] px-3 py-1 font-medium tabular-nums"
                        >{{ d.total_puntos }} puntos</span
                    >
                </div>
            </div>
        </section>

        <!-- Secciones -->
        <ListaAnimada
            tag="div"
            class="flex flex-col gap-8"
            :cantidad="d.secciones.length"
        >
            <section
                v-for="(s, si) in d.secciones"
                :key="s.clave"
                class="flex flex-col gap-3"
            >
                <header
                    class="flex flex-col gap-2 overflow-hidden rounded-2xl border border-border bg-card shadow-xs"
                >
                    <div
                        class="flex items-center gap-2 bg-[color-mix(in_oklab,var(--acento)_12%,transparent)] px-4 py-2"
                    >
                        <span
                            class="flex size-7 items-center justify-center rounded-full bg-[var(--acento)] text-xs font-bold text-[var(--acento-texto)]"
                            >{{ si + 1 }}</span
                        >
                        <span
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >Sección {{ si + 1 }} de {{ d.secciones.length }}</span
                        >
                        <span
                            v-if="!props.soloLectura"
                            class="ml-auto flex items-center gap-0.5"
                        >
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                title="Subir sección"
                                aria-label="Subir sección"
                                :disabled="si === 0"
                                @click="moverSeccion(si, -1)"
                            >
                                <ArrowUp class="size-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                title="Bajar sección"
                                aria-label="Bajar sección"
                                :disabled="si === d.secciones.length - 1"
                                @click="moverSeccion(si, 1)"
                            >
                                <ArrowDown class="size-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                title="Duplicar sección"
                                aria-label="Duplicar sección"
                                @click="duplicarSeccion(si)"
                            >
                                <Copy class="size-4" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                title="Eliminar sección"
                                aria-label="Eliminar sección"
                                class="text-destructive hover:text-destructive"
                                :disabled="d.secciones.length === 1"
                                @click="eliminarSeccion(si)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </span>
                    </div>
                    <div class="flex flex-col gap-1 px-3 pb-3">
                        <input
                            v-model="s.titulo"
                            :class="[campo, 'text-lg font-semibold']"
                            placeholder="Título de la sección"
                            maxlength="200"
                            :disabled="props.soloLectura"
                            aria-label="Título de la sección"
                        />
                        <textarea
                            v-model="s.descripcion"
                            rows="1"
                            :class="[campo, 'resize-y text-sm text-muted-foreground']"
                            placeholder="Instrucciones de la sección (opcional)"
                            maxlength="2000"
                            :disabled="props.soloLectura"
                            aria-label="Descripción de la sección"
                        />
                    </div>
                </header>

                <ListaAnimada
                    tag="div"
                    class="flex flex-col gap-3"
                    :cantidad="s.preguntas.length"
                >
                    <TarjetaPregunta
                        v-for="(p, pi) in s.preguntas"
                        :key="p.clave"
                        :activa="activa === p.clave"
                        :etiqueta="`Pregunta ${inicioSeccion[si] + pi + 1} · ${tipoDe(p.tipo).texto}`"
                        :solo-lectura="props.soloLectura"
                        :puede-subir="pi > 0"
                        :puede-bajar="pi < s.preguntas.length - 1"
                        @activar="activa = p.clave"
                        @subir="moverPregunta(s, pi, -1)"
                        @bajar="moverPregunta(s, pi, 1)"
                        @duplicar="duplicarPregunta(s, pi)"
                        @eliminar="eliminarPregunta(s, pi)"
                    >
                        <template #resumen>
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-[15px] font-medium text-pretty">
                                    {{ p.enunciado || 'Pregunta sin texto' }}
                                    <span
                                        v-if="p.obligatoria"
                                        class="text-[var(--mrl-danger)]"
                                        >*</span
                                    >
                                </p>
                                <span
                                    class="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground"
                                >
                                    <component
                                        :is="tipoDe(p.tipo).icono"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    <span
                                        v-if="puntosPreguntaTecnica(p) > 0"
                                        class="rounded-full bg-muted px-2 py-0.5 font-medium tabular-nums"
                                        >{{ p.puntos }} pts</span
                                    >
                                </span>
                            </div>
                            <p
                                v-if="textoCorrectas(p)"
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <CheckCircle2
                                    class="size-3.5 text-[var(--mrl-success)]"
                                />
                                {{ textoCorrectas(p) }}
                            </p>
                            <p
                                v-else-if="sinCorrecta(p)"
                                class="flex items-center gap-1.5 text-xs font-medium text-[var(--mrl-danger)]"
                            >
                                <Info class="size-3.5" /> Falta marcar la
                                respuesta correcta
                            </p>
                        </template>

                        <!-- Edición -->
                        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_220px]">
                            <Textarea
                                v-model="p.enunciado"
                                rows="2"
                                placeholder="Escribe la pregunta"
                                maxlength="1000"
                                :disabled="props.soloLectura"
                                aria-label="Pregunta"
                            />
                            <div
                                class="grid grid-cols-3 gap-1 self-start rounded-xl border border-border p-1 lg:grid-cols-2"
                                role="radiogroup"
                                aria-label="Tipo de pregunta"
                            >
                                <button
                                    v-for="t in TIPOS"
                                    :key="t.valor"
                                    type="button"
                                    role="radio"
                                    :aria-checked="p.tipo === t.valor"
                                    :title="t.texto"
                                    :disabled="props.soloLectura"
                                    :class="
                                        cn(
                                            'flex min-h-9 presionable items-center gap-1.5 rounded-lg px-2 py-1.5 text-left text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                            p.tipo === t.valor
                                                ? 'bg-[var(--acento)] text-[var(--acento-texto)] shadow-sm'
                                                : 'text-muted-foreground hover:bg-muted/50',
                                        )
                                    "
                                    @click="cambiarTipo(p, t.valor)"
                                >
                                    <component
                                        :is="t.icono"
                                        class="size-3.5 shrink-0"
                                    />
                                    <span class="truncate">{{ t.texto }}</span>
                                </button>
                            </div>
                        </div>
                        <Input
                            v-model="p.ayuda"
                            placeholder="Ayuda o aclaración (opcional)"
                            maxlength="1000"
                            :disabled="props.soloLectura"
                        />

                        <!-- Opciones -->
                        <div
                            v-if="
                                p.tipo === 'opcion_multiple' ||
                                p.tipo === 'casillas' ||
                                p.tipo === 'verdadero_falso'
                            "
                            class="flex flex-col gap-1.5"
                        >
                            <p class="text-xs font-medium text-muted-foreground">
                                {{
                                    p.tipo === 'casillas'
                                        ? 'Opciones · marca TODAS las correctas'
                                        : 'Opciones · marca la correcta'
                                }}
                            </p>
                            <div
                                v-for="(o, oi) in p.opciones"
                                :key="o.clave"
                                :class="
                                    cn(
                                        'flex items-center gap-2 rounded-xl border px-2.5 py-1.5 transition-colors',
                                        esCorrecta(p, o.clave)
                                            ? 'border-[color-mix(in_oklab,var(--mrl-success)_45%,transparent)] bg-success-soft'
                                            : 'border-transparent hover:border-border',
                                    )
                                "
                            >
                                <Checkbox
                                    v-if="p.tipo === 'casillas'"
                                    :model-value="esCorrecta(p, o.clave)"
                                    :disabled="props.soloLectura"
                                    :aria-label="`Marcar «${o.texto}» como correcta`"
                                    @update:model-value="
                                        marcarCorrecta(p, o.clave, $event === true)
                                    "
                                />
                                <RadioMarca
                                    v-else
                                    :model-value="(p.correctas ?? [])[0] ?? null"
                                    :value="o.clave"
                                    :name="`correcta-${p.clave}`"
                                    :disabled="props.soloLectura"
                                    @update:model-value="marcarCorrecta(p, o.clave)"
                                />
                                <input
                                    v-if="p.tipo !== 'verdadero_falso'"
                                    v-model="o.texto"
                                    :class="[campo, 'text-sm']"
                                    :placeholder="`Opción ${oi + 1}`"
                                    maxlength="500"
                                    :disabled="props.soloLectura"
                                    :aria-label="`Texto de la opción ${oi + 1}`"
                                />
                                <span v-else class="flex-1 px-2 py-1 text-sm">{{
                                    o.texto
                                }}</span>
                                <span
                                    v-if="esCorrecta(p, o.clave)"
                                    class="shrink-0 aparece-zoom text-xs font-medium text-[var(--mrl-success)]"
                                    >Correcta</span
                                >
                                <Button
                                    v-if="
                                        p.tipo !== 'verdadero_falso' &&
                                        !props.soloLectura
                                    "
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-8 shrink-0"
                                    title="Quitar opción"
                                    aria-label="Quitar opción"
                                    :disabled="p.opciones.length <= 2"
                                    @click="quitarOpcion(p, oi)"
                                >
                                    <X class="size-4" />
                                </Button>
                            </div>
                            <Button
                                v-if="
                                    p.tipo !== 'verdadero_falso' &&
                                    !props.soloLectura &&
                                    p.opciones.length < 12
                                "
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="w-fit"
                                @click="agregarOpcion(p)"
                            >
                                <Plus class="size-4" /> Agregar opción
                            </Button>
                        </div>

                        <!-- Escala -->
                        <div
                            v-else-if="p.tipo === 'escala'"
                            class="grid gap-3 rounded-xl bg-muted/30 p-3 sm:grid-cols-2"
                        >
                            <div class="flex items-center gap-2 text-sm">
                                <span class="text-muted-foreground">De</span>
                                <div class="flex rounded-lg border border-border bg-card p-0.5">
                                    <button
                                        v-for="n in [0, 1]"
                                        :key="n"
                                        type="button"
                                        :disabled="props.soloLectura"
                                        :class="
                                            cn(
                                                'min-w-8 rounded-md px-2 py-1 text-sm font-semibold tabular-nums',
                                                p.escala_min === n
                                                    ? 'bg-[var(--acento)] text-[var(--acento-texto)]'
                                                    : 'text-muted-foreground',
                                            )
                                        "
                                        @click="p.escala_min = n"
                                    >
                                        {{ n }}
                                    </button>
                                </div>
                                <span class="text-muted-foreground">a</span>
                                <div class="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-8"
                                        aria-label="Bajar máximo"
                                        :disabled="props.soloLectura || p.escala_max <= 2"
                                        @click="p.escala_max = limitar(p.escala_max - 1, 2, 10)"
                                    >
                                        <Minus class="size-3.5" />
                                    </Button>
                                    <span
                                        class="w-8 text-center font-semibold tabular-nums"
                                        >{{ p.escala_max }}</span
                                    >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-8"
                                        aria-label="Subir máximo"
                                        :disabled="props.soloLectura || p.escala_max >= 10"
                                        @click="p.escala_max = limitar(p.escala_max + 1, 2, 10)"
                                    >
                                        <Plus class="size-3.5" />
                                    </Button>
                                </div>
                            </div>
                            <p class="text-xs text-muted-foreground sm:text-right">
                                Da puntos proporcionales: el máximo vale todos
                                los puntos, el mínimo vale cero.
                            </p>
                            <Input
                                v-model="p.etiqueta_min"
                                :placeholder="`Etiqueta del ${p.escala_min} (p. ej. Nunca)`"
                                maxlength="60"
                                :disabled="props.soloLectura"
                            />
                            <Input
                                v-model="p.etiqueta_max"
                                :placeholder="`Etiqueta del ${p.escala_max} (p. ej. Siempre)`"
                                maxlength="60"
                                :disabled="props.soloLectura"
                            />
                        </div>

                        <p
                            v-else
                            class="flex items-start gap-1.5 rounded-xl bg-muted/30 px-3 py-2 text-xs text-muted-foreground"
                        >
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            Las respuestas abiertas no suman puntos: quedan
                            como evidencia para RH y el jefe directo.
                        </p>

                        <template #pie>
                            <label
                                class="mr-auto flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    :model-value="p.obligatoria"
                                    @update:model-value="
                                        (v) => (p.obligatoria = v === true)
                                    "
                                />
                                Obligatoria
                            </label>
                            <label
                                v-if="!esAbierta(p)"
                                class="mr-2 flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                Puntos
                                <Input
                                    :model-value="p.puntos"
                                    type="number"
                                    min="0"
                                    max="100"
                                    class="h-8 w-20"
                                    @update:model-value="
                                        p.puntos = limitar($event, 0, 100)
                                    "
                                />
                            </label>
                        </template>
                    </TarjetaPregunta>
                </ListaAnimada>

                <Button
                    v-if="!props.soloLectura"
                    type="button"
                    variant="outline"
                    class="w-fit border-dashed"
                    @click="agregarPregunta(s)"
                >
                    <Plus class="size-4" /> Agregar pregunta
                </Button>
            </section>
        </ListaAnimada>

        <Button
            v-if="!props.soloLectura && d.secciones.length < 20"
            type="button"
            variant="outline"
            class="w-full border-dashed py-6"
            @click="agregarSeccion"
        >
            <Layers class="size-4" /> Agregar sección
        </Button>
    </div>
</template>
