<script setup lang="ts">
import { Check, Info, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import TarjetaPregunta from '@/components/formularios-evaluacion/editor/TarjetaPregunta.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    claveNueva,
    duplicarEn,
    letraDe,
    moverEn,
    sumaPuntos,
    totalInduccion,
} from '@/lib/formulariosEvaluacion';
import { cn } from '@/lib/utils';
import type { DefinicionInduccion, SeccionInduccionClave } from '@/types';

/**
 * Editor de la evaluación de inducción (tipo Google Forms). Las cinco
 * secciones A–E son FIJAS (la app instalada y el PDF las esperan): dentro de
 * cada una se agregan, quitan, reordenan y editan preguntas.
 */
const props = defineProps<{ soloLectura?: boolean }>();
const d = defineModel<DefinicionInduccion>({ required: true });

const activa = ref<string | null>(null);

function activar(clave: string): void {
    activa.value = clave;
}

const total = computed(() => totalInduccion(d.value));

const SECCIONES: {
    clave: SeccionInduccionClave;
    tipo: string;
    ayuda: string;
}[] = [
    {
        clave: 'a',
        tipo: 'Opción múltiple',
        ayuda: 'Marca con el círculo la respuesta correcta. El sistema la califica solo.',
    },
    {
        clave: 'b',
        tipo: 'Verdadero o falso',
        ayuda: 'Elige si la afirmación es verdadera o falsa. Se califica sola.',
    },
    {
        clave: 'c',
        tipo: 'Relacionar columnas',
        ayuda: 'Primero escribe las descripciones (A, B, C…); luego a cada concepto elige la letra que le corresponde.',
    },
    {
        clave: 'd',
        tipo: 'Casos prácticos',
        ayuda: 'El colaborador responde con sus palabras y Recursos Humanos lo califica con la rúbrica. La rúbrica nunca la ve el colaborador.',
    },
    {
        clave: 'e',
        tipo: 'Escala de opinión (no califica)',
        ayuda: 'El colaborador marca del 1 al 5. Sirve para mejorar la inducción; no suma puntos.',
    },
];

function puntosSeccion(clave: SeccionInduccionClave): number | null {
    switch (clave) {
        case 'a':
            return sumaPuntos(d.value.seccion_a);
        case 'b':
            return sumaPuntos(d.value.seccion_b);
        case 'c':
            return sumaPuntos(d.value.seccion_c.conceptos);
        case 'd':
            return d.value.seccion_d.reduce(
                (s, c) => s + sumaPuntos(c.rubrica),
                0,
            );
        default:
            return null;
    }
}

// ---- Sección A
function agregarA(): void {
    d.value.seccion_a.push({
        pregunta: '',
        opciones: ['', ''],
        correcta: 0,
        puntos: 1,
    });
    activar(`a-${d.value.seccion_a.length - 1}`);
}

function quitarOpcion(i: number, o: number): void {
    const p = d.value.seccion_a[i];
    p.opciones.splice(o, 1);

    if (p.correcta === o) {
        p.correcta = 0;
    } else if (p.correcta > o) {
        p.correcta--;
    }
}

// ---- Sección B
function agregarB(): void {
    d.value.seccion_b.push({ afirmacion: '', correcto: true, puntos: 1 });
    activar(`b-${d.value.seccion_b.length - 1}`);
}

// ---- Sección C: descripciones como lista ordenada (las letras se recalculan)
const descripciones = computed(() =>
    Object.values(d.value.seccion_c.descripciones),
);

function guardarDescripciones(lista: string[], mapa: Record<string, string>) {
    d.value.seccion_c.descripciones = Object.fromEntries(
        lista.map((texto, i) => [letraDe(i), texto]),
    );
    d.value.seccion_c.conceptos.forEach((c) => {
        c.letra_correcta = mapa[c.letra_correcta] ?? '';
    });
}

function editarDescripcion(i: number, texto: string): void {
    d.value.seccion_c.descripciones[letraDe(i)] = texto;
}

function agregarDescripcion(): void {
    if (descripciones.value.length >= 10) {
        return;
    }

    d.value.seccion_c.descripciones[letraDe(descripciones.value.length)] = '';
}

function quitarDescripcion(i: number): void {
    const lista = [...descripciones.value];
    lista.splice(i, 1);
    const mapa: Record<string, string> = {};

    descripciones.value.forEach((_, j) => {
        if (j !== i) {
            mapa[letraDe(j)] = letraDe(j < i ? j : j - 1);
        }
    });
    guardarDescripciones(lista, mapa);
}

