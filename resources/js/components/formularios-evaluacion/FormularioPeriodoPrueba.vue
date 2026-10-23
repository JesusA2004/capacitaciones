<script setup lang="ts">
import { CheckCircle2, FileWarning } from '@lucide/vue';
import { computed } from 'vue';
import EncabezadoFormulario from '@/components/formularios-evaluacion/EncabezadoFormulario.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { estiloAcento } from '@/lib/formulariosEvaluacion';
import { cn } from '@/lib/utils';
import type { ContenidoPeriodoPrueba } from '@/types';

/**
 * Evaluación de periodo de prueba (FO-RH-EPP-001) TAL CUAL la captura el
 * jefe inmediato sobre el colaborador: por cada criterio elige una de las
 * cuatro tarjetas (4 Muy bueno … 1 Deficiente) con la descripción oficial
 * del nivel, responde las preguntas filtro Sí/No y escribe sus comentarios.
 * El resultado (SÍ/NO SE RENUEVA y sus motivos) se calcula EN VIVO con las
 * reglas de la versión del formulario. La usan la captura real
 * (Rh/Colaboradores/Ciclo) y la vista previa del editor.
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

type Calificacion = {
    clave: string;
    calificacion: number;
    meta?: number | null;
    logro?: number | null;
};
type Respuesta = { clave: string; respuesta: boolean };

const criterios = defineModel<Calificacion[]>('criterios', { required: true });
const filtros = defineModel<Respuesta[]>('filtros', { required: true });
const observaciones = defineModel<string>('observaciones', { default: '' });
const faltas = defineModel<number | null>('faltas', { default: null });
const retardos = defineModel<number | null>('retardos', { default: null });

const ESCALA = [4, 3, 2, 1];

function criterioDe(clave: string): Calificacion | undefined {
    return criterios.value.find((c) => c.clave === clave);
}

function calificacionDe(clave: string): number | null {
    return criterioDe(clave)?.calificacion ?? null;
}

/** Reescribe la lista conservando el orden del formulario y meta/logró. */
function asignarCriterio(clave: string, cambios: Partial<Calificacion>): void {
    if (props.deshabilitado) {
        return;
    }

    const previos = new Map(criterios.value.map((c) => [c.clave, c]));
    const actual = previos.get(clave);
    previos.set(clave, {
        clave,
        calificacion: cambios.calificacion ?? actual?.calificacion ?? 0,
        meta: 'meta' in cambios ? cambios.meta : actual?.meta,
        logro: 'logro' in cambios ? cambios.logro : actual?.logro,
    });

    criterios.value = props.contenido.criterios
        .map((c) => previos.get(c.clave))
        .filter((c): c is Calificacion => c !== undefined && c.calificacion > 0);
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

function metaDe(clave: string, campo: 'meta' | 'logro'): number | null {
    return criterioDe(clave)?.[campo] ?? null;
}

function actualizarMeta(
    clave: string,
    campo: 'meta' | 'logro',
    valor: string,
): void {
    const numero = valor === '' ? null : Number(valor);
    asignarCriterio(clave, {
        [campo]: numero === null || Number.isNaN(numero) ? null : numero,
    });
}

function porcentaje(clave: string): number | null {
    const c = criterioDe(clave);

    if (!c || c.meta == null || c.meta <= 0 || c.logro == null) {
        return null;
    }

    return Math.round((c.logro / c.meta) * 1000) / 10;
}

const total = computed(() =>
    criterios.value.reduce((s, c) => s + (Number(c.calificacion) || 0), 0),
);

const tieneUno = computed(() =>
    criterios.value.some((c) => c.calificacion === 1),
);
const tieneSiFiltro = computed(() => filtros.value.some((f) => f.respuesta));
const completo = computed(
    () =>
        criterios.value.length >= props.contenido.criterios.length &&
        filtros.value.length >= props.contenido.filtros.length,
);

/** Resultado preliminar EN VIVO (misma regla canónica que el backend). */
const resultado = computed<{ renueva: boolean; motivos: string[] }>(() => {
    const motivos: string[] = [];

    if (props.contenido.un_uno_no_renueva && tieneUno.value) {
        motivos.push('Tiene al menos un criterio calificado con 1.');
    }

    if (tieneSiFiltro.value) {
        motivos.push('Tiene al menos una pregunta filtro marcada «Sí».');
    }

    if (total.value < props.contenido.total_minimo_renovar) {
        motivos.push(
            `El total es menor de ${props.contenido.total_minimo_renovar} puntos.`,
        );
    }

    return { renueva: motivos.length === 0, motivos };
});

const requiereComentario = computed(
    () =>
        criterios.value.some(
            (c) =>
                c.calificacion <= props.contenido.comentario_obligatorio_hasta,
        ) ||
        (props.contenido.comentario_si_filtro && tieneSiFiltro.value),
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

function nivelTexto(c: ContenidoPeriodoPrueba['criterios'][number], n: number) {
    return c.niveles?.[String(n)] ?? '';
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

        <!-- Asistencia (dato de RH): el jefe lo ve para calificar el renglón 4. -->
        <section
            class="flex flex-col gap-2 rounded-2xl border border-border bg-card p-4 shadow-xs"
        >
            <h3 class="text-sm font-semibold">Asistencia del periodo</h3>
            <p class="text-xs text-muted-foreground">
                Dato de Recursos Humanos. Úsalo para calificar «Asistencia y
                puntualidad». Déjalo en blanco si no lo tienes.
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="faltas-evaluacion">Faltas sin justificar</Label>
                    <Input
                        id="faltas-evaluacion"
                        :model-value="faltas ?? ''"
                        type="number"
                        min="0"
                        inputmode="numeric"
                        :disabled="deshabilitado"
                        @update:model-value="
                            (v) =>
                                (faltas =
                                    v === '' || v === null ? null : Number(v))
                        "
                    />
                    <InputError :message="errores.faltas_injustificadas" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="retardos-evaluacion">Retardos</Label>
                    <Input
                        id="retardos-evaluacion"
                        :model-value="retardos ?? ''"
                        type="number"
                        min="0"
                        inputmode="numeric"
                        :disabled="deshabilitado"
                        @update:model-value="
                            (v) =>
                                (retardos =
                                    v === '' || v === null ? null : Number(v))
                        "
                    />
                    <InputError :message="errores.retardos" />
                </div>
            </div>
        </section>

        <section
            class="flex flex-col gap-2 rounded-2xl border border-border bg-card p-4 shadow-xs"
        >
            <h3 class="text-sm font-semibold">Paso 1 · Califica cada criterio</h3>
            <p class="text-xs text-muted-foreground">
                En cada renglón elige la tarjeta que mejor describa lo que viste
                del colaborador. Califica hechos, no impresiones.
            </p>
            <div
                v-for="(c, i) in contenido.criterios"
                :key="c.clave"
                class="flex flex-col gap-2 border-t border-border/70 py-3 first:border-t-0"
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

                <!-- Meta / Logró / % (solo "Cumple sus metas"). -->
                <div
                    v-if="c.captura_meta"
                    class="grid gap-3 rounded-xl bg-[var(--mrl-fondo)] p-3 sm:grid-cols-3"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`meta-${c.clave}`" class="text-xs"
                            >Meta</Label
                        >
                        <Input
                            :id="`meta-${c.clave}`"
                            type="number"
                            min="0"
                            inputmode="decimal"
                            :model-value="metaDe(c.clave, 'meta') ?? ''"
                            :disabled="deshabilitado"
                            @update:model-value="
                                (v) =>
                                    actualizarMeta(c.clave, 'meta', String(v))
                            "
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`logro-${c.clave}`" class="text-xs"
                            >Logró</Label
                        >
                        <Input
                            :id="`logro-${c.clave}`"
                            type="number"
                            min="0"
                            inputmode="decimal"
                            :model-value="metaDe(c.clave, 'logro') ?? ''"
                            :disabled="deshabilitado"
                            @update:model-value="
                                (v) =>
                                    actualizarMeta(c.clave, 'logro', String(v))
                            "
                        />
                    </div>
                    <div class="flex flex-col justify-end">
                        <span class="text-xs text-muted-foreground">Logró</span>
                        <span class="text-lg font-semibold tabular-nums">
                            {{
                                porcentaje(c.clave) === null
                                    ? '—'
                                    : `${porcentaje(c.clave)} %`
                            }}
                        </span>
                    </div>
                </div>

                <!-- Tarjetas por valor (4/3/2/1) con la descripción oficial. -->
                <div
                    class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4"
                    role="radiogroup"
                    :aria-label="c.etiqueta"
                >
                    <button
                        v-for="n in ESCALA"
                        :key="n"
                        type="button"
                        role="radio"
                        :aria-checked="calificacionDe(c.clave) === n"
                        :disabled="deshabilitado"
                        :class="
                            cn(
                                'flex flex-col gap-1 rounded-xl border p-2.5 text-left transition-colors disabled:cursor-not-allowed',
                                calificacionDe(c.clave) === n
                                    ? n === 1
                                        ? 'border-transparent bg-destructive text-white shadow-sm'
                                        : n === 4
                                          ? 'border-transparent bg-success text-white shadow-sm'
                                          : 'border-transparent bg-primary text-primary-foreground shadow-sm'
                                    : 'border-border bg-background hover:bg-muted/50',
                            )
                        "
                        @click="asignarCriterio(c.clave, { calificacion: n })"
                    >
                        <span class="flex items-center gap-1.5 text-sm font-semibold">
                            <span
                                :class="
                                    cn(
                                        'flex size-6 shrink-0 items-center justify-center rounded-md text-xs',
                                        calificacionDe(c.clave) === n
                                            ? 'bg-white/25'
                                            : 'bg-muted',
                                    )
                                "
                                >{{ n }}</span
                            >
                            {{ etiquetaEscala(n)
                            }}<span
                                v-if="n === 1"
                                class="text-[10px] font-normal opacity-90"
                                >· NO SE RENUEVA</span
                            >
                        </span>
                        <span
                            v-if="nivelTexto(c, n)"
                            class="text-xs leading-snug opacity-90"
                            >{{ nivelTexto(c, n) }}</span
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
                Marca «Sí» solo si el hecho ocurrió y está documentado. Un solo
                «Sí» significa que NO se renueva, sin importar el total.
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

            <!-- Resultado preliminar EN VIVO (Paso 3). -->
            <div
                v-if="completo"
                :class="
                    cn(
                        'flex flex-col gap-1.5 rounded-xl border p-3',
                        resultado.renueva
                            ? 'border-success/40 bg-success-soft'
                            : 'border-destructive/40 bg-[var(--mrl-peligro-suave,var(--mrl-fondo))]',
                    )
                "
            >
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <CheckCircle2
                        v-if="resultado.renueva"
                        class="size-4 text-success"
                    />
                    <FileWarning v-else class="size-4 text-destructive" />
                    Resultado preliminar:
                    {{ resultado.renueva ? 'SÍ SE RENUEVA' : 'NO SE RENUEVA' }}
                </p>
                <ul
                    v-if="!resultado.renueva"
                    class="ml-6 list-disc text-xs text-muted-foreground"
                >
                    <li v-for="m in resultado.motivos" :key="m">{{ m }}</li>
                </ul>
                <p v-else class="text-xs text-muted-foreground">
                    Pasa a contrato por tiempo indeterminado. La decisión final
                    la confirma Recursos Humanos.
                </p>
            </div>

            <div class="grid gap-1.5">
                <Label for="observaciones-evaluacion"
                    >Comentario del jefe
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
                    placeholder="Describe hechos y fechas. Si se renueva, anota en qué debe mejorar."
                />
                <InputError :message="errores.observaciones" />
            </div>
        </section>
    </div>
</template>
