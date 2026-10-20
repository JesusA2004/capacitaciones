<script setup lang="ts">
import { Check, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';

/**
 * Buscador de persona del organigrama: la lista COMPLETA se ve desde el
 * inicio (sin mínimo de letras) y se filtra en vivo mientras se escribe,
 * sin distinguir acentos. `items` viene del backend ya acotado al alcance
 * de quien asigna (OrganigramaPersonasService::candidatosCobertura).
 */
type Opcion = { value: string; label: string };

const props = defineProps<{
    items: Opcion[];
    modelValue: string;
    /** Personas que no se ofrecen (p. ej. quien ya ocupa la plaza). */
    excluir?: string[];
}>();

const emit = defineEmits<{ 'update:modelValue': [valor: string] }>();

const termino = ref('');

function normalizar(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

/** «Nombre — Puesto · Sucursal» → partes para mostrarlo en dos líneas. */
function partes(opcion: Opcion): { nombre: string; detalle: string } {
    const [nombre, ...resto] = opcion.label.split(' — ');

    return { nombre: nombre ?? opcion.label, detalle: resto.join(' — ') };
}

const filtrados = computed(() => {
    const texto = normalizar(termino.value.trim());
    const excluidos = new Set(props.excluir ?? []);

    return props.items.filter(
        (item) =>
            !excluidos.has(item.value) &&
            (texto === '' || normalizar(item.label).includes(texto)),
    );
});
</script>

<template>
    <div
        class="flex flex-col overflow-hidden rounded-2xl border border-border bg-card"
    >
        <label class="flex items-center gap-2 border-b border-border px-3">
            <Search class="size-4 shrink-0 text-muted-foreground" />
            <input
                v-model="termino"
                type="search"
                placeholder="Buscar por nombre, puesto o sucursal…"
                class="h-11 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                aria-label="Buscar colaborador"
            />
            <span class="shrink-0 text-xs text-muted-foreground tabular-nums">
                {{ filtrados.length }}
            </span>
        </label>
        <ul class="max-h-72 overflow-y-auto p-1.5" role="listbox">
            <li
                v-if="filtrados.length === 0"
                class="px-3 py-6 text-center text-sm text-muted-foreground"
            >
                Nadie coincide con «{{ termino }}».
            </li>
            <li v-for="item in filtrados" :key="item.value">
                <button
                    type="button"
                    role="option"
                    :aria-selected="item.value === modelValue"
                    class="flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition-colors"
                    :class="
                        item.value === modelValue
                            ? 'bg-salvia text-primary'
                            : 'hover:bg-muted'
                    "
                    @click="emit('update:modelValue', item.value)"
                >
                    <ColaboradorAvatar
                        :nombre="partes(item).nombre"
                        tamano="sm"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{
                            partes(item).nombre
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ partes(item).detalle }}</span
                        >
                    </span>
                    <Check
                        v-if="item.value === modelValue"
                        class="size-4 shrink-0"
                    />
                </button>
            </li>
        </ul>
    </div>
</template>
