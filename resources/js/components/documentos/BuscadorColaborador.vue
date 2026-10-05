<script setup lang="ts">
import { Loader2, Search, UserRound, X } from '@lucide/vue';
import { onClickOutside, useDebounceFn } from '@vueuse/core';
import { ref, useTemplateRef, watch } from 'vue';
import { colaboradores } from '@/routes/rh/documentos-maestros';
import type { ColaboradorBusqueda } from '@/types';

/**
 * Buscador de colaboradores para "Probar con colaborador": escribe nombre
 * o número de empleado y elige de la lista (nombre, número, puesto y
 * sucursal). Busca en el servidor (dentro del alcance de quien prueba);
 * nunca se captura un ID a mano.
 */
const modelo = defineModel<ColaboradorBusqueda | null>({ default: null });

const raiz = useTemplateRef('raiz');
const termino = ref('');
const resultados = ref<ColaboradorBusqueda[]>([]);
const abierto = ref(false);
const cargando = ref(false);
const error = ref<string | null>(null);
let solicitud = 0;

onClickOutside(raiz, () => {
    abierto.value = false;
});

const buscar = useDebounceFn(async (texto: string) => {
    const actual = ++solicitud;
    cargando.value = true;
    error.value = null;

    try {
        const r = await fetch(colaboradores.url({ query: { q: texto } }), {
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
    void buscar(texto);
});

function abrir() {
    abierto.value = true;

    if (resultados.value.length === 0) {
        void buscar(termino.value);
    }
}

function elegir(c: ColaboradorBusqueda) {
    modelo.value = c;
    abierto.value = false;
}

function limpiar() {
    modelo.value = null;
    termino.value = '';
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
                    {{
                        [modelo.numero_empleado, modelo.puesto, modelo.sucursal]
                            .filter(Boolean)
                            .join(' · ')
                    }}
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
                :aria-expanded="abierto"
                aria-autocomplete="list"
                placeholder="Buscar colaborador por nombre o número de empleado…"
                class="h-10 w-full rounded-xl border border-input bg-transparent pr-9 pl-9 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                @focus="abrir"
            />
            <Loader2
                v-if="cargando"
                class="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-[var(--mrl-texto-suave)]"
            />
        </div>

        <div
            v-if="abierto && !modelo"
            class="absolute z-50 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-[var(--mrl-borde)] bg-popover p-1 shadow-lg"
            role="listbox"
        >
            <p v-if="error" class="px-3 py-2 text-sm text-destructive">
                {{ error }}
            </p>
            <p
                v-else-if="!cargando && resultados.length === 0"
                class="px-3 py-2 text-sm text-[var(--mrl-texto-suave)]"
            >
                Sin coincidencias.
            </p>
            <button
                v-for="c in resultados"
                :key="c.id"
                type="button"
                role="option"
                class="flex w-full flex-col items-start rounded-lg px-3 py-2 text-left hover:bg-muted focus:bg-muted focus:outline-none"
                @click="elegir(c)"
            >
                <span class="text-sm font-medium">{{ c.nombre }}</span>
                <span class="text-xs text-[var(--mrl-texto-suave)]">
                    {{
                        [c.numero_empleado, c.puesto, c.sucursal]
                            .filter(Boolean)
                            .join(' · ') || 'Sin puesto asignado'
                    }}
                </span>
            </button>
        </div>
    </div>
</template>