function moverDescripcion(i: number, delta: number): void {
    const lista = [...descripciones.value];
    const destino = moverEn(lista, i, delta);

    if (destino === i) {
        return;
    }

    const mapa: Record<string, string> = {};
    descripciones.value.forEach((_, j) => {
        mapa[letraDe(j)] = letraDe(j);
    });
    mapa[letraDe(i)] = letraDe(destino);
    mapa[letraDe(destino)] = letraDe(i);
    guardarDescripciones(lista, mapa);
}

function agregarC(): void {
    d.value.seccion_c.conceptos.push({
        concepto: '',
        letra_correcta: '',
        puntos: 1,
    });
    activar(`c-${d.value.seccion_c.conceptos.length - 1}`);
}

// ---- Sección D
function agregarD(): void {
    d.value.seccion_d.push({
        clave: claveNueva('caso'),
        puntos_max: 1,
        enunciado: '',
        rubrica: [{ clave: claveNueva('criterio'), puntos: 1, criterio: '' }],
    });
    activar(`d-${d.value.seccion_d.length - 1}`);
}

// ---- Sección E
function agregarE(): void {
    d.value.seccion_e.aspectos.push({
        clave: claveNueva('aspecto'),
        texto: '',
    });
    activar(`e-${d.value.seccion_e.aspectos.length - 1}`);
}

function eliminar<T>(lista: T[], i: number, prefijo: string): void {
    lista.splice(i, 1);
    activa.value = lista.length ? `${prefijo}-${Math.max(0, i - 1)}` : null;
}

function mover<T>(lista: T[], i: number, delta: number, prefijo: string) {
    activa.value = `${prefijo}-${moverEn(lista, i, delta)}`;
}

function duplicar<T>(
    lista: T[],
    i: number,
    prefijo: string,
    ajustar?: (c: T) => T,
) {
    activa.value = `${prefijo}-${duplicarEn(lista, i, ajustar)}`;
}

const campoTitulo =
    'w-full rounded-lg border border-transparent bg-transparent px-2 py-1 font-semibold outline-none hover:border-border focus:border-[var(--acento)] focus:bg-background disabled:hover:border-transparent';
const campoTexto =
    'w-full rounded-lg border border-transparent bg-transparent px-2 py-1 text-sm text-muted-foreground outline-none hover:border-border focus:border-[var(--acento)] focus:bg-background disabled:hover:border-transparent';
</script>

