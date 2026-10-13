<script setup lang="ts">
import { ArrowRight, MoreHorizontal } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { AccionCiclo } from '@/types';

/**
 * Acciones de una ficha de proceso con jerarquía clara: UNA primaria (la
 * «siguiente acción»), hasta dos secundarias visibles y el resto —incluidas
 * las que cierran o rechazan— en «Más acciones».
 */
const props = withDefaults(
    defineProps<{
        acciones: AccionCiclo[];
        siguiente?: string | null;
        /** Acciones extra que la pantalla agrega (p. ej. devolver evaluación). */
        extras?: AccionCiclo[];
    }>(),
    { siguiente: null, extras: () => [] },
);

const emit = defineEmits<{ accion: [accion: AccionCiclo] }>();

const todas = computed(() => [...props.acciones, ...props.extras]);
const primaria = computed(
    () =>
        todas.value.find((a) => a.clave === props.siguiente) ??
        todas.value.find((a) => a.tipo === 'primaria') ??
        null,
);
const resto = computed(() => todas.value.filter((a) => a !== primaria.value));
const visibles = computed(() =>
    resto.value.filter((a) => a.tipo !== 'peligro').slice(0, 2),
);
const enMenu = computed(() =>
    resto.value.filter((a) => !visibles.value.includes(a)),
);
const seguras = computed(() => enMenu.value.filter((a) => a.tipo !== 'peligro'));
const peligrosas = computed(() =>
    enMenu.value.filter((a) => a.tipo === 'peligro'),
);
</script>

<template>
    <div v-if="todas.length" class="flex flex-wrap items-center gap-2">
        <Button v-if="primaria" @click="emit('accion', primaria)">
            {{ primaria.etiqueta }} <ArrowRight class="size-4" />
        </Button>
        <Button
            v-for="a in visibles"
            :key="a.clave"
            variant="outline"
            @click="emit('accion', a)"
            >{{ a.etiqueta }}</Button
        >
        <DropdownMenu v-if="enMenu.length">
            <DropdownMenuTrigger as-child>
                <Button variant="ghost" size="icon" aria-label="Más acciones">
                    <MoreHorizontal class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-60">
                <DropdownMenuItem
                    v-for="a in seguras"
                    :key="a.clave"
                    @click="emit('accion', a)"
                    >{{ a.etiqueta }}</DropdownMenuItem
                >
                <DropdownMenuSeparator v-if="seguras.length && peligrosas.length" />
                <DropdownMenuItem
                    v-for="a in peligrosas"
                    :key="a.clave"
                    class="text-danger focus:text-danger"
                    @click="emit('accion', a)"
                    >{{ a.etiqueta }}</DropdownMenuItem
                >
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
