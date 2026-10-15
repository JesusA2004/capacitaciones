<script setup lang="ts">
import { onClickOutside } from '@vueuse/core';
import { Check, ChevronDown, Search, X } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Igual que `Combobox` pero con selección múltiple: al abrir muestra la
 * lista completa (sin mínimo de letras) y filtra mientras se escribe. Sin
 * nada elegido muestra `textoTodos` (p. ej. «Todas — general»): una lista
 * vacía significa «todas».
 */
type OpcionCombobox = { value: string; label: string };

const props = withDefaults(
    defineProps<{
        items: OpcionCombobox[];
        modelValue: string[];
        textoTodos?: string;
        placeholder?: string;
        emptyText?: string;
        class?: string;
    }>(),
    {
        textoTodos: 'Todas',
        placeholder: 'Buscar...',
        emptyText: 'Sin resultados.',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
}>();

const abierto = ref(false);
const busqueda = ref('');
const raiz = useTemplateRef('raiz');
const inputBusqueda = useTemplateRef('inputBusqueda');

onClickOutside(raiz, () => {
    abierto.value = false;
});

const seleccionados = computed(() =>
    props.items.filter((item) => props.modelValue.includes(item.value)),
);

const resumen = computed(() => {
    if (seleccionados.value.length === 0) {
        return props.textoTodos;
    }

    if (seleccionados.value.length === 1) {
        return seleccionados.value[0].label;
    }

    return `${seleccionados.value.length} seleccionadas`;
});

function normalizar(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

const itemsFiltrados = computed(() => {
    const termino = normalizar(busqueda.value.trim());

    if (termino === '') {
        return props.items;
    }

    return props.items.filter((item) =>
        normalizar(item.label).includes(termino),
    );
});

async function alternar() {
    abierto.value = !abierto.value;

    if (abierto.value) {
        busqueda.value = '';
        await nextTick();
        inputBusqueda.value?.focus();
    }
}

function conmutar(item: OpcionCombobox) {
    emit(
        'update:modelValue',
        props.modelValue.includes(item.value)
            ? props.modelValue.filter((v) => v !== item.value)
            : [...props.modelValue, item.value],
    );
}

function limpiar(evento: Event) {
    evento.stopPropagation();
    emit('update:modelValue', []);
    busqueda.value = '';
}
</script>

<template>
    <div ref="raiz" class="relative">
        <button
            type="button"
            :class="
                cn(
                    'flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    props.class,
                )
            "
            :title="seleccionados.map((s) => s.label).join(', ')"
            @click="alternar"
        >
            <span
                class="truncate"
                :class="seleccionados.length === 0 && 'text-muted-foreground'"
            >
                {{ resumen }}
            </span>
            <span class="flex shrink-0 items-center gap-1">
                <X
                    v-if="seleccionados.length > 0"
                    class="size-3.5 text-muted-foreground hover:text-foreground"
                    @click="limpiar"
                />
                <ChevronDown class="size-4 text-muted-foreground/70" />
            </span>
        </button>

        <div
            v-if="abierto"
            class="absolute z-50 mt-1 w-full min-w-[16rem] overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md"
        >
            <div class="flex items-center gap-2 border-b px-2.5">
                <Search class="size-3.5 shrink-0 text-muted-foreground" />
                <input
                    ref="inputBusqueda"
                    v-model="busqueda"
                    type="text"
                    :placeholder="placeholder"
                    class="h-9 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    @keydown.escape="abierto = false"
                />
            </div>
            <div class="max-h-64 overflow-y-auto p-1">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                    @click="emit('update:modelValue', [])"
                >
                    <Check
                        :class="
                            cn(
                                'size-3.5 shrink-0',
                                modelValue.length === 0
                                    ? 'opacity-100'
                                    : 'opacity-0',
                            )
                        "
                    />
                    <span class="truncate">{{ textoTodos }}</span>
                </button>
                <p
                    v-if="itemsFiltrados.length === 0"
                    class="px-2 py-3 text-center text-sm text-muted-foreground"
                >
                    {{ emptyText }}
                </p>
                <button
                    v-for="item in itemsFiltrados"
                    :key="item.value"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground"
                    @click="conmutar(item)"
                >
                    <Check
                        :class="
                            cn(
                                'size-3.5 shrink-0',
                                modelValue.includes(item.value)
                                    ? 'opacity-100'
                                    : 'opacity-0',
                            )
                        "
                    />
                    <span class="truncate">{{ item.label }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
