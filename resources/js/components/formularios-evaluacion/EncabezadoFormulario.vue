<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { DisenoFormulario } from '@/types';

/**
 * Encabezado de un formulario de evaluación con su diseño (banner opcional,
 * franja/encabezado/degradado del color de acento). Lo usan la página real
 * del colaborador/jefe y la vista previa del editor — siempre el mismo.
 * Requiere las variables de estiloAcento() en un ancestro.
 */
const props = withDefaults(
    defineProps<{
        titulo: string;
        descripcion?: string | null;
        diseno: DisenoFormulario;
        compacto?: boolean;
    }>(),
    { descripcion: null, compacto: false },
);

const solido = computed(() => props.diseno.estilo_encabezado === 'solido');
const degradado = computed(
    () => props.diseno.estilo_encabezado === 'degradado',
);
</script>

<template>
    <header
        :class="
            cn(
                'overflow-hidden rounded-3xl border border-border shadow-sm',
                solido
                    ? 'border-transparent bg-[var(--acento)] text-[var(--acento-texto)]'
                    : degradado
                      ? 'bg-gradient-to-br from-[color-mix(in_oklab,var(--acento)_32%,transparent)] via-card to-card'
                      : 'bg-card',
            )
        "
    >
        <img
            v-if="diseno.banner_url"
            :src="diseno.banner_url"
            alt=""
            :class="
                cn('w-full object-cover', compacto ? 'h-24' : 'h-32 sm:h-44')
            "
        />
        <div
            v-if="!solido && !degradado"
            class="h-2.5 bg-[var(--acento)]"
            aria-hidden="true"
        />
        <div :class="cn('flex flex-col gap-2', compacto ? 'p-4' : 'p-6')">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h1
                    :class="
                        cn(
                            'font-display font-semibold tracking-tight text-balance',
                            compacto ? 'text-lg' : 'text-2xl sm:text-3xl',
                        )
                    "
                >
                    {{ titulo || 'Formulario sin título' }}
                </h1>
                <slot name="etiquetas" />
            </div>
            <p
                v-if="descripcion"
                :class="
                    cn(
                        'text-sm text-pretty whitespace-pre-line',
                        solido ? 'opacity-90' : 'text-muted-foreground',
                    )
                "
            >
                {{ descripcion }}
            </p>
            <slot />
        </div>
    </header>
</template>
