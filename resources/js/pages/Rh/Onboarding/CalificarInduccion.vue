<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Check,
    ClipboardCheck,
    Download,
    Eye,
    FolderInput,
    MessageSquareText,
    RotateCcw,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EnvioDescriptivoPuestoRh from '@/components/ciclo/EnvioDescriptivoPuestoRh.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { presentacion as presentacionInduccion } from '@/routes/induccion';
import {
    calificar,
    recalificar,
    retroalimentar,
    vistaPrevia as vistaPreviaEvaluacion,
} from '@/routes/rh/onboarding/induccion';
import * as pdf from '@/routes/rh/onboarding/induccion/pdf';
import type {
    EvaluacionInduccionResumen,
    InduccionCasoPractico,
    ResumenDescriptivoPuestoRh,
    RevisionInduccion,
} from '@/types';

/**
 * Revisión de la evaluación oficial de inducción (FO-RH-EI, 30 puntos):
 * cada pregunta con lo que contestó el colaborador y si acertó (A/B/C ya
 * calificadas en el servidor), los casos prácticos (D) con la rúbrica
 * oficial para que RH los califique, el total /30 y el resultado.
 */
const props = defineProps<{
    evaluacion: EvaluacionInduccionResumen;
    casosPracticos: InduccionCasoPractico[];
    revision: RevisionInduccion;
    intentoId: number;
    yaCalificado: boolean;
    puedeRecalificar: boolean;
    descriptivoPuesto: ResumenDescriptivoPuestoRh;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Presentar inducción', href: presentacionInduccion() },
            { title: 'Revisar evaluación', href: '' },
        ],
    },
});

const intento = computed(() =>
    props.evaluacion.intentos.find((i) => i.id === props.intentoId),
);

const form = useForm<{
    puntos: Record<string, Record<string, boolean>>;
    comentario: string;
}>({
    puntos: Object.fromEntries(
        props.revision.seccion_d.map((c) => [
            c.clave,
            Object.fromEntries(c.rubrica.map((r) => [r.clave, false])),
        ]),
    ),
    comentario: '',
});

/**
 * «Calificar de nuevo» (error humano): vuelve a abrir la rúbrica con lo que
 * se marcó la vez pasada; al guardar se recalcula y se regenera el formato.
 */
const recalificando = ref(false);
const editable = computed(() => !props.yaCalificado || recalificando.value);

function empezarRecalificacion() {
    for (const caso of props.revision.seccion_d) {
        for (const r of caso.rubrica) {
            form.puntos[caso.clave][r.clave] = r.otorgado === true;
        }
    }

    form.comentario = props.revision.comentario_rh ?? '';
    form.clearErrors();
    recalificando.value = true;
}

// El servicio rechaza con la clave «evaluacion» (ya calificado, ya no es el
// último intento…): se muestra junto a la rúbrica, nunca en silencio.
const errorEvaluacion = computed(
    () => (form.errors as Record<string, string | undefined>).evaluacion,
);

function cancelarRecalificacion() {
    recalificando.value = false;
    form.clearErrors();
}

const maxD = computed(() =>
    props.revision.seccion_d.reduce((s, c) => s + c.puntos_max, 0),
);

const totalD = computed(() =>
    !editable.value
        ? (props.revision.puntos.d ?? 0)
        : props.revision.seccion_d.reduce(
              (suma, caso) =>
                  suma +
                  caso.rubrica.reduce(
                      (s, r) =>
                          s +
                          (form.puntos[caso.clave]?.[r.clave] ? r.puntos : 0),
                      0,
                  ),
              0,
          ),
);

const totalAbc = computed(
    () =>
        props.revision.puntos.a +
        props.revision.puntos.b +
        props.revision.puntos.c,
);
const total = computed(() =>
    !editable.value
        ? (props.revision.puntos.total ?? 0)
        : totalAbc.value + totalD.value,
);
const aprobatoria = 24;

const secciones = computed(() => [
    {
        clave: 'A',
        titulo: 'Opción múltiple',
        puntos: props.revision.puntos.a,
        max: props.revision.seccion_a.length,
    },
    {
        clave: 'B',
        titulo: 'Verdadero o falso',
        puntos: props.revision.puntos.b,
        max: props.revision.seccion_b.length,
    },
    {
        clave: 'C',
        titulo: 'Relaciona columnas',
        puntos: props.revision.puntos.c,
        max: props.revision.seccion_c.conceptos.length,
    },
    {
        clave: 'D',
        titulo: 'Casos prácticos',
        puntos: totalD.value,
        max: maxD.value,
    },
]);

