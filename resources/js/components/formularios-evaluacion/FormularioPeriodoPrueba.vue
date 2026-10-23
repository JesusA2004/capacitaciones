<script setup lang="ts">
import { computed } from 'vue';
import EncabezadoFormulario from '@/components/formularios-evaluacion/EncabezadoFormulario.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { estiloAcento } from '@/lib/formulariosEvaluacion';
import { cn } from '@/lib/utils';
import type { ContenidoPeriodoPrueba } from '@/types';

/**
 * Evaluación de periodo de prueba (FO-RH-EPP-001) TAL CUAL la contesta el
 * propio colaborador (AUTOEVALUACIÓN): criterios en escala 4–1, preguntas filtro Sí/No y
 * comentario, con el diseño de la versión del formulario. La usan la
 * página real (Portal/EvaluacionPeriodoPrueba) y la vista previa del editor.
 */
const props = withDefaults(
    defineProps<{
        contenido: ContenidoPeriodoPrueba;
        errores?: Partial<Record<string, string>>;
        compacto?: boolean;
        deshabilitado?: boolean;
    }>(),
    { errores: () => ({}), compacto: false, deshabilitado: false },
);

type Calificacion = { clave: string; calificacion: number };
type Respuesta = { clave: string; respuesta: boolean };

const criterios = defineModel<Calificacion[]>('criterios', { required: true });
const filtros = defineModel<Respuesta[]>('filtros', { required: true });
const observaciones = defineModel<string>('observaciones', { default: '' });

const ESCALA = [4, 3, 2, 1];

function calificacionDe(clave: string): number | null {
    return criterios.value.find((c) => c.clave === clave)?.calificacion ?? null;
}

function calificar(clave: string, valor: number): void {
    if (props.deshabilitado) {
        return;
    }

    const otros = criterios.value.filter((c) => c.clave !== clave);
    criterios.value = props.contenido.criterios
        .map((c) =>
            c.clave === clave
                ? { clave, calificacion: valor }
                : otros.find((o) => o.clave === c.clave),
        )
        .filter((c): c is Calificacion => c !== undefined);
}

function respuestaDe(clave: string): boolean | null {
    return filtros.value.find((f) => f.clave === clave)?.respuesta ?? null;
}

function responder(clave: string, valor: boolean): void {
    if (props.deshabilitado) {
        return;
    }

    const otros = filtros.value.filter((f) => f.clave !== clave);
    filtros.value = props.contenido.filtros
        .map((f) =>
            f.clave === clave
                ? { clave, respuesta: valor }
                : otros.find((o) => o.clave === f.clave),
        )
        .filter((f): f is Respuesta => f !== undefined);
}

const total = computed(() =>
    criterios.value.reduce((s, c) => s + (Number(c.calificacion) || 0), 0),
);
const requiereComentario = computed(
    () =>
        criterios.value.some(
            (c) =>
                c.calificacion <= props.contenido.comentario_obligatorio_hasta,
        ) ||
        (props.contenido.comentario_si_filtro &&
            filtros.value.some((f) => f.respuesta)),
);
const motivoComentario = computed(() => {
    const hasta = props.contenido.comentario_obligatorio_hasta;
    const partes: string[] = [];

    if (hasta > 0) {
        partes.push(
            hasta === 1
                ? 'un criterio en 1'
                : `un criterio en ${hasta} o menos`,
        );
    }

    if (props.contenido.comentario_si_filtro) {
        partes.push('una pregunta filtro en «Sí»');
    }

    return partes.join(' o ');
});

function etiquetaEscala(n: number): string {
    return props.contenido.escala_etiquetas[String(n)] ?? String(n);
}
</script>

