<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight, Eye } from '@lucide/vue';
import RadioMarca from '@/components/Common/RadioMarca.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { presentacion as presentacionInduccion } from '@/routes/induccion';
import { miProceso } from '@/routes/portal';
import { presentar } from '@/routes/portal/induccion';
import type { InduccionContenido, InduccionRespuestas } from '@/types';

/**
 * Evaluación FO-RH-EI tal cual la contesta el colaborador. Con `vistaPrevia`
 * (RH, «Ver evaluación como colaborador») se muestra idéntica pero sin
 * evaluación detrás: no se puede enviar y no crea nada.
 */
const props = defineProps<{
    contenido: InduccionContenido;
    /** null en la vista previa de RH. */
    evaluacionId: number | null;
    vistaPrevia?: boolean;
}>();

// `layout` como función: defineOptions() no puede leer `props`.
defineOptions({
    layout: (pageProps: { vistaPrevia?: boolean }) => ({
        breadcrumbs: pageProps.vistaPrevia
            ? [
                  { title: 'Inicio', href: dashboard() },
                  {
                      title: 'Presentar inducción',
                      href: presentacionInduccion(),
                  },
                  { title: 'Vista previa de la evaluación', href: '' },
              ]
            : [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Mis pendientes', href: miProceso() },
                  { title: 'Evaluación de inducción', href: '' },
              ],
    }),
});

const form = useForm<{
    respuestas: InduccionRespuestas;
    opinion: {
        claridad: number | null;
        conocimiento_puesto: number | null;
        bienvenida: number | null;
        tema_reforzar: string;
    };
}>({
    respuestas: { a: {}, b: {}, c: {}, d: {} },
    opinion: {
        claridad: null,
        conocimiento_puesto: null,
        bienvenida: null,
        tema_reforzar: '',
    },
});

function enviar() {
    if (props.vistaPrevia || props.evaluacionId === null) {
        return;
    }

    form.post(presentar.url(props.evaluacionId), { preserveScroll: true });
}

const letras = ['A', 'B', 'C', 'D', 'E'];
</script>

