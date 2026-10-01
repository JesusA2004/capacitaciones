<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Circle, Lock, PlayCircle } from '@lucide/vue';
import { ref } from 'vue';
import CicloEstadoPanel from '@/components/ciclo/CicloEstadoPanel.vue';
import CicloStepper from '@/components/ciclo/CicloStepper.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { evaluacion as evaluacionUrl } from '@/routes/portal/onboarding';
import type { EstadoCiclo, ModuloOnboarding, OnboardingDetalle } from '@/types';

defineProps<{
    ciclo: EstadoCiclo;
    onboarding: OnboardingDetalle | null;
    expediente: { requeridos: number; aprobados: number; faltantes: number; porcentaje: number } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi proceso', href: '' },
        ],
    },
});

const abierto = ref<number | null>(null);
const form = useForm<{ respuestas: Record<number, number> }>({ respuestas: {} });

function abrir(modulo: ModuloOnboarding) {
    abierto.value = abierto.value === modulo.avance_id ? null : modulo.avance_id;
    form.reset();
    form.clearErrors();
}

function presentar(modulo: ModuloOnboarding) {
    form.post(evaluacionUrl.url(modulo.avance_id), {
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = null;
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Mi proceso" />

    <div class="pagina-media flex flex-col gap-5">
        <div>
            <h1 class="text-xl font-semibold text-[var(--mrl-texto)]">Mi proceso</h1>
            <p class="text-sm text-[var(--mrl-texto-suave)]">{{ ciclo.persona.puesto }} · {{ ciclo.persona.sucursal }}</p>
        </div>

        <CicloStepper :pasos="ciclo.pasos" />
        <CicloEstadoPanel :ciclo="ciclo" />

        <section v-if="expediente && ciclo.etapa.clave === 'contratacion'" class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5 text-sm">
            <h2 class="mb-2 font-semibold">Mi expediente</h2>
            <p>{{ expediente.aprobados }} de {{ expediente.requeridos }} documentos aprobados · {{ expediente.faltantes }} por cargar o corregir.</p>
            <p class="mt-1 text-xs text-[var(--mrl-texto-suave)]">Carga tus documentos desde la app MR. LANA PEOPLE.</p>
        </section>

        <section v-if="onboarding" class="flex flex-col gap-3" aria-label="Inducción">
            <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">Mi inducción · {{ onboarding.estado_etiqueta }}</h2>

            <article v-for="m in onboarding.modulos" :key="m.avance_id" class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4">
                <button type="button" class="flex w-full items-center gap-3 text-left" :disabled="m.estado === 'bloqueado'" @click="abrir(m)">
                    <CheckCircle2 v-if="m.estado === 'aprobado'" class="size-5 text-[var(--mrl-verde)]" />
                    <Lock v-else-if="m.estado === 'bloqueado'" class="size-5 text-[var(--mrl-gris-verdoso)]" />
                    <PlayCircle v-else-if="m.puede_presentar" class="size-5 text-[var(--mrl-petroleo)]" />
                    <Circle v-else class="size-5 text-[var(--mrl-dorado)]" />
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium text-[var(--mrl-texto)]">{{ m.titulo }}</span>
                        <span class="block text-xs text-[var(--mrl-texto-suave)]">{{ m.tipo_etiqueta }} · {{ m.estado_etiqueta }} · mínimo {{ m.calificacion_minima }}</span>
                    </span>
                    <span v-if="m.mejor_calificacion !== null" class="text-sm font-semibold" :class="m.estado === 'aprobado' ? 'text-[var(--mrl-verde)]' : 'text-[var(--mrl-rojo)]'">{{ m.mejor_calificacion.toFixed(1) }}</span>
                </button>

                <div v-if="abierto === m.avance_id" class="mt-4 flex flex-col gap-4 border-t border-[var(--mrl-borde)] pt-4">
                    <p v-if="m.retroalimentacion" class="rounded-xl bg-[var(--mrl-dorado)]/10 p-3 text-sm">Retroalimentación de RH: {{ m.retroalimentacion }}</p>
                    <a v-if="m.contenido_url" :href="m.contenido_url" target="_blank" rel="noopener" class="text-sm font-medium text-[var(--mrl-petroleo)] hover:underline">Ver material del módulo</a>
                    <p v-if="m.contenido" class="text-sm whitespace-pre-line">{{ m.contenido }}</p>

                    <p v-if="m.estado === 'requiere_refuerzo'" class="text-sm text-[var(--mrl-rojo)]">No alcanzaste la calificación mínima. RH te dará retroalimentación y habilitará un nuevo intento.</p>

                    <form v-if="m.puede_presentar && m.preguntas.length" class="flex flex-col gap-4" @submit.prevent="presentar(m)">
                        <fieldset v-for="p in m.preguntas" :key="p.indice" class="flex flex-col gap-1.5">
                            <legend class="mb-1 text-sm font-medium">{{ p.indice + 1 }}. {{ p.pregunta }}</legend>
                            <label v-for="(opcion, i) in p.opciones" :key="i" class="flex items-center gap-2 text-sm">
                                <input v-model="form.respuestas[p.indice]" type="radio" :name="`p${m.avance_id}-${p.indice}`" :value="i" />
                                {{ opcion }}
                            </label>
                        </fieldset>
                        <InputError :message="form.errors.onboarding" />
                        <Button type="submit" class="w-fit" :disabled="form.processing || Object.keys(form.respuestas).length < m.preguntas.length">Enviar evaluación</Button>
                    </form>
                </div>
            </article>
        </section>
    </div>
</template>