<template>
    <div
        :class="cn('flex flex-col', compacto ? 'gap-4' : 'gap-5')"
        :style="estiloAcento(contenido.diseno)"
    >
        <EncabezadoFormulario
            :titulo="contenido.titulo"
            :descripcion="contenido.descripcion"
            :diseno="contenido.diseno"
            :compacto="compacto"
        >
            <template #etiquetas>
                <span
                    class="rounded-full bg-[color-mix(in_oklab,var(--acento)_14%,transparent)] px-3 py-1 text-xs font-medium"
                    >{{ contenido.codigo }}</span
                >
            </template>
            <ul class="mt-1 flex flex-col gap-1 text-xs">
                <li>
                    Escala:
                    <span v-for="(n, i) in ESCALA" :key="n"
                        ><strong>{{ n }}</strong> {{ etiquetaEscala(n)
                        }}{{ i < ESCALA.length - 1 ? ' · ' : '' }}</span
                    >
                </li>
                <li>
                    Se renueva con
                    <strong
                        >{{ contenido.total_minimo_renovar }} de
                        {{ contenido.total_maximo }} puntos</strong
                    >
                    o más<template v-if="contenido.un_uno_no_renueva"
                        >, sin ningún criterio en 1</template
                    ><template v-if="contenido.filtros.length"
                        >, y sin ningún «Sí» en las preguntas filtro</template
                    >.
                </li>
            </ul>
        </EncabezadoFormulario>

        <section
            class="flex flex-col gap-2 rounded-2xl border border-border bg-card p-4 shadow-xs"
        >
            <h3 class="text-sm font-semibold">
                Paso 1 · Califica tu desempeño
            </h3>
            <p class="text-xs text-muted-foreground">
                En cada punto elige la opción que mejor describa cómo has
                trabajado durante tu capacitación inicial.
            </p>
            <div
                v-for="(c, i) in contenido.criterios"
                :key="c.clave"
                class="flex flex-col gap-2 border-t border-border/70 py-3 first:border-t-0 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium">
                        {{ i + 1 }}. {{ c.etiqueta }}
                    </p>
                    <p
                        v-if="c.descripcion"
                        class="text-xs text-muted-foreground"
                    >
                        {{ c.descripcion }}
                    </p>
                    <InputError :message="errores[`criterios.${c.clave}`]" />
                </div>
                <div
                    class="flex shrink-0 gap-1"
                    role="radiogroup"
                    :aria-label="c.etiqueta"
                >
                    <button
                        v-for="n in ESCALA"
                        :key="n"
                        type="button"
                        role="radio"
                        :aria-checked="calificacionDe(c.clave) === n"
                        :title="etiquetaEscala(n)"
                        :disabled="deshabilitado"
                        :class="
                            cn(
                                'flex h-10 min-w-11 flex-col items-center justify-center rounded-xl border px-2 text-sm font-semibold transition-colors disabled:cursor-not-allowed',
                                calificacionDe(c.clave) === n
                                    ? n === 1
                                        ? 'border-transparent bg-destructive text-white'
                                        : 'border-transparent bg-primary text-primary-foreground'
                                    : 'border-border bg-background hover:bg-muted/50',
                            )
                        "
                        @click="calificar(c.clave, n)"
                    >
                        {{ n }}
                        <span
                            v-if="!compacto"
                            class="text-[10px] leading-none font-normal opacity-80"
                            >{{ etiquetaEscala(n) }}</span
                        >
                    </button>
                </div>
            </div>
        </section>

        <section
            v-if="contenido.filtros.length"
            class="flex flex-col gap-2 rounded-2xl border border-border bg-card p-4 shadow-xs"
        >
            <h3 class="text-sm font-semibold">Paso 2 · Preguntas filtro</h3>
            <p class="text-xs text-muted-foreground">
                Durante tu capacitación inicial, ¿te pasó alguna de estas
                situaciones? Contesta con honestidad: un solo «Sí» significa que
                NO se renueva, sin importar el total.
            </p>
            <div
                v-for="f in contenido.filtros"
                :key="f.clave"
                class="flex flex-col gap-2 border-t border-border/70 py-3 first:border-t-0 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium">{{ f.etiqueta }}</p>
                    <p
                        v-if="f.descripcion"
                        class="text-xs text-muted-foreground"
                    >
                        {{ f.descripcion }}
                    </p>
                    <InputError :message="errores[`filtros.${f.clave}`]" />
                </div>
                <div class="flex shrink-0 gap-1" role="radiogroup">
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="respuestaDe(f.clave) === false"
                        :disabled="deshabilitado"
                        :class="
                            cn(
                                'h-9 rounded-xl border px-4 text-sm font-medium transition-colors',
                                respuestaDe(f.clave) === false
                                    ? 'border-transparent bg-primary text-primary-foreground'
                                    : 'border-border bg-background hover:bg-muted/50',
                            )
                        "
                        @click="responder(f.clave, false)"
                    >
                        No
                    </button>
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="respuestaDe(f.clave) === true"
                        :disabled="deshabilitado"
                        :class="
                            cn(
                                'h-9 rounded-xl border px-4 text-sm font-medium transition-colors',
                                respuestaDe(f.clave) === true
                                    ? 'border-transparent bg-destructive text-white'
                                    : 'border-border bg-background hover:bg-muted/50',
                            )
                        "
                        @click="responder(f.clave, true)"
                    >
                        Sí
                    </button>
                </div>
            </div>
        </section>

        <section
            class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 shadow-xs"
        >
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold">Total</h3>
                <p class="text-lg font-semibold tabular-nums">
                    {{ total
                    }}<span class="text-sm font-normal text-muted-foreground"
                        >/{{ contenido.total_maximo }}</span
                    >
                </p>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full bg-[var(--acento)] transition-all"
                    :style="{
                        width: `${contenido.total_maximo ? Math.min(100, (total / contenido.total_maximo) * 100) : 0}%`,
                    }"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="observaciones-evaluacion"
                    >Tus comentarios
                    <span class="font-normal text-muted-foreground">{{
                        requiereComentario
                            ? `(obligatorio: hay ${motivoComentario})`
                            : '(opcional)'
                    }}</span></Label
                >
                <Textarea
                    id="observaciones-evaluacion"
                    v-model="observaciones"
                    rows="3"
                    :disabled="deshabilitado"
                />
                <InputError :message="errores.observaciones" />
            </div>
        </section>
    </div>
</template>
