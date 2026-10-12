<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BadgeCheck, ClipboardCheck } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { calificar, retroalimentar } from '@/routes/rh/onboarding/induccion';
import { index as pendientesIndex } from '@/routes/rh/pendientes';
import type { EvaluacionInduccionResumen, InduccionCasoPractico } from '@/types';

const props = defineProps<{
    evaluacion: EvaluacionInduccionResumen;
    casosPracticos: InduccionCasoPractico[];
    intentoId: number;
    yaCalificado: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis pendientes', href: pendientesIndex() },
            { title: 'Calificar inducción', href: '' },
        ],
    },
});

const intento = computed(() =>
    props.evaluacion.intentos.find((i) => i.id === props.intentoId),
);

const form = useForm<{ puntos: Record<string, Record<string, boolean>> }>({
    puntos: Object.fromEntries(
        props.casosPracticos.map((c) => [
            c.clave,
            Object.fromEntries(c.rubrica.map((r) => [r.clave, false])),
        ]),
    ),
});

const totalD = computed(() =>
    props.casosPracticos.reduce(
        (suma, caso) =>
            suma +
            caso.rubrica.reduce(
                (s, r) => s + (form.puntos[caso.clave]?.[r.clave] ? r.puntos : 0),
                0,
            ),
        0,
    ),
);

function enviar() {
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
</script>

<template>
    <Head title="Calificar evaluación de inducción" />

    <div class="pagina-ancha flex flex-col gap-6 pb-16">
        <header
            class="rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-6"
        >
            <h1
                class="text-2xl font-semibold tracking-tight text-[var(--mrl-texto)]"
            >
                Evaluación de inducción · {{ evaluacion.colaborador.nombre }}
            </h1>
            <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-[var(--mrl-texto-suave)]">Puesto</dt>
                    <dd>{{ evaluacion.colaborador.puesto ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[var(--mrl-texto-suave)]">Sucursal</dt>
                    <dd>{{ evaluacion.colaborador.sucursal ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[var(--mrl-texto-suave)]">Fecha de ingreso</dt>
                    <dd>{{ evaluacion.colaborador.fecha_ingreso ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[var(--mrl-texto-suave)]">Intento</dt>
                    <dd>N.º {{ intento?.numero ?? '—' }}</dd>
                </div>
            </dl>
        </header>

        <section
            v-if="yaCalificado"
            class="flex items-center gap-4 rounded-3xl border border-[var(--mrl-success)]/30 bg-[var(--mrl-success)]/5 p-6"
        >
            <BadgeCheck class="size-10 shrink-0 text-[var(--mrl-success)]" />
            <div>
                <p class="text-lg font-semibold text-[var(--mrl-texto)]">
                    Ya calificado: {{ intento?.calificacion_total }}/30 ·
                    {{ intento?.nivel_etiqueta }}
                </p>
                <p class="text-sm text-[var(--mrl-texto-suave)]">
                    {{
                        intento?.aprobado
                            ? 'El colaborador aprobó su evaluación de inducción.'
                            : 'Requiere reforzamiento — da retroalimentación abajo para habilitar la reevaluación.'
                    }}
                </p>
            </div>
        </section>

        <form
            v-else
            class="flex flex-col gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
            @submit.prevent="enviar"
        >
            <h2 class="flex items-center gap-2 text-base font-semibold text-[var(--mrl-texto)]">
                <ClipboardCheck class="size-5" /> Casos prácticos · rúbrica
                oficial (5 puntos)
            </h2>
            <p class="text-sm text-[var(--mrl-texto-suave)]">
                Se otorga el punto cuando la idea es correcta, aunque el
                colaborador la exprese con sus propias palabras.
            </p>

            <article
                v-for="caso in casosPracticos"
                :key="caso.clave"
                class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] p-4"
            >
                <p class="text-sm font-medium">{{ caso.enunciado }}</p>
                <p
                    class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm whitespace-pre-line"
                >
                    {{ caso.respuesta }}
                </p>
                <label
                    v-for="criterio in caso.rubrica"
                    :key="criterio.clave"
                    class="flex cursor-pointer items-start gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="form.puntos[caso.clave][criterio.clave]"
                        @update:model-value="
                            (v) => (form.puntos[caso.clave][criterio.clave] = !!v)
                        "
                    />
                    <span
                        >{{ criterio.criterio }}
                        <span class="text-[var(--mrl-texto-suave)]"
                            >({{ criterio.puntos }} pt)</span
                        ></span
                    >
                </label>
            </article>

            <p class="text-sm font-medium">
                Puntos de casos prácticos: {{ totalD }}/5
            </p>

            <Button type="submit" class="w-fit" :disabled="form.processing">
                Guardar calificación
            </Button>
        </form>

        <form
            v-if="evaluacion.estado === 'requiere_reforzamiento'"
            class="flex flex-col gap-3 rounded-3xl border border-destructive/30 bg-destructive/5 p-5"
            @submit.prevent="enviarRetro"
        >
            <h2 class="text-base font-semibold text-[var(--mrl-texto)]">
                Retroalimentación para reforzamiento
            </h2>
            <p class="text-sm text-[var(--mrl-texto-suave)]">
                Reaplicar antes del
                {{ evaluacion.fecha_limite_reevaluacion }}.
            </p>
            <Textarea
                v-model="retroForm.retroalimentacion"
                rows="3"
                placeholder="Qué temas repasar con el colaborador…"
            />
            <Button
                type="submit"
                class="w-fit"
                :disabled="retroForm.processing"
                >Enviar y habilitar reevaluación</Button
            >
        </form>
    </div>
</template>