const opinion = computed(() => {
    const o = props.revision.opinion;

    if (!o) {
        return [];
    }

    return [
        {
            texto: 'La información fue clara y fácil de entender',
            valor: o.claridad,
        },
        {
            texto: 'Ahora conozco qué se espera de mí en mi puesto',
            valor: o.conocimiento_puesto,
        },
        {
            texto: 'Me siento bienvenido(a) y parte de Mr. Lana',
            valor: o.bienvenida,
        },
    ];
});

function enviar() {
    if (recalificando.value) {
        form.post(recalificar.url(props.intentoId), {
            preserveScroll: true,
            onSuccess: () => (recalificando.value = false),
        });

        return;
    }

    form.post(calificar.url(props.intentoId), { preserveScroll: true });
}

const retroForm = useForm<{ retroalimentacion: string }>({
    retroalimentacion: '',
});

function enviarRetro() {
    retroForm.post(retroalimentar.url(props.evaluacion.id), {
        preserveScroll: true,
        onSuccess: () => retroForm.reset(),
    });
}

const guardando = ref(false);

function guardarEnExpediente() {
    guardando.value = true;
    router.post(
        pdf.expediente.url(props.intentoId),
        {},
        { preserveScroll: true, onFinish: () => (guardando.value = false) },
    );
}

function letra(indice: number): string {
    return String.fromCharCode(97 + indice);
}
</script>

