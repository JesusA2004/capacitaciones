<script setup lang="ts">
import { computed } from 'vue';
import RadioMarca from '@/components/Common/RadioMarca.vue';
import EncabezadoFormulario from '@/components/formularios-evaluacion/EncabezadoFormulario.vue';
import { Textarea } from '@/components/ui/textarea';
import { estiloAcento, SECCIONES_INDUCCION } from '@/lib/formulariosEvaluacion';
import type { InduccionContenido, InduccionRespuestas } from '@/types';

/**
 * Evaluación de inducción TAL CUAL la contesta el colaborador: encabezado
 * con el diseño de la versión, secciones A–E en tarjetas. La usan la página
 * real (Portal/EvaluacionInduccion), la vista previa de RH y el editor de
 * formularios — un solo componente, para que la vista previa sea idéntica.
 * El botón de envío lo pone la página (slot `acciones`).
 */
const props = defineProps<{
    contenido: InduccionContenido;
    deshabilitado?: boolean;
}>();

const respuestas = defineModel<InduccionRespuestas>('respuestas', {
    required: true,
});
const opinion = defineModel<Record<string, number | string | null>>('opinion', {
    required: true,
});

const diseno = computed(
    () =>
        props.contenido.formulario?.diseno ?? {
            acento: 'petroleo',
            estilo_encabezado: 'franja',
            banner_url: null,
        },
);
const textos = computed(
    () => props.contenido.formulario?.secciones ?? SECCIONES_INDUCCION,
);
const puntos = computed(() => props.contenido.formulario?.puntos_secciones);
const letras = computed(() =>
    Object.keys(props.contenido.seccion_c.descripciones),
);
const escala = computed(() => {
    const e = props.contenido.seccion_e;

    return Array.from(
        { length: e.escala_max - e.escala_min + 1 },
        (_, i) => e.escala_min + i,
    );
});
const etiquetasEscala = computed(
    () => props.contenido.seccion_e.escala_etiquetas ?? {},
);
const preguntaAbierta = computed(
    () =>
        props.contenido.seccion_e.pregunta_abierta?.texto ??
        '¿Qué tema te gustaría reforzar?',
);

// Numeración corrida A→D, como el formato impreso.
const baseB = computed(() => props.contenido.seccion_a.length);
const baseC = computed(() => baseB.value + props.contenido.seccion_b.length);
const baseD = computed(
    () => baseC.value + props.contenido.seccion_c.conceptos.length,
);

const contestadas = computed(() => {
    const r = respuestas.value;
    const total =
        props.contenido.seccion_a.length +
        props.contenido.seccion_b.length +
        props.contenido.seccion_c.conceptos.length +
        props.contenido.seccion_d.length;
    const hechas =
        Object.values(r.a).filter((v) => v !== null && v !== undefined).length +
        Object.values(r.b).filter((v) => v !== null && v !== undefined).length +
        Object.values(r.c).filter((v) => v).length +
        Object.values(r.d).filter((v) => String(v ?? '').trim() !== '').length;

    return { total, hechas };
});

const tarjeta =
    'rounded-2xl border border-border bg-card p-5 shadow-xs transition-shadow focus-within:shadow-md focus-within:ring-1 focus-within:ring-[var(--acento)]';
const opcionClase =
    'flex cursor-pointer items-center gap-2.5 rounded-xl border border-transparent px-3 py-2 text-sm transition-colors hover:border-border hover:bg-muted/40 has-[[data-state=checked]]:border-[color-mix(in_oklab,var(--acento)_45%,transparent)] has-[[data-state=checked]]:bg-[color-mix(in_oklab,var(--acento)_10%,transparent)]';
</script>

