<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';

/**
 * Un dato de la ficha: ícono + etiqueta corta + valor completo. El valor
 * nunca se corta (break-words); si está vacío dice «Sin registrar». Con
 * `href` el valor es un enlace (tel:, mailto:) y con `copiable` aparece un
 * botón para copiarlo (útil para Sistemas al dar de alta cuentas).
 */
const props = withDefaults(
    defineProps<{
        icono: Component;
        etiqueta: string;
        valor?: string | null;
        detalle?: string | null;
        href?: string | null;
        copiable?: boolean;
    }>(),
    { valor: null, detalle: null, href: null, copiable: false },
);

const vacio = computed(() => !props.valor || props.valor.trim() === '');
const copiado = ref(false);

async function copiar(): Promise<void> {
    if (!props.valor) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.valor);
        copiado.value = true;
        window.setTimeout(() => (copiado.value = false), 1600);
    } catch {
        copiado.value = false;
    }
}
</script>

<template>
    <div
        class="group flex min-w-0 items-start gap-3 rounded-xl border border-transparent bg-[var(--mrl-fondo)] px-3 py-2.5 transicion-ui hover:border-[var(--mrl-borde)] hover:bg-crema/40"
    >
        <span
            class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-[var(--mrl-superficie)] text-bronce shadow-xs transicion-ui group-hover:scale-105"
            aria-hidden="true"
        >
            <component :is="icono" class="size-4" />
        </span>
        <div class="min-w-0 flex-1">
            <dt class="text-xs text-muted-foreground">{{ etiqueta }}</dt>
            <dd class="text-sm leading-snug font-medium break-words">
                <slot>
                    <span
                        v-if="vacio"
                        class="font-normal text-muted-foreground italic"
                        >Sin registrar</span
                    >
                    <a
                        v-else-if="href"
                        :href="href"
                        class="text-[var(--mrl-petroleo)] underline-offset-2 hover:underline"
                        >{{ valor }}</a
                    >
                    <template v-else>{{ valor }}</template>
                </slot>
                <span
                    v-if="detalle"
                    class="block text-xs font-normal text-muted-foreground"
                    >{{ detalle }}</span
                >
            </dd>
        </div>
        <button
            v-if="copiable && !vacio"
            type="button"
            class="flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transicion-ui hover:bg-[var(--mrl-superficie)] hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="
                copiado ? 'Copiado' : `Copiar ${etiqueta.toLowerCase()}`
            "
            :title="copiado ? 'Copiado' : 'Copiar'"
            @click="copiar"
        >
            <Check v-if="copiado" class="size-4 text-success" />
            <Copy v-else class="size-4" />
        </button>
    </div>
</template>