<template>
    <div class="flex flex-col gap-8">
        <!-- Calificación -->
        <section
            class="grid gap-4 rounded-2xl border border-border bg-card p-5 shadow-xs sm:grid-cols-3"
        >
            <div class="grid gap-1.5">
                <Label for="aprobatoria">Calificación mínima aprobatoria</Label>
                <Input
                    id="aprobatoria"
                    v-model.number="d.calificacion_aprobatoria"
                    type="number"
                    min="1"
                    :max="total"
                    :disabled="props.soloLectura"
                />
                <p class="text-xs text-muted-foreground">
                    De {{ total }} puntos en total. Con menos, requiere
                    reforzamiento.
                </p>
            </div>
            <div class="grid gap-1.5">
                <Label for="sobresaliente"
                    >Desde cuánto es «sobresaliente»</Label
                >
                <Input
                    id="sobresaliente"
                    v-model.number="d.calificacion_sobresaliente"
                    type="number"
                    :min="d.calificacion_aprobatoria"
                    :max="total"
                    :disabled="props.soloLectura"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="dias">Días hábiles para reevaluar</Label>
                <Input
                    id="dias"
                    v-model.number="d.dias_habiles_reevaluacion"
                    type="number"
                    min="1"
                    max="30"
                    :disabled="props.soloLectura"
                />
            </div>
        </section>

        <section
            v-for="s in SECCIONES"
            :key="s.clave"
            class="flex flex-col gap-3"
        >
            <!-- Encabezado de la sección (título y descripción editables) -->
            <div
                class="flex items-start gap-3 rounded-2xl border border-border bg-[color-mix(in_oklab,var(--acento)_8%,var(--card))] p-4"
            >
                <span
                    class="mt-1 flex size-9 shrink-0 items-center justify-center rounded-full bg-[var(--acento)] text-sm font-bold text-[var(--acento-texto)]"
                    >{{ s.clave.toUpperCase() }}</span
                >
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <input
                            v-model="d.secciones[s.clave].titulo"
                            :class="[campoTitulo, 'max-w-md text-base']"
                            :disabled="props.soloLectura"
                            aria-label="Título de la sección"
                        />
                        <span
                            class="rounded-full bg-background px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                            >{{ s.tipo }}</span
                        >
                        <span
                            v-if="puntosSeccion(s.clave) !== null"
                            class="rounded-full bg-background px-2.5 py-0.5 text-xs font-semibold"
                            >{{ puntosSeccion(s.clave) }} pts</span
                        >
                    </div>
                    <input
                        v-model="d.secciones[s.clave].descripcion"
                        :class="campoTexto"
                        placeholder="Instrucciones de la sección (opcional)"
                        :disabled="props.soloLectura"
                        aria-label="Instrucciones de la sección"
                    />
                    <p
                        class="flex items-start gap-1.5 px-2 text-xs text-muted-foreground"
                    >
                        <Info class="mt-0.5 size-3.5 shrink-0" />{{ s.ayuda }}
                    </p>
                </div>
            </div>

            <!-- A · Opción múltiple -->
            <template v-if="s.clave === 'a'">
                <TarjetaPregunta
                    v-for="(p, i) in d.seccion_a"
                    :key="`a-${i}`"
                    :activa="activa === `a-${i}`"
                    :etiqueta="`Pregunta ${i + 1} · ${p.puntos} ${p.puntos === 1 ? 'punto' : 'puntos'}`"
                    :solo-lectura="props.soloLectura"
                    :puede-subir="i > 0"
                    :puede-bajar="i < d.seccion_a.length - 1"
                    @activar="activar(`a-${i}`)"
                    @subir="mover(d.seccion_a, i, -1, 'a')"
                    @bajar="mover(d.seccion_a, i, 1, 'a')"
                    @duplicar="duplicar(d.seccion_a, i, 'a')"
                    @eliminar="eliminar(d.seccion_a, i, 'a')"
                >
                    <template #resumen>
                        <p class="text-[15px] font-medium">
                            {{ p.pregunta || 'Pregunta sin texto' }}
                        </p>
                        <ul class="flex flex-col gap-1 text-sm">
                            <li
                                v-for="(o, j) in p.opciones"
                                :key="j"
                                :class="
                                    cn(
                                        'flex items-center gap-2',
                                        j === p.correcta
                                            ? 'font-medium text-[var(--primary)]'
                                            : 'text-muted-foreground',
                                    )
                                "
                            >
                                <Check v-if="j === p.correcta" class="size-4" />
                                <span v-else class="size-4" />
                                {{ o || 'Opción sin texto' }}
                            </li>
                        </ul>
                    </template>
                    <Textarea
                        v-model="p.pregunta"
                        rows="2"
                        placeholder="Escribe la pregunta"
                        :disabled="props.soloLectura"
                    />
                    <div class="flex flex-col gap-1.5">
                        <div
                            v-for="(_, j) in p.opciones"
                            :key="j"
                            class="flex items-center gap-2"
                        >
                            <button
                                type="button"
                                :title="
                                    j === p.correcta
                                        ? 'Respuesta correcta'
                                        : 'Marcar como correcta'
                                "
                                :aria-pressed="j === p.correcta"
                                :disabled="props.soloLectura"
                                :class="
                                    cn(
                                        'flex size-6 shrink-0 items-center justify-center rounded-full border transition-colors',
                                        j === p.correcta
                                            ? 'border-transparent bg-[var(--primary)] text-primary-foreground'
                                            : 'border-input hover:border-[var(--primary)]',
                                    )
                                "
                                @click="p.correcta = j"
                            >
                                <Check
                                    v-if="j === p.correcta"
                                    class="size-3.5"
                                />
                            </button>
                            <Input
                                v-model="p.opciones[j]"
                                :placeholder="`Opción ${j + 1}`"
                                :disabled="props.soloLectura"
                            />
                            <Button
                                v-if="
                                    !props.soloLectura && p.opciones.length > 2
                                "
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Quitar opción"
                                @click="quitarOpcion(i, j)"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>
                        <div
                            v-if="!props.soloLectura"
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <Button
                                v-if="p.opciones.length < 8"
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="p.opciones.push('')"
                            >
                                <Plus class="size-4" /> Agregar opción
                            </Button>
                            <p class="text-xs text-muted-foreground">
                                El círculo marca la respuesta correcta.
                            </p>
                        </div>
                    </div>
                    <template #pie>
                        <label
                            class="mr-auto flex items-center gap-2 text-sm text-muted-foreground"
                            >Puntos
                            <Input
                                v-model.number="p.puntos"
                                type="number"
                                min="1"
                                max="10"
                                class="h-8 w-16"
                        /></label>
                    </template>
                </TarjetaPregunta>
                <Button
                    v-if="!props.soloLectura"
                    type="button"
                    variant="outline"
                    class="w-fit border-dashed"
                    @click="agregarA"
                >
                    <Plus class="size-4" /> Agregar pregunta de opción múltiple
                </Button>
            </template>

            <!-- B · Verdadero o falso -->
            <template v-else-if="s.clave === 'b'">
                <TarjetaPregunta
                    v-for="(p, i) in d.seccion_b"
                    :key="`b-${i}`"
                    :activa="activa === `b-${i}`"
                    :etiqueta="`Afirmación ${i + 1} · ${p.puntos} ${p.puntos === 1 ? 'punto' : 'puntos'}`"
                    :solo-lectura="props.soloLectura"
                    :puede-subir="i > 0"
                    :puede-bajar="i < d.seccion_b.length - 1"
                    @activar="activar(`b-${i}`)"
                    @subir="mover(d.seccion_b, i, -1, 'b')"
                    @bajar="mover(d.seccion_b, i, 1, 'b')"
                    @duplicar="duplicar(d.seccion_b, i, 'b')"
                    @eliminar="eliminar(d.seccion_b, i, 'b')"
                >
                    <template #resumen>
                        <p class="text-[15px] font-medium">
                            {{ p.afirmacion || 'Afirmación sin texto' }}
                        </p>
                        <p class="text-sm text-[var(--primary)]">
                            Correcta: {{ p.correcto ? 'Verdadero' : 'Falso' }}
                        </p>
                    </template>
                    <Textarea
                        v-model="p.afirmacion"
                        rows="2"
                        placeholder="Escribe la afirmación"
                        :disabled="props.soloLectura"
                    />
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm text-muted-foreground"
                            >La respuesta correcta es</span
                        >
                        <Button
                            type="button"
                            size="sm"
                            :variant="p.correcto ? 'default' : 'outline'"
                            :disabled="props.soloLectura"
                            @click="p.correcto = true"
                            >Verdadero</Button
                        >
                        <Button
                            type="button"
                            size="sm"
                            :variant="!p.correcto ? 'default' : 'outline'"
                            :disabled="props.soloLectura"
                            @click="p.correcto = false"
                            >Falso</Button
                        >
                    </div>
                    <template #pie>
                        <label
                            class="mr-auto flex items-center gap-2 text-sm text-muted-foreground"
                            >Puntos
                            <Input
                                v-model.number="p.puntos"
                                type="number"
                                min="1"
                                max="10"
                                class="h-8 w-16"
                        /></label>
                    </template>
                </TarjetaPregunta>
                <Button
                    v-if="!props.soloLectura"
                    type="button"
                    variant="outline"
                    class="w-fit border-dashed"
                    @click="agregarB"
                >
                    <Plus class="size-4" /> Agregar afirmación
                </Button>
            </template>

            <!-- C · Relacionar columnas -->
            <template v-else-if="s.clave === 'c'">
                <TarjetaPregunta
                    :activa="activa === 'c-descripciones'"
                    etiqueta="Columna de descripciones"
                    :solo-lectura="props.soloLectura"
                    :puede-subir="false"
                    :puede-bajar="false"
                    :puede-eliminar="false"
                    :puede-duplicar="false"
                    @activar="activar('c-descripciones')"
                >
                    <template #resumen>
                        <ul class="flex flex-col gap-1 text-sm">
                            <li
                                v-for="(texto, i) in descripciones"
                                :key="i"
                                class="flex gap-2"
                            >
                                <strong>{{ letraDe(i) }}.</strong>
                                {{ texto || 'Descripción sin texto' }}
                            </li>
                        </ul>
                    </template>
                    <div
                        v-for="(texto, i) in descripciones"
                        :key="i"
                        class="flex items-center gap-2"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-md bg-[color-mix(in_oklab,var(--acento)_16%,transparent)] text-xs font-bold"
                            >{{ letraDe(i) }}</span
                        >
                        <Input
                            :model-value="texto"
                            placeholder="Descripción"
                            :disabled="props.soloLectura"
                            @update:model-value="
                                (v) => editarDescripcion(i, String(v))
                            "
                        />
                        <template v-if="!props.soloLectura">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Subir"
                                :disabled="i === 0"
                                @click="moverDescripcion(i, -1)"
                                >↑</Button
                            >
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Quitar descripción"
                                :disabled="descripciones.length <= 2"
                                @click="quitarDescripcion(i)"
                            >
                                <X class="size-4" />
                            </Button>
                        </template>
                    </div>
                    <Button
                        v-if="!props.soloLectura && descripciones.length < 10"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="w-fit"
                        @click="agregarDescripcion"
                    >
                        <Plus class="size-4" /> Agregar descripción
                    </Button>
                </TarjetaPregunta>

                <TarjetaPregunta
                    v-for="(c, i) in d.seccion_c.conceptos"
                    :key="`c-${i}`"
                    :activa="activa === `c-${i}`"
                    :etiqueta="`Concepto ${i + 1} · ${c.puntos} ${c.puntos === 1 ? 'punto' : 'puntos'}`"
                    :solo-lectura="props.soloLectura"
                    :puede-subir="i > 0"
                    :puede-bajar="i < d.seccion_c.conceptos.length - 1"
                    @activar="activar(`c-${i}`)"
                    @subir="mover(d.seccion_c.conceptos, i, -1, 'c')"
                    @bajar="mover(d.seccion_c.conceptos, i, 1, 'c')"
                    @duplicar="duplicar(d.seccion_c.conceptos, i, 'c')"
                    @eliminar="eliminar(d.seccion_c.conceptos, i, 'c')"
                >
                    <template #resumen>
                        <p class="text-[15px] font-medium">
                            {{ c.concepto || 'Concepto sin texto' }}
                            <span class="ml-2 text-sm text-[var(--primary)]">
                                →
                                {{ c.letra_correcta || 'sin letra elegida' }}
                            </span>
                        </p>
                    </template>
                    <Input
                        v-model="c.concepto"
                        placeholder="Concepto"
                        :disabled="props.soloLectura"
                    />
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="mr-1 text-sm text-muted-foreground"
                            >Le corresponde la descripción</span
                        >
                        <Button
                            v-for="(_, j) in descripciones"
                            :key="j"
                            type="button"
                            size="sm"
                            :variant="
                                c.letra_correcta === letraDe(j)
                                    ? 'default'
                                    : 'outline'
                            "
                            :disabled="props.soloLectura"
                            @click="c.letra_correcta = letraDe(j)"
                            >{{ letraDe(j) }}</Button
                        >
                    </div>
                    <template #pie>
                        <label
                            class="mr-auto flex items-center gap-2 text-sm text-muted-foreground"
                            >Puntos
                            <Input
                                v-model.number="c.puntos"
                                type="number"
                                min="1"
                                max="10"
                                class="h-8 w-16"
                        /></label>
                    </template>
                </TarjetaPregunta>
                <Button
                    v-if="!props.soloLectura"
                    type="button"
                    variant="outline"
                    class="w-fit border-dashed"
                    @click="agregarC"
                >
                    <Plus class="size-4" /> Agregar concepto
                </Button>
            </template>

            <!-- D · Casos prácticos -->
            <template v-else-if="s.clave === 'd'">
                <TarjetaPregunta
                    v-for="(caso, i) in d.seccion_d"
                    :key="caso.clave"
                    :activa="activa === `d-${i}`"
                    :etiqueta="`Caso ${i + 1} · ${sumaPuntos(caso.rubrica)} puntos`"
                    :solo-lectura="props.soloLectura"
                    :puede-subir="i > 0"
                    :puede-bajar="i < d.seccion_d.length - 1"
                    @activar="activar(`d-${i}`)"
                    @subir="mover(d.seccion_d, i, -1, 'd')"
                    @bajar="mover(d.seccion_d, i, 1, 'd')"
                    @duplicar="
                        duplicar(d.seccion_d, i, 'd', (copia) => ({
                            ...copia,
                            clave: claveNueva('caso'),
                        }))
                    "
                    @eliminar="eliminar(d.seccion_d, i, 'd')"
                >
                    <template #resumen>
                        <p class="text-[15px] font-medium">
                            {{ caso.enunciado || 'Caso sin texto' }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Rúbrica de RH: {{ caso.rubrica.length }}
                            {{
                                caso.rubrica.length === 1
                                    ? 'criterio'
                                    : 'criterios'
                            }}
                        </p>
                    </template>
                    <Textarea
                        v-model="caso.enunciado"
                        rows="3"
                        placeholder="Describe el caso práctico"
                        :disabled="props.soloLectura"
                    />
                    <div
                        class="flex flex-col gap-2 rounded-xl border border-dashed border-border bg-muted/30 p-3"
                    >
                        <p class="text-xs font-semibold text-muted-foreground">
                            Rúbrica (solo la ve Recursos Humanos al calificar)
                        </p>
                        <div
                            v-for="(r, j) in caso.rubrica"
                            :key="r.clave"
                            class="flex items-center gap-2"
                        >
                            <Input
                                v-model="r.criterio"
                                placeholder="Qué debe mencionar para ganar el punto"
                                :disabled="props.soloLectura"
                            />
                            <Input
                                v-model.number="r.puntos"
                                type="number"
                                min="1"
                                max="10"
                                class="w-20"
                                aria-label="Puntos del criterio"
                                :disabled="props.soloLectura"
                            />
                            <Button
                                v-if="!props.soloLectura"
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Quitar criterio"
                                :disabled="caso.rubrica.length <= 1"
                                @click="caso.rubrica.splice(j, 1)"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>
                        <Button
                            v-if="!props.soloLectura"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="w-fit"
                            @click="
                                caso.rubrica.push({
                                    clave: claveNueva('criterio'),
                                    puntos: 1,
                                    criterio: '',
                                })
                            "
                        >
                            <Plus class="size-4" /> Agregar criterio
                        </Button>
                    </div>
                </TarjetaPregunta>
                <Button
                    v-if="!props.soloLectura"
                    type="button"
                    variant="outline"
                    class="w-fit border-dashed"
                    @click="agregarD"
                >
                    <Plus class="size-4" /> Agregar caso práctico
                </Button>
            </template>

            <!-- E · Opinión -->
            <template v-else>
                <TarjetaPregunta
                    v-for="(aspecto, i) in d.seccion_e.aspectos"
                    :key="aspecto.clave"
                    :activa="activa === `e-${i}`"
                    :etiqueta="`Aspecto ${i + 1} · escala 1 a 5`"
                    :solo-lectura="props.soloLectura"
                    :puede-subir="i > 0"
                    :puede-bajar="i < d.seccion_e.aspectos.length - 1"
                    @activar="activar(`e-${i}`)"
                    @subir="mover(d.seccion_e.aspectos, i, -1, 'e')"
                    @bajar="mover(d.seccion_e.aspectos, i, 1, 'e')"
                    @duplicar="
                        duplicar(d.seccion_e.aspectos, i, 'e', (copia) => ({
                            ...copia,
                            clave: claveNueva('aspecto'),
                        }))
                    "
                    @eliminar="eliminar(d.seccion_e.aspectos, i, 'e')"
                >
                    <template #resumen>
                        <p class="text-[15px] font-medium">
                            {{ aspecto.texto || 'Aspecto sin texto' }}
                        </p>
                    </template>
                    <Input
                        v-model="aspecto.texto"
                        placeholder="Ej. La información fue clara"
                        :disabled="props.soloLectura"
                    />
                </TarjetaPregunta>
                <Button
                    v-if="!props.soloLectura"
                    type="button"
                    variant="outline"
                    class="w-fit border-dashed"
                    @click="agregarE"
                >
                    <Plus class="size-4" /> Agregar aspecto
                </Button>
                <div
                    class="grid gap-4 rounded-2xl border border-border bg-card p-4 shadow-xs sm:grid-cols-2"
                >
                    <div class="grid gap-1.5">
                        <Label for="etiqueta-min">Texto del 1</Label>
                        <Input
                            id="etiqueta-min"
                            v-model="d.seccion_e.escala_etiquetas['1']"
                            :disabled="props.soloLectura"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="etiqueta-max">Texto del 5</Label>
                        <Input
                            id="etiqueta-max"
                            v-model="d.seccion_e.escala_etiquetas['5']"
                            :disabled="props.soloLectura"
                        />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="pregunta-abierta"
                            >Pregunta abierta final</Label
                        >
                        <Input
                            id="pregunta-abierta"
                            v-model="d.seccion_e.pregunta_abierta.texto"
                            :disabled="props.soloLectura"
                        />
                    </div>
                </div>
            </template>
        </section>
    </div>
</template>
