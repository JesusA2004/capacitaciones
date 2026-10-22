<script setup lang="ts" generic="T extends string | number | null">
import { Check, ChevronDown, Loader2, Search, X } from '@lucide/vue';
import { computed, nextTick, ref, useId, watch } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { OpcionBuscable } from './tipos';

/**
 * Select con buscador (genérico y reutilizable): input arriba, la lista
 * COMPLETA al abrir (sin mínimo de letras) y filtro en vivo sin acentos ni
 * mayúsculas sobre la etiqueta, los alias y la descripción de cada opción.
 *
 * - Teclado: ↑ ↓ Inicio Fin para moverse, Enter elige, Esc cierra; escribir
 *   sobre el botón cerrado abre el buscador con esa letra.
 * - Grupos opcionales (`grupo`), chip pequeño por opción (`chip`) y
 *   opciones deshabilitadas con una nota («Ya asignado»).
 * - Se abre siempre hacia abajo con el mismo ancho del botón; solo se voltea
 *   hacia arriba si de verdad no cabe, y la lista hace scroll por dentro.
 */
const props = withDefaults(
    defineProps<{
        modelValue: T | undefined;
        opciones: OpcionBuscable[];
        placeholder?: string;
        placeholderBusqueda?: string;
        textoVacio?: string;
        /** Muestra una «x» para dejar el campo vacío (emite null). */
        limpiable?: boolean;
        /** Emite number en vez de string: para ids. */
        numerico?: boolean;
        disabled?: boolean;
        size?: 'sm' | 'default';
        id?: string;
        ariaLabel?: string;
        class?: string;
        /** Clases del botón (alto, borde…); `class` va al contenedor (ancho). */
        claseBoton?: string;
        /**
         * Búsqueda en el SERVIDOR (listas grandes de colaboradores): recibe
         * el término (vacío al abrir) y devuelve las opciones; espera 250 ms
         * entre teclas y muestra «Buscando…». Sin esto, filtra `opciones`.
         */
        buscar?: (termino: string) => Promise<OpcionBuscable[]>;
        /** Cuántas opciones se pintan a la vez («Mostrando N de M · escribe para filtrar»). */
        limite?: number;
        /** Etiqueta del valor actual cuando no viene en las opciones (búsqueda remota). */
        etiquetaSeleccionada?: string | null;
    }>(),
    {
        placeholder: 'Selecciona…',
        placeholderBusqueda: 'Escribe para buscar…',
        textoVacio: 'Sin coincidencias',
        size: 'default',
        limite: 200,
        buscar: undefined,
        etiquetaSeleccionada: null,
    },
);

const emit = defineEmits<{
    'update:modelValue': [valor: T];
    /** La opción elegida completa (null al limpiar). */
    seleccionar: [opcion: OpcionBuscable | null];
}>();

const abierto = ref(false);
const busqueda = ref('');
const activo = ref(-1);
const input = ref<HTMLInputElement | null>(null);
const lista = ref<HTMLElement | null>(null);
const idLista = `combobox-buscable-${useId()}`;