<template>
    <Head title="Revisar evaluación de inducción" />

    <div class="pagina-ancha flex flex-col gap-6 pb-16">
        <header class="rounded-3xl border bg-card p-6">
            <p
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                FO-RH-EI · Evaluación del Programa de Inducción
            </p>
            <div class="mt-2 flex items-center gap-3">
                <ColaboradorAvatar
                    :nombre="evaluacion.colaborador.nombre"
                    :foto-url="evaluacion.colaborador.foto_url"
                    tamano="lg"
                />
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ evaluacion.colaborador.nombre }}
                </h1>
            </div>
            <dl
                class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-5"
            >
                <div>
                    <dt class="text-muted-foreground">Puesto</dt>
                    <dd>{{ evaluacion.colaborador.puesto ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Sucursal</dt>
                    <dd>{{ evaluacion.colaborador.sucursal ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Fecha de ingreso</dt>
                    <dd>{{ evaluacion.colaborador.fecha_ingreso ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Contestada</dt>
                    <dd>
                        {{
                            intento?.presentado_en
                                ? new Date(
                                      intento.presentado_en,
                                  ).toLocaleDateString('es-MX')
                                : '—'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Intento</dt>
                    <dd>N.º {{ intento?.numero ?? '—' }}</dd>
                </div>
            </dl>
            <div class="mt-4 flex flex-wrap gap-2">
                <Button as-child variant="outline" size="sm">
                    <a
                        :href="pdf.vista.url(intentoId)"
                        target="_blank"
                        rel="noopener"
                        ><Eye class="size-4" /> Vista previa del formato</a
                    >
                </Button>
                <Button as-child variant="outline" size="sm">
                    <Link :href="vistaPreviaEvaluacion()"
                        ><Eye class="size-4" /> Ver evaluación como
                        colaborador</Link
                    >
                </Button>
                <Button as-child variant="outline" size="sm">
                    <a :href="pdf.descargar.url(intentoId)"
                        ><Download class="size-4" /> Descargar PDF</a
                    >
                </Button>
                <Button
                    v-if="yaCalificado"
                    size="sm"
                    :disabled="guardando"
                    @click="guardarEnExpediente"
                    ><FolderInput class="size-4" /> Guardar en
                    expediente</Button
                >
            </div>
        </header>

        <!-- Marcador -->
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div
                v-for="s in secciones"
                :key="s.clave"
                class="rounded-2xl border bg-card p-4"
            >
                <p class="text-xs text-muted-foreground">
                    {{ s.clave }} · {{ s.titulo }}
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ s.puntos
                    }}<span class="text-base text-muted-foreground"
                        >/{{ s.max }}</span
                    >
                </p>
            </div>
            <div
                class="rounded-2xl border p-4"
                :class="
                    total >= aprobatoria
                        ? 'bg-success-soft'
                        : yaCalificado
                          ? 'bg-warning-soft'
                          : 'bg-crema'
                "
            >
                <p class="text-xs text-muted-foreground">
                    Total {{ editable ? '(con lo marcado)' : '' }}
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ total
                    }}<span class="text-base text-muted-foreground">/30</span>
                </p>
                <p class="text-xs font-medium">
                    {{
                        !editable
                            ? revision.nivel_etiqueta
                            : total >= aprobatoria
                              ? 'Aprobaría (mínimo 24)'
                              : 'No alcanza 24 todavía'
                    }}
                </p>
            </div>
        </section>

        <section
            v-if="yaCalificado"
            class="flex items-center gap-4 rounded-3xl border p-6"
            :class="revision.aprobado ? 'bg-success-soft' : 'bg-warning-soft'"
        >
            <BadgeCheck
                v-if="revision.aprobado"
                class="size-10 shrink-0 text-success"
            />
            <TriangleAlert v-else class="size-10 shrink-0 text-warning" />
            <div>
                <p class="text-lg font-semibold">
                    {{ revision.puntos.total }}/30 ·
                    {{ revision.nivel_etiqueta }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        revision.aprobado
                            ? 'Aprobó su evaluación de inducción: queda listo para empezar a laborar en cuanto no tenga otros pendientes de ingreso.'
                            : 'Requiere reforzamiento: da retroalimentación abajo para habilitar la reevaluación (máximo 5 días hábiles).'
                    }}
                </p>
                <p v-if="revision.comentario_rh" class="mt-1 text-sm">
                    Comentario de RH: {{ revision.comentario_rh }}
                </p>
            </div>
        </section>

        <!-- Inducción aprobada: «Enviar descriptivo de puesto» y su acuse -->
        <EnvioDescriptivoPuestoRh
            v-if="evaluacion.estado === 'aprobada' || descriptivoPuesto.acuse"
            :colaborador-id="evaluacion.colaborador.id"
            :resumen="descriptivoPuesto"
        />

        <!-- A -->
        <section class="flex flex-col gap-3">
            <h2 class="text-base font-semibold">
                A · Opción múltiple
                <span class="text-sm font-normal text-muted-foreground"
                    >({{ revision.puntos.a }}/{{
                        revision.seccion_a.length
                    }})</span
                >
            </h2>
            <article
                v-for="p in revision.seccion_a"
                :key="p.numero"
                class="tarjeta-interactiva rounded-2xl border bg-card p-4"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full"
                        :class="
                            p.acierto
                                ? 'bg-success-soft text-success'
                                : 'bg-danger-soft text-destructive'
                        "
                    >
                        <Check v-if="p.acierto" class="size-4" />
                        <X v-else class="size-4" />
                    </span>
                    <div class="flex-1">
                        <p class="text-sm font-medium">
                            {{ p.numero }}. {{ p.pregunta }}
                        </p>
                        <ul class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                            <li
                                v-for="(opcion, i) in p.opciones"
                                :key="i"
                                class="rounded-lg px-2 py-1"
                                :class="{
                                    'bg-success-soft font-medium':
                                        i === p.correcta,
                                    'bg-danger-soft':
                                        i === p.elegida && !p.acierto,
                                }"
                            >
                                {{ letra(i) }}) {{ opcion }}
                                <span
                                    v-if="i === p.elegida"
                                    class="text-xs text-muted-foreground"
                                    >· eligió</span
                                >
                            </li>
                        </ul>
                        <p
                            v-if="p.elegida === null"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            Sin respuesta.
                        </p>
                    </div>
                </div>
            </article>
        </section>

        <!-- B -->
        <section class="flex flex-col gap-3">
            <h2 class="text-base font-semibold">
                B · Verdadero o falso
                <span class="text-sm font-normal text-muted-foreground"
                    >({{ revision.puntos.b }}/{{
                        revision.seccion_b.length
                    }})</span
                >
            </h2>
            <article
                v-for="p in revision.seccion_b"
                :key="p.numero"
                class="flex tarjeta-interactiva items-start gap-3 rounded-2xl border bg-card p-4"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-full"
                    :class="
                        p.acierto
                            ? 'bg-success-soft text-success'
                            : 'bg-danger-soft text-destructive'
                    "
                >
                    <Check v-if="p.acierto" class="size-4" />
                    <X v-else class="size-4" />
                </span>
                <div class="flex-1 text-sm">
                    <p class="font-medium">
                        {{ p.numero }}. {{ p.afirmacion }}
                    </p>
                    <p class="mt-1 text-muted-foreground">
                        Contestó:
                        <strong>{{
                            p.elegida === null
                                ? 'sin respuesta'
                                : p.elegida
                                  ? 'Verdadero'
                                  : 'Falso'
                        }}</strong>
                        · Correcto: {{ p.correcto ? 'Verdadero' : 'Falso' }}
                    </p>
                </div>
            </article>
        </section>

        <!-- C -->
        <section class="flex flex-col gap-3">
            <h2 class="text-base font-semibold">
                C · Relaciona columnas
                <span class="text-sm font-normal text-muted-foreground"
                    >({{ revision.puntos.c }}/{{
                        revision.seccion_c.conceptos.length
                    }})</span
                >
            </h2>
            <div class="grid gap-3 lg:grid-cols-2">
                <article
                    v-for="p in revision.seccion_c.conceptos"
                    :key="p.numero"
                    class="flex tarjeta-interactiva items-start gap-3 rounded-2xl border bg-card p-4"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full"
                        :class="
                            p.acierto
                                ? 'bg-success-soft text-success'
                                : 'bg-danger-soft text-destructive'
                        "
                    >
                        <Check v-if="p.acierto" class="size-4" />
                        <X v-else class="size-4" />
                    </span>
                    <div class="flex-1 text-sm">
                        <p class="font-medium">
                            {{ p.numero }}. {{ p.concepto }}
                        </p>
                        <p class="mt-1 text-muted-foreground">
                            Eligió <strong>{{ p.elegida ?? '—' }}</strong
                            ><template
                                v-if="
                                    p.elegida &&
                                    revision.seccion_c.descripciones[p.elegida]
                                "
                            >
                                ·
                                {{
                                    revision.seccion_c.descripciones[p.elegida]
                                }}</template
                            >
                        </p>
                        <p v-if="!p.acierto" class="text-muted-foreground">
                            Correcta: <strong>{{ p.correcta }}</strong> ·
                            {{ revision.seccion_c.descripciones[p.correcta] }}
                        </p>
                    </div>
                </article>
            </div>
        </section>

        <!-- D -->
        <form
            class="flex flex-col gap-4 rounded-3xl border bg-card p-5"
            @submit.prevent="enviar"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <ClipboardCheck class="size-5" /> D · Casos prácticos ·
                    rúbrica oficial ({{ maxD }} puntos)
                </h2>
                <Button
                    v-if="yaCalificado && puedeRecalificar && !recalificando"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="empezarRecalificacion"
                    ><RotateCcw class="size-4" /> Calificar de nuevo</Button
                >
            </div>
            <p v-if="editable" class="text-sm text-muted-foreground">
                Marca cada criterio que la respuesta escrita cumple: otorga el
                punto cuando la idea es correcta, aunque el colaborador la
                exprese con sus propias palabras.
            </p>
            <p
                v-if="recalificando"
                class="rounded-xl bg-warning-soft p-3 text-sm"
            >
                Estás calificando de nuevo el intento N.º
                {{ intento?.numero }}. Se recalcula el total, queda registrado
                quién lo corrigió y se regenera el formato.
                <template v-if="evaluacion.estado === 'aprobada'">
                    Como ya estaba aprobada, la corrección no puede dejarla por
                    debajo del mínimo.</template
                >
            </p>
            <p
                v-if="errorEvaluacion"
                class="rounded-xl bg-danger-soft p-3 text-sm text-destructive"
            >
                {{ errorEvaluacion }}
            </p>

            <article
                v-for="caso in revision.seccion_d"
                :key="caso.clave"
                class="flex flex-col gap-3 rounded-2xl border p-4"
            >
                <p class="text-sm font-medium">
                    {{ caso.numero }}. {{ caso.enunciado }}
                    <span class="text-muted-foreground"
                        >({{ caso.puntos_max }} pts)</span
                    >
                </p>
                <p class="rounded-xl bg-crema p-3 text-sm whitespace-pre-line">
                    {{ caso.respuesta || 'Sin respuesta.' }}
                </p>
                <template v-if="!editable">
                    <p
                        v-for="criterio in caso.rubrica"
                        :key="criterio.clave"
                        class="flex items-start gap-2 text-sm"
                    >
                        <Check
                            v-if="criterio.otorgado"
                            class="mt-0.5 size-4 text-success"
                        />
                        <X v-else class="mt-0.5 size-4 text-destructive" />
                        <span
                            >{{ criterio.criterio }}
                            <span class="text-muted-foreground"
                                >({{ criterio.puntos }} pt)</span
                            ></span
                        >
                    </p>
                    <p class="text-sm font-medium">
                        {{ caso.puntos_obtenidos }}/{{ caso.puntos_max }}
                    </p>
                </template>
                <template v-else>
                    <label
                        v-for="criterio in caso.rubrica"
                        :key="criterio.clave"
                        class="flex cursor-pointer items-start gap-2 rounded-lg p-1 text-sm transition-colors hover:bg-salvia/40"
                    >
                        <Checkbox
                            :model-value="
                                form.puntos[caso.clave][criterio.clave]
                            "
                            @update:model-value="
                                (v) =>
                                    (form.puntos[caso.clave][criterio.clave] =
                                        !!v)
                            "
                        />
                        <span
                            >{{ criterio.criterio }}
                            <span class="text-muted-foreground"
                                >({{ criterio.puntos }} pt)</span
                            ></span
                        >
                    </label>
                </template>
            </article>

            <template v-if="editable">
                <label class="flex flex-col gap-1 text-sm">
                    <span class="flex items-center gap-1 font-medium"
                        ><MessageSquareText class="size-4" /> Comentario de RH
                        (opcional, va en el formato)</span
                    >
                    <Textarea
                        v-model="form.comentario"
                        rows="2"
                        placeholder="Observaciones sobre los casos prácticos…"
                    />
                </label>
                <div class="flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{
                            recalificando
                                ? 'Guardar nueva calificación'
                                : 'Guardar calificación'
                        }}
                        ({{ total }}/30)
                    </Button>
                    <Button
                        v-if="recalificando"
                        type="button"
                        variant="ghost"
                        :disabled="form.processing"
                        @click="cancelarRecalificacion"
                        >Cancelar</Button
                    >
                    <span class="text-sm text-muted-foreground"
                        >D: {{ totalD }}/{{ maxD }} · A+B+C:
                        {{ totalAbc }}</span
                    >
                </div>
            </template>
        </form>

        <!-- E -->
        <section
            v-if="opinion.length || revision.opinion?.tema_reforzar"
            class="flex flex-col gap-2 rounded-3xl border bg-card p-5"
        >
            <h2 class="text-base font-semibold">E · Opinión del colaborador</h2>
            <p
                v-for="o in opinion"
                :key="o.texto"
                class="flex justify-between gap-4 text-sm"
            >
                <span>{{ o.texto }}</span>
                <strong class="tabular-nums">{{ o.valor ?? '—' }}</strong>
            </p>
            <p v-if="revision.opinion?.tema_reforzar" class="text-sm">
                Tema a reforzar: {{ revision.opinion.tema_reforzar }}
            </p>
        </section>

        <form
            v-if="evaluacion.estado === 'requiere_reforzamiento'"
            class="flex flex-col gap-3 rounded-3xl border bg-warning-soft p-5"
            @submit.prevent="enviarRetro"
        >
            <h2 class="text-base font-semibold">
                Retroalimentación para reforzamiento
            </h2>
            <p class="text-sm text-muted-foreground">
                Reaplicar antes del
                {{ evaluacion.fecha_limite_reevaluacion }}.
            </p>
            <Textarea
                v-model="retroForm.retroalimentacion"
                rows="3"
                placeholder="Qué temas repasar con el colaborador…"
            />
            <Button type="submit" class="w-fit" :disabled="retroForm.processing"
                >Enviar y habilitar reevaluación</Button
            >
        </form>

        <Link
            :href="presentacionInduccion()"
            class="text-sm text-muted-foreground underline-offset-4 hover:underline"
            >← Volver al seguimiento de inducción</Link
        >
    </div>
</template>
