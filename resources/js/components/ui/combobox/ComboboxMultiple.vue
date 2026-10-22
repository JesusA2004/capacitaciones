<script setup lang="ts">
import { Check, ChevronDown, Search, X } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { cn } from '@/lib/utils';
import {
    agruparOpciones,
    coincideOpcion,
    palabrasDe,
    type OpcionCombobox,
} from './filtro';

/**
 * Igual que `Combobox` pero con selección múltiple («asignar a varias
 * personas», filtros por varias sucursales): lista completa al abrir,
 * filtro sin acentos y por palabras sobre etiqueta + descripción, teclado
 * (↑ ↓, Enter marca/desmarca, Esc cierra).
 *
 * - Sin `chips`: el control resume («3 seleccionadas»); sin nada elegido
 *   muestra `textoTodos` (una lista vacía significa «todas») y la primera
 *   opción de la lista es esa.
 * - Con `chips`: cada elegido se ve como ficha con su ✕ y no hay opción
 *   «todas» (una lista vacía es «nadie», p. ej. destinatarios).
 */
const props = withDefaults(
    defineProps<{
        items: OpcionCombobox[];
        modelValue: string[];
        textoTodos?: string;
        placeholder?: string;
        placeholderBusqueda?: string;
        emptyText?: string;
        class?: string;
        id?: string;
        ariaLabel?: string;
        disabled?: boolean;
        chips?: boolean;
        limite?: number;
    }>(),
    {
        textoTodos: 'Todas',
        placeholder: 'Selecciona…',
        placeholderBusqueda: 'Buscar…',
        emptyText: 'Sin coincidencias.',
        chips: false,
        limite: 150,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
}>();

const uid = useId();
const abierto = ref(false);
const busqueda = ref('');
const activo = ref(0);
const inputBusqueda = ref<HTMLInputElement | null>(null);
const lista = ref<HTMLElement | null>(null);

const seleccionados = computed(() =>
    props.modelValue
        .map((v) => props.items.find((item) => item.value === v))
        .filter((item): item is OpcionCombobox => item !== undefined),
);

const resumen = computed(() => {
    if (seleccionados.value.length === 0) {
        return props.chips ? props.placeholder : props.textoTodos;
    }

    if (seleccionados.value.length === 1) {
        return seleccionados.value[0].label;
    }

    return `${seleccionados.value.length} seleccionadas`;
});

const filtrados = computed(() => {
    const palabras = palabrasDe(busqueda.value);

    return props.items.filter((item) => coincideOpcion(item, palabras));
});

const recortados = computed(() => filtrados.value.slice(0, props.limite));
const grupos = computed(() => agruparOpciones(recortados.value));
const visibles = computed(() => grupos.value.flatMap((g) => g.opciones));
const indiceDe = computed(
    () => new Map(visibles.value.map((opcion, i) => [opcion, i])),
);
const ocultos = computed(() => filtrados.value.length - recortados.value.length);

/** Sin chips, la fila 0 es «todas»; las opciones empiezan en 1. */
const desfase = computed(() => (props.chips ? 0 : 1));
const totalFilas = computed(() => visibles.value.length + desfase.value);

watch(busqueda, () => {
    activo.value = desfase.value;
});

watch(abierto, (valor) => {
    if (valor) {
        busqueda.value = '';
        activo.value = 0;
    }
});

function desplazarActivo() {
    void nextTick(() => {
        lista.value
            ?.querySelector<HTMLElement>(`[data-fila="${activo.value}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    });
}

function mover(delta: number) {
    if (totalFilas.value === 0) {
        return;
    }

    activo.value = (activo.value + delta + totalFilas.value) % totalFilas.value;
    desplazarActivo();
}

function conmutar(item: OpcionCombobox | undefined) {
    if (!item || item.disabled) {
        return;
    }

    emit(
        'update:modelValue',
        props.modelValue.includes(item.value)
            ? props.modelValue.filter((v) => v !== item.value)
            : [...props.modelValue, item.value],
    );
}

function quitar(valor: string, evento?: Event) {
    evento?.stopPropagation();
    evento?.preventDefault();
    emit(
        'update:modelValue',
        props.modelValue.filter((v) => v !== valor),
    );
}

function limpiar(evento: Event) {
    evento.stopPropagation();
    evento.preventDefault();
    emit('update:modelValue', []);
}

function activarFila(fila: number) {
    if (!props.chips && fila === 0) {
        emit('update:modelValue', []);

        return;
    }

    conmutar(visibles.value[fila - desfase.value]);
}

function alTeclear(evento: KeyboardEvent) {
    switch (evento.key) {
        case 'ArrowDown':
            evento.preventDefault();
            mover(1);
            break;
        case 'ArrowUp':
            evento.preventDefault();
            mover(-1);
            break;
        case 'Enter':
            evento.preventDefault();
            activarFila(activo.value);
            break;
        case 'Backspace':
            if (busqueda.value === '' && props.modelValue.length > 0) {
                quitar(props.modelValue[props.modelValue.length - 1]);
            }

            break;
        case 'Tab':
            abierto.value = false;
            break;
    }
}

function enfocarBusqueda(evento: Event) {
    evento.preventDefault();
    inputBusqueda.value?.focus();
}

const idLista = `${uid}-lista`;
</script>

<template>
    <PopoverRoot v-model:open="abierto">
        <PopoverTrigger as-child>
            <button
                :id="id"
                type="button"
                role="combobox"
                aria-haspopup="listbox"
                :aria-expanded="abierto"
                :aria-controls="idLista"
                :aria-label="ariaLabel"
                :disabled="disabled"
                :class="
                    cn(
                        'flex min-h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-1.5 text-left text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                        props.class,
                    )
                "
                :title="seleccionados.map((s) => s.label).join(', ')"
            >
                <span
                    v-if="chips && seleccionados.length > 0"
                    class="flex min-w-0 flex-1 flex-wrap gap-1"
                >
                    <span
                        v-for="s in seleccionados"
                        :key="s.value"
                        class="inline-flex max-w-full items-center gap-1 rounded-md bg-muted px-1.5 py-0.5 text-xs"
                    >
                        <span class="truncate">{{ s.label }}</span>
                        <span
                            role="button"
                            tabindex="-1"
                            :aria-label="`Quitar ${s.label}`"
                            class="rounded-sm text-muted-foreground hover:text-foreground"
                            @click="quitar(s.value, $event)"
                            @pointerdown.stop
                        >
                            <X class="size-3" />
                        </span>
                    </span>
                </span>
                <span
                    v-else
                    class="min-w-0 truncate"
                    :class="seleccionados.length === 0 && 'text-muted-foreground'"
                >
                    {{ resumen }}
                </span>
                <span class="flex shrink-0 items-center gap-1">
                    <span
                        v-if="seleccionados.length > 0 && !disabled"
                        role="button"
                        tabindex="-1"
                        aria-label="Quitar todas"
                        class="rounded-sm p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                        @click="limpiar"
                        @pointerdown.stop
                    >
                        <X class="size-3.5" />
                    </span>
                    <ChevronDown class="size-4 text-muted-foreground/70" />
                </span>
            </button>
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                :collision-padding="8"
                class="z-50 w-(--reka-popover-trigger-width) min-w-[16rem] max-w-[calc(100vw-1rem)] overflow-hidden rounded-md border bg-popover p-0 text-popover-foreground shadow-md outline-hidden data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
                @open-auto-focus="enfocarBusqueda"
            >
                <div class="flex items-center gap-2 border-b px-2.5">
                    <Search class="size-3.5 shrink-0 text-muted-foreground" />
                    <input
                        ref="inputBusqueda"
                        v-model="busqueda"
                        type="text"
                        role="searchbox"
                        autocomplete="off"
                        :aria-controls="idLista"
                        :placeholder="placeholderBusqueda"
                        class="h-9 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        @keydown="alTeclear"
                    />
                    <span
                        v-if="modelValue.length > 0"
                        class="shrink-0 text-xs text-muted-foreground"
                    >
                        {{ modelValue.length }}
                    </span>
                </div>
                <div
                    :id="idLista"
                    ref="lista"
                    role="listbox"
                    aria-multiselectable="true"
                    class="max-h-64 overflow-y-auto overscroll-contain p-1"
                >
                    <button
                        v-if="!chips"
                        type="button"
                        role="option"
                        tabindex="-1"
                        data-fila="0"
                        :aria-selected="modelValue.length === 0"
                        :class="
                            cn(
                                'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm font-medium',
                                activo === 0 && 'bg-accent text-accent-foreground',
                            )
                        "
                        @click="activarFila(0)"
                        @mousemove="activo = 0"
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
                        v-if="visibles.length === 0"
                        class="px-2 py-3 text-center text-sm text-muted-foreground"
                    >
                        {{ emptyText }}
                    </p>
                    <template v-for="g in grupos" :key="g.grupo ?? '—'">
                        <p
                            v-if="g.grupo"
                            class="px-2 pt-2 pb-1 text-xs font-semibold text-muted-foreground"
                        >
                            {{ g.grupo }}
                        </p>
                        <button
                            v-for="item in g.opciones"
                            :key="item.value"
                            type="button"
                            role="option"
                            tabindex="-1"
                            :data-fila="(indiceDe.get(item) ?? 0) + desfase"
                            :aria-selected="modelValue.includes(item.value)"
                            :disabled="item.disabled"
                            :class="
                                cn(
                                    'flex w-full items-start gap-2 rounded-sm px-2 py-1.5 text-left text-sm disabled:cursor-not-allowed disabled:opacity-50',
                                    (indiceDe.get(item) ?? 0) + desfase === activo &&
                                        'bg-accent text-accent-foreground',
                                )
                            "
                            @click="conmutar(item)"
                            @mousemove="activo = (indiceDe.get(item) ?? 0) + desfase"
                        >
                            <span
                                :class="
                                    cn(
                                        'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-[4px] border border-input',
                                        modelValue.includes(item.value) &&
                                            'border-primary bg-primary text-primary-foreground',
                                    )
                                "
                            >
                                <Check
                                    v-if="modelValue.includes(item.value)"
                                    class="size-3"
                                />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{ item.label }}</span>
                                <span
                                    v-if="item.descripcion"
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ item.descripcion }}
                                </span>
                            </span>
                        </button>
                    </template>
                </div>
                <p
                    v-if="ocultos > 0"
                    class="border-t px-3 py-1.5 text-xs text-muted-foreground"
                >
                    Mostrando {{ recortados.length }} de
                    {{ filtrados.length }} · escribe para filtrar
                </p>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
