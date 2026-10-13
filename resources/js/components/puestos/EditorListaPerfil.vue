<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import type { EstiloLista } from '@/lib/perfilPuesto';

/**
 * Captura de una lista del descriptivo: escribes, Enter y se agrega.
 * Etiquetas (habilidades, valores…) como chips; funciones, responsabilidades
 * e indicadores como filas editables y reordenables.
 */
const props = defineProps<{
    titulo: string;
    estilo: EstiloLista;
    ejemplo: string;
    error?: string;
}>();

const items = defineModel<string[]>({ required: true });
const nuevo = ref('');

function agregar() {
    const texto = nuevo.value.trim();

    if (
        texto !== '' &&
        !items.value.includes(texto) &&
        items.value.length < 30
    ) {
        items.value = [...items.value, texto];
    }

    nuevo.value = '';
}

function quitar(i: number) {
    items.value = items.value.filter((_, j) => j !== i);
}

function mover(i: number, delta: number) {
    const destino = i + delta;

    if (destino < 0 || destino >= items.value.length) {
        return;
    }

    const copia = [...items.value];
    [copia[i], copia[destino]] = [copia[destino], copia[i]];
    items.value = copia;
}

function editar(i: number, valor: string) {
    const copia = [...items.value];
    copia[i] = valor;
    items.value = copia;
}

function alBorrar(evento: KeyboardEvent) {
    // Etiquetas: retroceso en el campo vacío quita la última.
    if (
        props.estilo === 'etiquetas' &&
        nuevo.value === '' &&
        items.value.length
    ) {
        evento.preventDefault();
        quitar(items.value.length - 1);
    }
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-baseline justify-between gap-2">
            <p class="text-sm font-medium">{{ titulo }}</p>
            <span class="text-[11px] text-muted-foreground"
                >{{ items.length }}/30</span
            >
        </div>

        <!-- Etiquetas -->
        <div
            v-if="estilo === 'etiquetas'"
            class="flex min-h-11 flex-wrap items-center gap-1.5 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-2 py-1.5 focus-within:border-[var(--mrl-petroleo)]/50 focus-within:ring-2 focus-within:ring-[var(--mrl-petroleo)]/15"
        >
            <span
                v-for="(item, i) in items"
                :key="item"
                class="inline-flex items-center gap-1 rounded-full bg-[var(--mrl-petroleo)]/[0.08] py-0.5 pr-1 pl-2.5 text-sm text-[var(--mrl-petroleo)]"
            >
                {{ item }}
                <button
                    type="button"
                    class="rounded-full p-0.5 hover:bg-[var(--mrl-petroleo)]/15"
                    :aria-label="`Quitar ${item}`"
                    @click="quitar(i)"
                >
                    <X class="size-3" />
                </button>
            </span>
            <input
                v-model="nuevo"
                type="text"
                class="min-w-[140px] flex-1 bg-transparent px-1 py-1 text-sm outline-none placeholder:text-muted-foreground"
                :placeholder="items.length ? 'Agregar…' : `Ej. ${ejemplo}`"
                @keydown.enter.prevent="agregar"
                @keydown.backspace="alBorrar"
                @blur="agregar"
            />
        </div>

        <!-- Filas -->
        <template v-else>
            <ul v-if="items.length" class="flex flex-col gap-1.5">
                <li
                    v-for="(item, i) in items"
                    :key="i"
                    class="group flex items-center gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] py-1 pr-1 pl-3"
                >
                    <span
                        v-if="estilo === 'numerada'"
                        class="flex size-5 shrink-0 items-center justify-center rounded-full bg-[var(--mrl-petroleo)] text-[10px] font-semibold text-white"
                        >{{ i + 1 }}</span
                    >
                    <input
                        :value="item"
                        type="text"
                        class="min-w-0 flex-1 bg-transparent py-1.5 text-sm outline-none"
                        :aria-label="`${titulo} ${i + 1}`"
                        @input="
                            editar(i, ($event.target as HTMLInputElement).value)
                        "
                    />
                    <div
                        class="flex shrink-0 items-center opacity-60 group-hover:opacity-100"
                    >
                        <button
                            type="button"
                            class="rounded-md p-1.5 hover:bg-[var(--mrl-fondo)] disabled:opacity-30"
                            :disabled="i === 0"
                            aria-label="Subir"
                            @click="mover(i, -1)"
                        >
                            <ArrowUp class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-1.5 hover:bg-[var(--mrl-fondo)] disabled:opacity-30"
                            :disabled="i === items.length - 1"
                            aria-label="Bajar"
                            @click="mover(i, 1)"
                        >
                            <ArrowDown class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-1.5 text-muted-foreground hover:bg-danger/10 hover:text-danger"
                            aria-label="Quitar"
                            @click="quitar(i)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>
                </li>
            </ul>
            <div
                class="flex items-center gap-2 rounded-xl border border-dashed border-[var(--mrl-borde)] py-1 pr-1 pl-3 focus-within:border-[var(--mrl-petroleo)]/50"
            >
                <input
                    v-model="nuevo"
                    type="text"
                    class="min-w-0 flex-1 bg-transparent py-1.5 text-sm outline-none placeholder:text-muted-foreground"
                    :placeholder="`Ej. ${ejemplo}`"
                    @keydown.enter.prevent="agregar"
                />
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-[var(--mrl-petroleo)] hover:bg-[var(--mrl-fondo)] disabled:opacity-40"
                    :disabled="nuevo.trim() === ''"
                    @click="agregar"
                >
                    <Plus class="size-3.5" /> Agregar
                </button>
            </div>
        </template>

        <p v-if="error" class="text-xs text-danger">{{ error }}</p>
    </div>
</template>