function normalizar(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

// Búsqueda remota (`buscar`).
const remoto = computed(() => props.buscar !== undefined);
const remotas = ref<OpcionBuscable[]>([]);
const cargando = ref(false);
const errorRemoto = ref<string | null>(null);
const ultimaElegida = ref<OpcionBuscable | null>(null);
let solicitud = 0;
let espera: ReturnType<typeof setTimeout> | undefined;

const fuente = computed(() =>
    remoto.value ? remotas.value : props.opciones,
);

const seleccionada = computed<OpcionBuscable | null>(() => {
    if (
        props.modelValue === null ||
        props.modelValue === undefined ||
        props.modelValue === ''
    ) {
        return null;
    }

    const actual = String(props.modelValue);
    const igual = (o: OpcionBuscable) => String(o.value) === actual;

    return (
        props.opciones.find(igual) ??
        remotas.value.find(igual) ??
        (ultimaElegida.value && igual(ultimaElegida.value)
            ? ultimaElegida.value
            : null) ??
        (props.etiquetaSeleccionada
            ? { value: actual, label: props.etiquetaSeleccionada }
            : null)
    );
});

/** Texto indexado por opción (una sola vez por lista de opciones). */
const indice = computed(() =>
    fuente.value.map((o) =>
        normalizar(
            [o.label, o.descripcion ?? '', o.grupo ?? '', ...(o.alias ?? [])].join(' '),
        ),
    ),
);

/** Todas las palabras escritas deben aparecer («juan cuernavaca»). */
const coincidencias = computed(() => {
    const palabras = normalizar(busqueda.value).split(/\s+/).filter(Boolean);

    // Remota: el servidor ya filtró con el mismo criterio.
    if (remoto.value || palabras.length === 0) {
        return fuente.value;
    }

    return fuente.value.filter((_, i) =>
        palabras.every((p) => indice.value[i].includes(p)),
    );
});

/** Lo que se pinta (las primeras `limite`); el teclado recorre esto. */
const filtradas = computed(() => coincidencias.value.slice(0, props.limite));
const ocultas = computed(
    () => coincidencias.value.length - filtradas.value.length,
);

async function ejecutarRemota(termino: string): Promise<void> {
    if (!props.buscar) {
        return;
    }

    const actual = ++solicitud;
    cargando.value = true;
    errorRemoto.value = null;

    try {
        const resultado = await props.buscar(termino);

        if (actual === solicitud) {
            remotas.value = resultado;
            activo.value = primeraHabilitada(0, 1);
        }
    } catch (e) {
        if (actual === solicitud) {
            remotas.value = [];
            errorRemoto.value =
                e instanceof Error && e.message !== ''
                    ? e.message
                    : 'No se pudo buscar.';
        }
    } finally {
        if (actual === solicitud) {
            cargando.value = false;
        }
    }
}

function buscarConEspera(termino: string): void {
    clearTimeout(espera);
    cargando.value = true;
    espera = setTimeout(() => void ejecutarRemota(termino), 250);
}

/** Grupos en el orden en que aparecen; sin `grupo` = un solo bloque. */
const grupos = computed(() => {
    const mapa = new Map<
        string,
        { opcion: OpcionBuscable; posicion: number }[]
    >();

    filtradas.value.forEach((opcion, posicion) => {
        const clave = opcion.grupo ?? '';

        if (!mapa.has(clave)) {
            mapa.set(clave, []);
        }

        mapa.get(clave)!.push({ opcion, posicion });
    });

    return [...mapa.entries()].map(([nombre, items]) => ({ nombre, items }));
});

function habilitada(posicion: number): boolean {
    const opcion = filtradas.value[posicion];

    return opcion !== undefined && !opcion.deshabilitado;
}

function primeraHabilitada(desde: number, paso: 1 | -1): number {
    const total = filtradas.value.length;

    for (let i = desde; i >= 0 && i < total; i += paso) {
        if (habilitada(i)) {
            return i;
        }
    }

    return -1;
}

function asegurarVisible(): void {
    nextTick(() => {
        lista.value
            ?.querySelector<HTMLElement>(`[data-posicion="${activo.value}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    });
}

function mover(paso: 1 | -1): void {
    const total = filtradas.value.length;

    if (total === 0) {
        return;
    }

    let siguiente = primeraHabilitada(activo.value + paso, paso);

    // Al pasar del final vuelve al inicio (y al revés).
    if (siguiente === -1) {
        siguiente = primeraHabilitada(paso === 1 ? 0 : total - 1, paso);
    }

    activo.value = siguiente;
    asegurarVisible();
}

function elegir(opcion: OpcionBuscable): void {
    if (opcion.deshabilitado) {
        return;
    }

    ultimaElegida.value = opcion;
    emit('seleccionar', opcion);
    emit(
        'update:modelValue',
        (props.numerico ? Number(opcion.value) : String(opcion.value)) as T,
    );
    abierto.value = false;
}

function limpiar(): void {
    ultimaElegida.value = null;
    emit('seleccionar', null);
    emit('update:modelValue', null as T);
}

function alTeclear(evento: KeyboardEvent): void {
    switch (evento.key) {
        case 'ArrowDown':
            evento.preventDefault();
            mover(1);
            break;
        case 'ArrowUp':
            evento.preventDefault();
            mover(-1);
            break;
        case 'Home':
            evento.preventDefault();
            activo.value = primeraHabilitada(0, 1);
            asegurarVisible();
            break;
        case 'End':
            evento.preventDefault();
            activo.value = primeraHabilitada(filtradas.value.length - 1, -1);
            asegurarVisible();
            break;
        case 'Enter': {
            evento.preventDefault();
            const opcion = filtradas.value[activo.value];

            if (opcion) {
                elegir(opcion);
            }

            break;
        }
        case 'Tab':
            abierto.value = false;
            break;
    }
}

/** Escribir sobre el botón cerrado abre el buscador con esa letra. */
function alTeclearBoton(evento: KeyboardEvent): void {
    if (props.disabled) {
        return;
    }

    if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
        evento.preventDefault();
        abierto.value = true;

        return;
    }

    if (
        evento.key.length === 1 &&
        evento.key !== ' ' &&
        !evento.ctrlKey &&
        !evento.metaKey &&
        !evento.altKey
    ) {
        evento.preventDefault();
        abierto.value = true;
        nextTick(() => {
            busqueda.value = evento.key;
        });
    }
}

function alAbrirFoco(evento: Event): void {
    evento.preventDefault();
    input.value?.focus();
}

watch(abierto, (valor) => {
    if (!valor) {
        return;
    }

    busqueda.value = '';

    if (remoto.value) {
        void ejecutarRemota('');
    }

    // Después del watcher de `busqueda`: al abrir queda resaltada la opción
    // ya elegida (o la primera elegible).
    nextTick(() => {
        if (busqueda.value !== '') {
            return;
        }

        const actual = filtradas.value.findIndex(
            (o) =>
                seleccionada.value !== null &&
                o.value === seleccionada.value.value,
        );
        activo.value =
            actual >= 0 && habilitada(actual)
                ? actual
                : primeraHabilitada(0, 1);
        asegurarVisible();
    });
});

// Al filtrar, la primera coincidencia elegible queda resaltada.
watch(busqueda, (termino) => {
    activo.value = primeraHabilitada(0, 1);
    lista.value?.scrollTo({ top: 0 });

    if (remoto.value && abierto.value) {
        buscarConEspera(termino);
    }
});
</script>

<template>
    <Popover v-model:open="abierto">
        <div :class="cn('relative w-full', props.class)">
            <PopoverTrigger as-child :disabled="disabled">
                <button
                    :id="id"
                    type="button"
                    role="combobox"
                    :aria-expanded="abierto"
                    :aria-controls="idLista"
                    aria-haspopup="listbox"
                    :aria-label="ariaLabel"
                    :disabled="disabled"
                    :data-size="size"
                    :class="
                        cn(
                            'flex w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 text-left text-sm whitespace-nowrap shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 data-[size=default]:h-9 data-[size=sm]:h-8',
                            abierto && 'border-ring ring-[3px] ring-ring/30',
                            limpiable && seleccionada && 'pr-14',
                            claseBoton,
                        )
                    "
                    @keydown="alTeclearBoton"
                >
                    <span
                        class="min-w-0 flex-1 truncate"
                        :class="!seleccionada && 'text-muted-foreground'"
                    >
                        {{ seleccionada?.label ?? placeholder }}
                    </span>
                    <ChevronDown
                        class="size-4 shrink-0 text-muted-foreground transition-transform duration-200"
                        :class="abierto && 'rotate-180'"
                    />
                </button>
            </PopoverTrigger>
            <button
                v-if="limpiable && seleccionada && !disabled"
                type="button"
                class="absolute top-1/2 right-8 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                aria-label="Quitar selección"
                @click="limpiar"
            >
                <X class="size-3.5" />
            </button>
        </div>

        <PopoverContent
            side="bottom"
            align="start"
            :side-offset="6"
            :collision-padding="12"
            avoid-collisions
            class="flex w-(--reka-popover-trigger-width) min-w-[16rem] flex-col overflow-hidden p-0"
            @open-auto-focus="alAbrirFoco"
        >
            <div class="flex items-center gap-2 border-b px-3">
                <Search class="size-4 shrink-0 text-muted-foreground" />
                <input
                    ref="input"
                    v-model="busqueda"
                    type="text"
                    role="searchbox"
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="placeholderBusqueda"
                    :aria-controls="idLista"
                    :aria-activedescendant="
                        activo >= 0 ? `${idLista}-${activo}` : undefined
                    "
                    class="h-10 w-full min-w-0 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    @keydown="alTeclear"
                />
                <Loader2
                    v-if="cargando"
                    class="size-3.5 shrink-0 animate-spin text-muted-foreground"
                />
                <button
                    v-else-if="busqueda"
                    type="button"
                    class="rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                    aria-label="Borrar búsqueda"
                    @click="
                        busqueda = '';
                        input?.focus();
                    "
                >
                    <X class="size-3.5" />
                </button>
            </div>

            <div
                :id="idLista"
                ref="lista"
                role="listbox"
                :aria-label="ariaLabel"
                class="max-h-[min(18rem,calc(var(--reka-popover-content-available-height)_-_3.5rem))] overflow-y-auto overscroll-contain p-1"
            >
                <p
                    v-if="errorRemoto"
                    class="px-3 py-6 text-center text-sm text-destructive"
                >
                    {{ errorRemoto }}
                </p>
                <p
                    v-else-if="cargando && filtradas.length === 0"
                    class="flex items-center justify-center gap-2 px-3 py-6 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Buscando…
                </p>
                <p
                    v-else-if="filtradas.length === 0"
                    class="flex flex-col items-center gap-1 px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    <Search class="size-5 opacity-50" />
                    {{ textoVacio }}
                </p>

                <div
                    v-for="grupo in grupos"
                    :key="grupo.nombre"
                    role="group"
                    :aria-label="grupo.nombre || undefined"
                >
                    <p
                        v-if="grupo.nombre"
                        class="sticky top-0 z-10 bg-popover px-2 pt-2 pb-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ grupo.nombre }}
                    </p>
                    <div
                        v-for="{ opcion, posicion } in grupo.items"
                        :id="`${idLista}-${posicion}`"
                        :key="String(opcion.value)"
                        role="option"
                        :data-posicion="posicion"
                        :aria-selected="seleccionada?.value === opcion.value"
                        :aria-disabled="opcion.deshabilitado || undefined"
                        :class="
                            cn(
                                'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm select-none',
                                posicion === activo &&
                                    'bg-accent text-accent-foreground',
                                opcion.deshabilitado &&
                                    'cursor-not-allowed opacity-55',
                            )
                        "
                        @mousemove="
                            !opcion.deshabilitado && (activo = posicion)
                        "
                        @mousedown.prevent
                        @click="elegir(opcion)"
                    >
                        <Check
                            class="size-4 shrink-0 text-primary"
                            :class="
                                seleccionada?.value === opcion.value
                                    ? 'opacity-100'
                                    : 'opacity-0'
                            "
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{
                                opcion.label
                            }}</span>
                            <span
                                v-if="opcion.descripcion"
                                class="block truncate text-xs text-muted-foreground"
                                >{{ opcion.descripcion }}</span
                            >
                        </span>
                        <span
                            v-if="opcion.deshabilitado && opcion.nota"
                            class="shrink-0 text-xs text-muted-foreground italic"
                            >{{ opcion.nota }}</span
                        >
                        <span
                            v-else-if="opcion.chip"
                            :class="
                                cn(
                                    'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium',
                                    opcion.chipTono === 'primario'
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-crema text-muted-foreground',
                                )
                            "
                            >{{ opcion.chip }}</span
                        >
                    </div>
                </div>
            </div>
            <p
                v-if="ocultas > 0"
                class="border-t px-3 py-1.5 text-xs text-muted-foreground"
            >
                Mostrando {{ filtradas.length }} de
                {{ coincidencias.length }} · escribe para filtrar
            </p>
        </PopoverContent>
    </Popover>
</template>
