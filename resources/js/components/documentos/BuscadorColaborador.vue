<script setup lang="ts">
import { Loader2, Search, UserRound, X } from '@lucide/vue';
import { onClickOutside, useDebounceFn } from '@vueuse/core';
import { nextTick, ref, useId, useTemplateRef, watch } from 'vue';
import { colaboradores } from '@/routes/rh/documentos-maestros';
import type { ColaboradorBusqueda } from '@/types';

/**
 * Buscador de colaboradores: escribe nombre, número de empleado, puesto o
 * SUCURSAL («Cuernavaca» trae a los de esa sucursal; «juan cuerna», a los
 * Juan de Cuernavaca) y elige de la lista (nombre, número, puesto y
 * sucursal). Busca en el servidor (dentro del alcance de quien pregunta,
 * criterio único BuscadorColaboradoresService); nunca se captura un ID a
 * mano. Al enfocar muestra la lista inicial sin mínimo de letras.
 * Teclado: ↑ ↓ para moverse, Enter elige, Esc cierra.
 * Reutilizado por actas, avisos, bajas, documentos, inducción… — cada quien
 * pasa su propio endpoint de búsqueda, nunca un segundo buscador.
 */
const props = withDefaults(
    defineProps<{
        buscarUrl?: (texto: string) => string;
        placeholder?: string;
    }>(),
    {
        buscarUrl: (texto: string) =>
            colaboradores.url({ query: { q: texto } }),
        placeholder: 'Buscar por nombre, número, puesto o sucursal…',
    },
);

const modelo = defineModel<ColaboradorBusqueda | null>({ default: null });

const uid = useId();
const raiz = useTemplateRef('raiz');
const lista = useTemplateRef('lista');
const termino = ref('');
const resultados = ref<ColaboradorBusqueda[]>([]);
const abierto = ref(false);
const cargando = ref(false);
const error = ref<string | null>(null);
const activo = ref(0);
let solicitud = 0;

onClickOutside(raiz, () => {
    abierto.value = false;
});

const buscar = useDebounceFn(async (texto: string) => {
    const actual = ++solicitud;
    cargando.value = true;
    error.value = null;

    try {
        const r = await fetch(props.buscarUrl(texto), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!r.ok) {
            throw new Error(
                r.status === 403
                    ? 'Sin permiso para buscar colaboradores.'
                    : 'No se pudo buscar.',
            );
        }

        const datos = (await r.json()) as { data: ColaboradorBusqueda[] };

        if (actual === solicitud) {
            resultados.value = datos.data;
            activo.value = 0;
        }
    } catch (e) {
        if (actual === solicitud) {
            error.value = e instanceof Error ? e.message : 'No se pudo buscar.';
            resultados.value = [];
        }
    } finally {
        if (actual === solicitud) {
            cargando.value = false;
        }
    }
}, 250);

watch(termino, (texto) => {
    abierto.value = true;
    cargando.value = true;
    void buscar(texto);
});

function abrir() {
    abierto.value = true;

    if (resultados.value.length === 0) {
        void buscar(termino.value);
    }
}

function elegir(c: ColaboradorBusqueda | undefined) {
    if (!c) {
        return;
    }

    modelo.value = c;
    abierto.value = false;
}

function limpiar() {
    modelo.value = null;
    termino.value = '';
}