<template>
    <Head title="Evaluación de inducción" />

    <div class="pagina-ancha flex flex-col gap-6 pb-16">
        <div
            v-if="vistaPrevia"
            role="status"
            class="sticky top-2 z-10 flex items-start gap-3 rounded-2xl border bg-warning-soft px-4 py-3 text-sm shadow-sm"
        >
            <Eye class="mt-0.5 size-4 shrink-0" />
            <p>
                <strong>Vista previa — así lo verá el colaborador.</strong>
                Puedes marcar respuestas para probarla, pero no se envía ni se
                guarda nada.
            </p>
        </div>

        <header
            class="rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-6"
        >
            <h1
                class="text-2xl font-semibold tracking-tight text-[var(--mrl-texto)]"
            >
                Evaluación del Programa de Inducción
            </h1>
            <p class="mt-1 text-sm text-[var(--mrl-texto-suave)]">
                {{ contenido.instrucciones.total_puntos }} puntos en total ·
                calificación mínima aprobatoria
                {{ contenido.instrucciones.calificacion_aprobatoria }}/{{
                    contenido.instrucciones.total_puntos
                }}. Las secciones A, B y C se califican al instante; los casos
                prácticos (sección D) los revisa Recursos Humanos.
            </p>
        </header>

        <form class="flex flex-col gap-6" @submit.prevent="enviar">
            <!-- Sección A -->
            <section
                class="flex flex-col gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
            >
                <h2 class="text-base font-semibold text-[var(--mrl-texto)]">
                    A · Opción múltiple
                    <span class="font-normal text-[var(--mrl-texto-suave)]"
                        >(12 puntos)</span
                    >
                </h2>
                <fieldset
                    v-for="p in contenido.seccion_a"
                    :key="p.indice"
                    class="flex flex-col gap-1.5"
                >
                    <legend class="mb-1 text-sm font-medium">
                        {{ p.indice + 1 }}. {{ p.pregunta }}
                    </legend>
                    <label
                        v-for="(opcion, i) in p.opciones"
                        :key="i"
                        class="flex cursor-pointer items-center gap-2 rounded-lg border border-transparent px-2 py-1.5 text-sm transition-colors hover:border-[var(--mrl-borde)] hover:bg-[var(--mrl-fondo)] has-[:checked]:border-[var(--mrl-primary)]/40 has-[:checked]:bg-[var(--mrl-primary)]/5"
                    >
                        <RadioMarca
                            v-model="form.respuestas.a[p.indice]"
                            :value="i"
                            :name="`a-${p.indice}`"
                        />
                        {{ opcion }}
                    </label>
                </fieldset>
            </section>

            <!-- Sección B -->
            <section
                class="flex flex-col gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
            >
                <h2 class="text-base font-semibold text-[var(--mrl-texto)]">
                    B · Verdadero o falso
                    <span class="font-normal text-[var(--mrl-texto-suave)]"
                        >(8 puntos)</span
                    >
                </h2>
                <fieldset
                    v-for="p in contenido.seccion_b"
                    :key="p.indice"
                    class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <legend class="text-sm font-medium">
                        {{ p.indice + 1 }}. {{ p.afirmacion }}
                    </legend>
                    <div class="flex shrink-0 gap-4">
                        <label
                            class="flex cursor-pointer items-center gap-2 text-sm"
                        >
                            <RadioMarca
                                v-model="form.respuestas.b[p.indice]"
                                :value="true"
                                :name="`b-${p.indice}`"
                            />
                            Verdadero
                        </label>
                        <label
                            class="flex cursor-pointer items-center gap-2 text-sm"
                        >
                            <RadioMarca
                                v-model="form.respuestas.b[p.indice]"
                                :value="false"
                                :name="`b-${p.indice}`"
                            />
                            Falso
                        </label>
                    </div>
                </fieldset>
            </section>

            <!-- Sección C -->
            <section
                class="flex flex-col gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
            >
                <h2 class="text-base font-semibold text-[var(--mrl-texto)]">
                    C · Relaciona columnas
                    <span class="font-normal text-[var(--mrl-texto-suave)]"
                        >(5 puntos)</span
                    >
                </h2>
                <p class="text-sm text-[var(--mrl-texto-suave)]">
                    Elige la descripción que corresponde a cada concepto.
                </p>
                <ul class="mb-2 flex flex-col gap-1 text-sm">
                    <li
                        v-for="(texto, letra) in contenido.seccion_c
                            .descripciones"
                        :key="letra"
                    >
                        <strong>{{ letra }}.</strong> {{ texto }}
                    </li>
                </ul>
                <fieldset
                    v-for="c in contenido.seccion_c.conceptos"
                    :key="c.indice"
                    class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <legend class="text-sm font-medium">
                        {{ c.indice + 1 }}. {{ c.concepto }}
                    </legend>
                    <div class="flex shrink-0 flex-wrap gap-3">
                        <label
                            v-for="letra in letras"
                            :key="letra"
                            class="flex cursor-pointer items-center gap-1.5 text-sm"
                        >
                            <RadioMarca
                                v-model="form.respuestas.c[c.indice]"
                                :value="letra"
                                :name="`c-${c.indice}`"
                            />
                            {{ letra }}
                        </label>
                    </div>
                </fieldset>
            </section>

            <!-- Sección D -->
            <section
                class="flex flex-col gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
            >
                <h2 class="text-base font-semibold text-[var(--mrl-texto)]">
                    D · Casos prácticos
                    <span class="font-normal text-[var(--mrl-texto-suave)]"
                        >(5 puntos · los califica Recursos Humanos)</span
                    >
                </h2>
                <div
                    v-for="caso in contenido.seccion_d"
                    :key="caso.clave"
                    class="flex flex-col gap-2"
                >
                    <p class="text-sm font-medium">
                        {{ caso.enunciado }}
                        <span class="text-[var(--mrl-texto-suave)]"
                            >({{ caso.puntos_max }} pts)</span
                        >
                    </p>
                    <Textarea
                        v-model="form.respuestas.d[caso.clave]"
                        rows="4"
                        placeholder="Responde con tus propias palabras…"
                    />
                </div>
            </section>

            <!-- Sección E -->
            <section
                class="flex flex-col gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
            >
                <h2 class="text-base font-semibold text-[var(--mrl-texto)]">
                    E · Tu opinión sobre la inducción
                    <span class="font-normal text-[var(--mrl-texto-suave)]"
                        >(no califica)</span
                    >
                </h2>
                <fieldset
                    v-for="aspecto in contenido.seccion_e.aspectos"
                    :key="aspecto.clave"
                    class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <legend class="text-sm">{{ aspecto.texto }}</legend>
                    <div class="flex shrink-0 gap-3">
                        <label
                            v-for="n in contenido.seccion_e.escala_max -
                            contenido.seccion_e.escala_min +
                            1"
                            :key="n"
                            class="flex cursor-pointer items-center gap-1 text-sm"
                        >
                            <RadioMarca
                                v-model="
                                    (
                                        form.opinion as unknown as Record<
                                            string,
                                            number | null
                                        >
                                    )[aspecto.clave]
                                "
                                :value="n + contenido.seccion_e.escala_min - 1"
                                :name="aspecto.clave"
                            />
                            {{ n + contenido.seccion_e.escala_min - 1 }}
                        </label>
                    </div>
                </fieldset>
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium" for="tema_reforzar"
                        >¿Qué tema te gustaría reforzar?</label
                    >
                    <Textarea
                        id="tema_reforzar"
                        v-model="form.opinion.tema_reforzar"
                        rows="2"
                    />
                </div>
            </section>

            <InputError :message="form.errors.respuestas as string" />
            <Button
                type="submit"
                class="w-fit"
                :disabled="form.processing || vistaPrevia"
                :title="
                    vistaPrevia
                        ? 'Vista previa: el envío está deshabilitado'
                        : undefined
                "
            >
                Enviar evaluación <ArrowRight class="size-4" />
            </Button>
        </form>
    </div>
</template>