<template>
    <div
        class="flex flex-col gap-5"
        :style="estiloAcento(diseno)"
        :aria-disabled="deshabilitado || undefined"
    >
        <EncabezadoFormulario
            :titulo="
                contenido.formulario?.titulo ??
                'Evaluación del Programa de Inducción'
            "
            :descripcion="contenido.formulario?.descripcion"
            :diseno="diseno"
        >
            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                <span
                    class="rounded-full bg-[color-mix(in_oklab,var(--acento)_14%,transparent)] px-3 py-1 font-medium"
                >
                    {{ contenido.instrucciones.total_puntos }} puntos
                </span>
                <span
                    class="rounded-full bg-[color-mix(in_oklab,var(--acento)_14%,transparent)] px-3 py-1 font-medium"
                >
                    Mínimo aprobatorio
                    {{ contenido.instrucciones.calificacion_aprobatoria }}
                </span>
                <span
                    class="rounded-full bg-[color-mix(in_oklab,var(--acento)_14%,transparent)] px-3 py-1 font-medium"
                >
                    {{ contestadas.hechas }}/{{ contestadas.total }}
                    respondidas
                </span>
            </div>
        </EncabezadoFormulario>

        <!-- Sección A -->
        <section class="flex flex-col gap-3">
            <div class="px-1">
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <span
                        class="flex size-7 items-center justify-center rounded-full bg-[var(--acento)] text-xs font-bold text-[var(--acento-texto)]"
                        >A</span
                    >
                    {{ textos.a.titulo }}
                    <span
                        v-if="puntos"
                        class="text-sm font-normal text-muted-foreground"
                        >({{ puntos.a }} puntos)</span
                    >
                </h2>
                <p
                    v-if="textos.a.descripcion"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ textos.a.descripcion }}
                </p>
            </div>
            <fieldset
                v-for="p in contenido.seccion_a"
                :key="`a-${p.indice}`"
                :class="tarjeta"
                :disabled="deshabilitado"
            >
                <legend class="sr-only">Pregunta {{ p.indice + 1 }}</legend>
                <p class="mb-3 text-[15px] font-medium">
                    {{ p.indice + 1 }}. {{ p.pregunta }}
                </p>
                <div class="flex flex-col gap-1">
                    <label
                        v-for="(opcion, i) in p.opciones"
                        :key="i"
                        :class="opcionClase"
                    >
                        <RadioMarca
                            v-model="respuestas.a[p.indice]"
                            :value="i"
                            :name="`a-${p.indice}`"
                            :disabled="deshabilitado"
                        />
                        {{ opcion }}
                    </label>
                </div>
            </fieldset>
        </section>

        <!-- Sección B -->
        <section class="flex flex-col gap-3">
            <div class="px-1">
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <span
                        class="flex size-7 items-center justify-center rounded-full bg-[var(--acento)] text-xs font-bold text-[var(--acento-texto)]"
                        >B</span
                    >
                    {{ textos.b.titulo }}
                    <span
                        v-if="puntos"
                        class="text-sm font-normal text-muted-foreground"
                        >({{ puntos.b }} puntos)</span
                    >
                </h2>
                <p
                    v-if="textos.b.descripcion"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ textos.b.descripcion }}
                </p>
            </div>
            <fieldset
                v-for="p in contenido.seccion_b"
                :key="`b-${p.indice}`"
                :class="[
                    tarjeta,
                    'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between',
                ]"
                :disabled="deshabilitado"
            >
                <legend class="sr-only">
                    Afirmación {{ baseB + p.indice + 1 }}
                </legend>
                <p class="text-[15px] font-medium">
                    {{ baseB + p.indice + 1 }}. {{ p.afirmacion }}
                </p>
                <div class="flex shrink-0 gap-2">
                    <label :class="opcionClase">
                        <RadioMarca
                            v-model="respuestas.b[p.indice]"
                            :value="true"
                            :name="`b-${p.indice}`"
                            :disabled="deshabilitado"
                        />
                        Verdadero
                    </label>
                    <label :class="opcionClase">
                        <RadioMarca
                            v-model="respuestas.b[p.indice]"
                            :value="false"
                            :name="`b-${p.indice}`"
                            :disabled="deshabilitado"
                        />
                        Falso
                    </label>
                </div>
            </fieldset>
        </section>

        <!-- Sección C -->
        <section class="flex flex-col gap-3">
            <div class="px-1">
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <span
                        class="flex size-7 items-center justify-center rounded-full bg-[var(--acento)] text-xs font-bold text-[var(--acento-texto)]"
                        >C</span
                    >
                    {{ textos.c.titulo }}
                    <span
                        v-if="puntos"
                        class="text-sm font-normal text-muted-foreground"
                        >({{ puntos.c }} puntos)</span
                    >
                </h2>
                <p
                    v-if="textos.c.descripcion"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ textos.c.descripcion }}
                </p>
            </div>
            <div :class="tarjeta">
                <ul class="flex flex-col gap-2 text-sm">
                    <li
                        v-for="(texto, letra) in contenido.seccion_c
                            .descripciones"
                        :key="letra"
                        class="flex gap-3"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-md bg-[color-mix(in_oklab,var(--acento)_16%,transparent)] text-xs font-bold"
                            >{{ letra }}</span
                        >
                        <span class="pt-0.5">{{ texto }}</span>
                    </li>
                </ul>
            </div>
            <fieldset
                v-for="c in contenido.seccion_c.conceptos"
                :key="`c-${c.indice}`"
                :class="[
                    tarjeta,
                    'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between',
                ]"
                :disabled="deshabilitado"
            >
                <legend class="sr-only">
                    Concepto {{ baseC + c.indice + 1 }}
                </legend>
                <p class="text-[15px] font-medium">
                    {{ baseC + c.indice + 1 }}. {{ c.concepto }}
                </p>
                <div class="flex shrink-0 flex-wrap gap-1">
                    <label
                        v-for="letra in letras"
                        :key="letra"
                        :class="[opcionClase, 'px-2.5']"
                    >
                        <RadioMarca
                            v-model="respuestas.c[c.indice]"
                            :value="letra"
                            :name="`c-${c.indice}`"
                            :disabled="deshabilitado"
                        />
                        {{ letra }}
                    </label>
                </div>
            </fieldset>
        </section>

        <!-- Sección D -->
        <section class="flex flex-col gap-3">
            <div class="px-1">
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <span
                        class="flex size-7 items-center justify-center rounded-full bg-[var(--acento)] text-xs font-bold text-[var(--acento-texto)]"
                        >D</span
                    >
                    {{ textos.d.titulo }}
                    <span
                        v-if="puntos"
                        class="text-sm font-normal text-muted-foreground"
                        >({{ puntos.d }} puntos)</span
                    >
                </h2>
                <p
                    v-if="textos.d.descripcion"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ textos.d.descripcion }}
                </p>
            </div>
            <div
                v-for="(caso, i) in contenido.seccion_d"
                :key="caso.clave"
                :class="[tarjeta, 'flex flex-col gap-3']"
            >
                <p class="text-[15px] font-medium">
                    {{ baseD + i + 1 }}. {{ caso.enunciado }}
                    <span class="font-normal text-muted-foreground"
                        >({{ caso.puntos_max }}
                        {{ caso.puntos_max === 1 ? 'punto' : 'puntos' }})</span
                    >
                </p>
                <Textarea
                    v-model="respuestas.d[caso.clave]"
                    rows="4"
                    placeholder="Responde con tus propias palabras…"
                    :disabled="deshabilitado"
                />
            </div>
        </section>

        <!-- Sección E -->
        <section class="flex flex-col gap-3">
            <div class="px-1">
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <span
                        class="flex size-7 items-center justify-center rounded-full bg-[var(--acento)] text-xs font-bold text-[var(--acento-texto)]"
                        >E</span
                    >
                    {{ textos.e.titulo }}
                </h2>
                <p
                    v-if="textos.e.descripcion"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ textos.e.descripcion }}
                </p>
            </div>
            <div :class="[tarjeta, 'flex flex-col gap-5']">
                <fieldset
                    v-for="aspecto in contenido.seccion_e.aspectos"
                    :key="aspecto.clave"
                    class="flex flex-col gap-2"
                    :disabled="deshabilitado"
                >
                    <legend class="mb-2 text-sm font-medium">
                        {{ aspecto.texto }}
                    </legend>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            v-if="
                                etiquetasEscala[contenido.seccion_e.escala_min]
                            "
                            class="mr-1 text-xs text-muted-foreground"
                            >{{
                                etiquetasEscala[contenido.seccion_e.escala_min]
                            }}</span
                        >
                        <label
                            v-for="n in escala"
                            :key="n"
                            :class="[
                                opcionClase,
                                'flex-col gap-1 px-3 py-1.5 text-xs',
                            ]"
                        >
                            <RadioMarca
                                v-model="opinion[aspecto.clave]"
                                :value="n"
                                :name="`e-${aspecto.clave}`"
                                :disabled="deshabilitado"
                            />
                            {{ n }}
                        </label>
                        <span
                            v-if="
                                etiquetasEscala[contenido.seccion_e.escala_max]
                            "
                            class="ml-1 text-xs text-muted-foreground"
                            >{{
                                etiquetasEscala[contenido.seccion_e.escala_max]
                            }}</span
                        >
                    </div>
                </fieldset>
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium" for="tema_reforzar">{{
                        preguntaAbierta
                    }}</label>
                    <Textarea
                        id="tema_reforzar"
                        :model-value="String(opinion.tema_reforzar ?? '')"
                        rows="2"
                        :disabled="deshabilitado"
                        @update:model-value="
                            (v) => (opinion.tema_reforzar = String(v))
                        "
                    />
                </div>
            </div>
        </section>

        <slot name="acciones" />
    </div>
</template>
