<script
    setup
    lang="ts"
    generic="
        T extends {
            clave: string;
            etiqueta: string;
            categoria?: string;
            restringido?: boolean;
        }
    "
>
import {
    ArrowDown,
    ArrowUp,
    GripVertical,
    Lock,
    Plus,
    Search,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { VueDraggable } from 'vue-draggable-plus';
import { Input } from '@/components/ui/input';

/**
 * Dos paneles: a la izquierda todas las columnas disponibles, agrupadas y con
 * buscador; a la derecha las del reporte, en orden. Se arrastran entre paneles
 * y se reordenan; los botones hacen lo mismo con teclado o pantalla táctil.
 */
const props = defineProps<{
    disponibles: T[];
    tituloDisponibles?: string;
    tituloSeleccion?: string;
}>();

const seleccion = defineModel<T[]>({ required: true });
const busqueda = ref('');

const claves = computed(() => new Set(seleccion.value.map((c) => c.clave)));

const grupos = computed(() => {
    const termino = busqueda.value.trim().toLowerCase();
    const mapa = new Map<string, T[]>();

    for (const campo of props.disponibles) {
        if (
            claves.value.has(campo.clave) ||
            (termino !== '' && !campo.etiqueta.toLowerCase().includes(termino))
        ) {
            continue;
        }

        const categoria = campo.categoria ?? 'Disponibles';
        mapa.set(categoria, [...(mapa.get(categoria) ?? []), campo]);
    }

    return [...mapa.entries()].map(([categoria, campos]) => ({
        categoria,
        campos,
    }));
});

function agregar(campo: T) {
    if (!claves.value.has(campo.clave)) {
        seleccion.value = [...seleccion.value, campo];
    }
}

function quitar(clave: string) {
    seleccion.value = seleccion.value.filter((c) => c.clave !== clave);
}

function mover(indice: number, delta: number) {
    const destino = indice + delta;

    if (destino < 0 || destino >= seleccion.value.length) {
        return;
    }

    const copia = [...seleccion.value];
    const [campo] = copia.splice(indice, 1);
    copia.splice(destino, 0, campo);
    seleccion.value = copia;
}
</script>

<template>
    <div class="grid gap-3 md:grid-cols-2">
        <section
            class="flex min-h-0 flex-col rounded-xl border border-border/70 bg-card"
            data-testid="columnas-disponibles"
        >
            <header class="border-b border-border/60 p-3">
                <h3 class="text-sm font-semibold">
                    {{ tituloDisponibles ?? 'Columnas disponibles' }}
                </h3>
                <div class="relative mt-2">
                    <Search
                        class="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="busqueda"
                        class="h-8 pl-8 text-xs"
                        placeholder="Buscar columna…"
                        aria-label="Buscar columna"
                    />
                </div>
            </header>

            <div class="max-h-96 space-y-3 overflow-y-auto p-3">
                <div v-for="grupo in grupos" :key="grupo.categoria">
                    <p
                        class="mb-1 text-[11px] font-semibold tracking-wide text-bronce uppercase"
                    >
                        {{ grupo.categoria }}
                    </p>
                    <VueDraggable
                        :model-value="grupo.campos"
                        :group="{ name: 'columnas', pull: 'clone', put: false }"
                        :sort="false"
                        :animation="120"
                        :force-fallback="true"
                        class="flex flex-wrap gap-1.5"
                    >
                        <button
                            v-for="campo in grupo.campos"
                            :key="campo.clave"
                            type="button"
                            class="inline-flex cursor-grab items-center gap-1 rounded-full border border-border/70 bg-crema/60 px-2.5 py-1 text-xs hover:bg-salvia active:cursor-grabbing"
                            :data-campo="campo.clave"
                            :aria-label="`Agregar ${campo.etiqueta}`"
                            @click="agregar(campo)"
                        >
                            <Plus class="size-3 text-muted-foreground" />
                            {{ campo.etiqueta }}
                            <Lock
                                v-if="campo.restringido"
                                class="size-3 text-bronce"
                                aria-label="Dato restringido"
                            />
                        </button>
                    </VueDraggable>
                </div>
                <p
                    v-if="!grupos.length"
                    class="py-6 text-center text-xs text-muted-foreground"
                >
                    No hay más columnas para mostrar.
                </p>
            </div>
        </section>

        <section
            class="flex min-h-0 flex-col rounded-xl border border-border/70 bg-card"
            data-testid="columnas-seleccion"
        >
            <header class="border-b border-border/60 p-3">
                <h3 class="text-sm font-semibold">
                    {{ tituloSeleccion ?? 'Columnas del reporte' }}
                    <span class="font-normal text-muted-foreground">
                        · {{ seleccion.length }}
                    </span>
                </h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    Arrástralas aquí y ordénalas como quieras verlas.
                </p>
            </header>

            <VueDraggable
                v-model="seleccion"
                group="columnas"
                :animation="120"
                :force-fallback="true"
                handle=".asa-columna"
                class="max-h-96 min-h-40 flex-1 space-y-1.5 overflow-y-auto p-3"
            >
                <div
                    v-for="(campo, indice) in seleccion"
                    :key="campo.clave"
                    class="flex items-center gap-2 rounded-lg border border-border/70 bg-background px-2 py-1.5 text-sm"
                    :data-seleccionado="campo.clave"
                >
                    <GripVertical
                        class="asa-columna size-4 shrink-0 cursor-grab text-muted-foreground active:cursor-grabbing"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ campo.etiqueta }}</span>
                        <slot name="detalle" :campo="campo" :indice="indice" />
                    </span>
                    <button
                        type="button"
                        class="rounded p-1 hover:bg-muted disabled:opacity-30"
                        :disabled="indice === 0"
                        :aria-label="`Subir ${campo.etiqueta}`"
                        @click="mover(indice, -1)"
                    >
                        <ArrowUp class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        class="rounded p-1 hover:bg-muted disabled:opacity-30"
                        :disabled="indice === seleccion.length - 1"
                        :aria-label="`Bajar ${campo.etiqueta}`"
                        @click="mover(indice, 1)"
                    >
                        <ArrowDown class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        class="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        :aria-label="`Quitar ${campo.etiqueta}`"
                        @click="quitar(campo.clave)"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>
                <p
                    v-if="!seleccion.length"
                    class="rounded-lg border border-dashed border-border/70 py-10 text-center text-xs text-muted-foreground"
                >
                    Arrastra aquí las columnas del reporte.
                </p>
            </VueDraggable>
        </section>
    </div>
</template>
