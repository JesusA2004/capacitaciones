<script setup lang="ts">
import { ChevronDown, GripVertical, Scale, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import type { GrupoVariablesEditor, VariableEditor } from '@/types';

/**
 * Datos disponibles para el documento, por grupo y con nombres humanos.
 * Se ARRASTRAN al documento (crea el espacio ahí) o, con un espacio
 * seleccionado, un clic se lo asigna. Los datos legales llevan una marca:
 * si el documento los pide y faltan, no se genera.
 */
const props = defineProps<{
    grupos: GrupoVariablesEditor[];
    habilitado: boolean;
    seleccionado: boolean;
}>();

const emit = defineEmits<{ elegir: [variable: VariableEditor] }>();

const busqueda = ref('');
const abiertos = ref<Set<string>>(new Set(['colaborador', 'laboral']));

function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const filtrados = computed(() => {
    const termino = normalizar(busqueda.value.trim());

    return props.grupos
        .map((g) => ({
            ...g,
            variables:
                termino === ''
                    ? g.variables
                    : g.variables.filter((v) =>
                          normalizar(`${v.etiqueta} ${g.etiqueta}`).includes(
                              termino,
                          ),
                      ),
        }))
        .filter((g) => g.variables.length > 0);
});

function alternar(clave: string) {
    const nuevos = new Set(abiertos.value);

    if (nuevos.has(clave)) {
        nuevos.delete(clave);
    } else {
        nuevos.add(clave);
    }

    abiertos.value = nuevos;
}

function arrastrar(evento: DragEvent, variable: VariableEditor) {
    evento.dataTransfer?.setData('text/x-variable', variable.clave);

    if (evento.dataTransfer) {
        evento.dataTransfer.effectAllowed = 'copy';
    }
}
</script>

<template>
    <div class="flex min-h-0 flex-col gap-2">
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="busqueda"
                type="search"
                placeholder="Buscar dato…"
                class="h-9 pl-8"
            />
        </div>
        <p class="text-xs text-muted-foreground">
            {{
                seleccionado
                    ? 'Clic para asignar al espacio seleccionado.'
                    : 'Arrastra un dato al documento.'
            }}
        </p>
        <div class="flex min-h-0 flex-col gap-1 overflow-y-auto pr-1">
            <section v-for="grupo in filtrados" :key="grupo.clave">
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase hover:bg-muted"
                    :aria-expanded="
                        abiertos.has(grupo.clave) || busqueda !== ''
                    "
                    @click="alternar(grupo.clave)"
                >
                    {{ grupo.etiqueta }}
                    <ChevronDown
                        class="size-3.5 transition-transform"
                        :class="
                            abiertos.has(grupo.clave) || busqueda !== ''
                                ? 'rotate-180'
                                : ''
                        "
                    />
                </button>
                <ul
                    v-if="abiertos.has(grupo.clave) || busqueda !== ''"
                    class="flex flex-col gap-0.5 pb-1"
                >
                    <li v-for="v in grupo.variables" :key="v.clave">
                        <button
                            type="button"
                            :draggable="habilitado"
                            :disabled="!habilitado"
                            class="flex w-full items-center gap-1.5 rounded-md border border-transparent px-2 py-1.5 text-left text-sm hover:border-border hover:bg-crema/60 disabled:opacity-60"
                            :title="
                                v.ejemplo ? `Ejemplo: ${v.ejemplo}` : undefined
                            "
                            @dragstart="arrastrar($event, v)"
                            @click="emit('elegir', v)"
                        >
                            <GripVertical
                                class="size-3.5 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                v.etiqueta
                            }}</span>
                            <Scale
                                v-if="v.legal"
                                class="size-3.5 shrink-0 text-oro"
                                aria-label="Dato legal"
                            />
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
