<script setup lang="ts">
import { ArrowRight } from '@lucide/vue';
import RadioMarca from '@/components/Common/RadioMarca.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

/**
 * Cuerpo de una lección de «Bienvenida a MR. LANA» tal cual la ve el
 * colaborador (material + preguntas). Lo usan «Mis pendientes»
 * (Portal/MiProceso) y la vista previa de RH en Rh/Onboarding/Configuracion,
 * para que RH vea EXACTAMENTE lo mismo. En `vistaPrevia` se puede marcar
 * pero el envío queda deshabilitado.
 */
const props = withDefaults(
    defineProps<{
        leccion: {
            descripcion: string | null;
            contenido_url: string | null;
            contenido: string | null;
            retroalimentacion?: string | null;
            preguntas: {
                indice: number;
                pregunta: string;
                opciones: string[];
            }[];
            puede_presentar: boolean;
        };
        /** Prefijo para el `name` de los radios (único por lección). */
        grupo: string;
        error?: string;
        procesando?: boolean;
        vistaPrevia?: boolean;
    }>(),
    { error: undefined, procesando: false, vistaPrevia: false },
);

const respuestas = defineModel<Record<number, number>>('respuestas', {
    required: true,
});

const emit = defineEmits<{ presentar: [] }>();

function enviar() {
    if (!props.vistaPrevia) {
        emit('presentar');
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p
            v-if="leccion.retroalimentacion"
            class="rounded-xl bg-[var(--mrl-gold)]/10 p-3 text-sm"
        >
            Comentario de Recursos Humanos:
            {{ leccion.retroalimentacion }}
        </p>
        <p
            v-if="leccion.descripcion"
            class="text-sm text-[var(--mrl-texto-suave)]"
        >
            {{ leccion.descripcion }}
        </p>
        <a
            v-if="leccion.contenido_url"
            :href="leccion.contenido_url"
            target="_blank"
            rel="noopener"
            class="inline-flex w-fit items-center gap-2 rounded-xl border border-[var(--mrl-primary)]/40 px-3 py-2 text-sm font-medium text-[var(--mrl-primary)] transition-colors hover:bg-[var(--mrl-primary)]/10"
        >
            Ver el material <ArrowRight class="size-4" />
        </a>
        <p
            v-if="leccion.contenido"
            class="max-w-prose text-sm whitespace-pre-line"
        >
            {{ leccion.contenido }}
        </p>

        <form
            v-if="leccion.puede_presentar && leccion.preguntas.length"
            class="flex flex-col gap-4"
            @submit.prevent="enviar"
        >
            <fieldset
                v-for="p in leccion.preguntas"
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
                        v-model="respuestas[p.indice]"
                        :value="i"
                        :name="`${grupo}-${p.indice}`"
                    />
                    {{ opcion }}
                </label>
            </fieldset>
            <InputError :message="error" />
            <Button
                type="submit"
                class="w-fit"
                :disabled="
                    vistaPrevia ||
                    procesando ||
                    Object.keys(respuestas).length < leccion.preguntas.length
                "
                :title="
                    vistaPrevia
                        ? 'Vista previa: el envío está deshabilitado'
                        : undefined
                "
                >Enviar respuestas</Button
            >
        </form>
    </div>
</template>