function mover(delta: number) {
    const total = resultados.value.length;

    if (total === 0) {
        return;
    }

    activo.value = (activo.value + delta + total) % total;
    void nextTick(() => {
        lista.value
            ?.querySelector<HTMLElement>(`[data-indice="${activo.value}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    });
}

function alTeclear(evento: KeyboardEvent) {
    switch (evento.key) {
        case 'ArrowDown':
            evento.preventDefault();

            if (!abierto.value) {
                abrir();

                return;
            }

            mover(1);
            break;
        case 'ArrowUp':
            evento.preventDefault();
            mover(-1);
            break;
        case 'Enter':
            if (abierto.value) {
                evento.preventDefault();
                elegir(resultados.value[activo.value]);
            }

            break;
        case 'Escape':
            if (abierto.value) {
                evento.preventDefault();
                evento.stopPropagation();
                abierto.value = false;
            }

            break;
        case 'Tab':
            abierto.value = false;
            break;
    }
}

function detalle(c: ColaboradorBusqueda): string {
    return [c.numero_empleado, c.puesto, c.sucursal]
        .filter(Boolean)
        .join(' · ');
}

function idOpcion(i: number): string {
    return `${uid}-op-${i}`;
}
</script>

<template>
    <div ref="raiz" class="relative">
        <div
            v-if="modelo"
            class="flex items-center gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-2"
        >
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
            >
                <UserRound class="size-4" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ modelo.nombre }}</p>
                <p class="truncate text-xs text-[var(--mrl-texto-suave)]">
                    {{ detalle(modelo) }}
                </p>
            </div>
            <button
                type="button"
                class="rounded-md p-1 text-[var(--mrl-texto-suave)] hover:bg-muted"
                aria-label="Cambiar colaborador"
                @click="limpiar"
            >
                <X class="size-4" />
            </button>
        </div>

        <div v-else class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[var(--mrl-texto-suave)]"
            />
            <input
                v-model="termino"
                type="search"
                role="combobox"
                autocomplete="off"
                :aria-expanded="abierto"
                :aria-controls="`${uid}-lista`"
                :aria-activedescendant="
                    abierto && resultados.length > 0
                        ? idOpcion(activo)
                        : undefined
                "
                aria-autocomplete="list"
                :placeholder="placeholder"
                class="h-10 w-full rounded-xl border border-input bg-transparent pr-9 pl-9 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                @focus="abrir"
                @click="abrir"
                @keydown="alTeclear"
            />
            <Loader2
                v-if="cargando"
                class="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-[var(--mrl-texto-suave)]"
            />
        </div>

        <div
            v-if="abierto && !modelo"
            :id="`${uid}-lista`"
            ref="lista"
            class="absolute z-50 mt-1 max-h-72 w-full overflow-y-auto overscroll-contain rounded-xl border border-[var(--mrl-borde)] bg-popover p-1 shadow-lg"
            role="listbox"
        >
            <p v-if="error" class="px-3 py-2 text-sm text-destructive">
                {{ error }}
            </p>
            <p
                v-else-if="cargando && resultados.length === 0"
                class="px-3 py-2 text-sm text-[var(--mrl-texto-suave)]"
            >
                Buscando…
            </p>
            <p
                v-else-if="resultados.length === 0"
                class="px-3 py-2 text-sm text-[var(--mrl-texto-suave)]"
            >
                Sin coincidencias.
            </p>
            <button
                v-for="(c, i) in resultados"
                :id="idOpcion(i)"
                :key="c.id"
                type="button"
                role="option"
                tabindex="-1"
                :data-indice="i"
                :aria-selected="i === activo"
                class="flex w-full flex-col items-start rounded-lg px-3 py-2 text-left"
                :class="i === activo ? 'bg-muted' : 'hover:bg-muted'"
                @click="elegir(c)"
                @mousemove="activo = i"
            >
                <span class="text-sm font-medium">{{ c.nombre }}</span>
                <span class="text-xs text-[var(--mrl-texto-suave)]">
                    {{ detalle(c) || 'Sin puesto asignado' }}
                </span>
            </button>
            <p
                v-if="
                    !error && resultados.length >= 25 && termino.trim() === ''
                "
                class="border-t border-[var(--mrl-borde)] px-3 py-1.5 text-xs text-[var(--mrl-texto-suave)]"
            >
                Mostrando los primeros {{ resultados.length }} · escribe nombre
                o sucursal para filtrar
            </p>
        </div>
    </div>
</template>
