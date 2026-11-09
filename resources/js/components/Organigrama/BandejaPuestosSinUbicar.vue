<script setup lang="ts">
import { ChevronDown, MapPinOff, Move } from '@lucide/vue';
import { ref } from 'vue';
import type { PuestoSinUbicar } from '@/types';

/**
 * Bandeja «Puestos sin ubicar en el organigrama» (PuestosSinUbicarService):
 * puestos activos del catálogo que no cuelgan de Dirección General (sin
 * «reporta a», superior inactivo o rama suelta). Con permiso, cada uno se
 * coloca con «Ubicar / mover puesto». En celular se ve igual (lista).
 */
defineProps<{
    puestos: PuestoSinUbicar[];
    puedeUbicar: boolean;
}>();

const emit = defineEmits<{ ubicar: [puesto: PuestoSinUbicar] }>();

const abierta = ref(true);
</script>

<template>
    <section
        v-if="puestos.length"
        class="rounded-2xl border border-dashed border-warning/40 bg-warning-soft/60"
        aria-labelledby="bandeja-sin-ubicar"
    >
        <button
            type="button"
            class="flex w-full items-center gap-2 px-4 py-3 text-left"
            :aria-expanded="abierta"
            @click="abierta = !abierta"
        >
            <MapPinOff class="size-4 shrink-0 text-warning" />
            <span id="bandeja-sin-ubicar" class="text-sm font-semibold">
                Puestos sin ubicar en el organigrama
            </span>
            <span
                class="rounded-full bg-card px-2 py-0.5 text-xs font-semibold text-warning tabular-nums"
                >{{ puestos.length }}</span
            >
            <span class="ml-2 hidden text-xs text-muted-foreground md:inline"
                >No cuelgan de Dirección General: el árbol no puede dibujarlos
                en su lugar.</span
            >
            <ChevronDown
                class="ml-auto size-4 text-muted-foreground transition-transform"
                :class="abierta ? 'rotate-180' : ''"
            />
        </button>

        <ul
            v-if="abierta"
            class="grid gap-2 px-3 pb-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <li
                v-for="puesto in puestos"
                :key="puesto.id"
                class="flex items-start gap-3 rounded-xl border border-border bg-card p-3"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">
                        {{ puesto.nombre }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ puesto.departamento ?? 'Sin departamento' }}
                        <template v-if="puesto.superior">
                            · reporta a {{ puesto.superior }}</template
                        >
                    </p>
                    <p class="mt-1 text-[11px] font-medium text-warning">
                        {{ puesto.motivo_etiqueta }}
                    </p>
                    <p
                        class="mt-0.5 truncate text-[11px] text-muted-foreground"
                    >
                        {{
                            puesto.total_ocupantes
                                ? `${puesto.ocupantes.join(', ')}${puesto.total_ocupantes > puesto.ocupantes.length ? ` y ${puesto.total_ocupantes - puesto.ocupantes.length} más` : ''}`
                                : 'Sin personas'
                        }}
                    </p>
                </div>
                <button
                    v-if="puedeUbicar"
                    type="button"
                    class="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-border bg-crema px-2.5 text-xs font-medium text-bronce hover:border-oro/60"
                    @click="emit('ubicar', puesto)"
                >
                    <Move class="size-3.5" /> Ubicar
                </button>
            </li>
        </ul>
    </section>
</template>
