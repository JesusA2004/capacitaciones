<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

/**
 * Selector de campo amigable para los textos editables del documento
 * (título, subtítulo, leyenda, nota, pie): en vez de que RH escriba
 * `{{ colaborador.nombre }}` a mano, elige "Nombre" dentro de "Colaborador"
 * y el placeholder real se inserta solo. Los placeholders crudos solo se
 * muestran en "Avanzado" (ver AdministrativoEditor.vue).
 */
const props = defineProps<{ campos: string[] }>();
const emit = defineEmits<{ insertar: [placeholder: string] }>();

const abierto = ref(false);

function etiqueta(texto: string): string {
    return texto
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

const grupos = computed(() => {
    const mapa = new Map<string, { campo: string; etiqueta: string }[]>();

    for (const campo of props.campos) {
        const partes = campo.split('.');
        const grupo = partes.length > 1 ? partes[0] : 'General';
        const resto = partes.length > 1 ? partes.slice(1).join(' ') : partes[0];
        const lista = mapa.get(grupo) ?? [];
        lista.push({ campo, etiqueta: etiqueta(resto) });
        mapa.set(grupo, lista);
    }

    return Array.from(mapa.entries()).map(([grupo, items]) => ({
        grupo: etiqueta(grupo),
        items,
    }));
});

function elegir(campo: string) {
    emit('insertar', `{{ ${campo} }}`);
    abierto.value = false;
}
</script>

<template>
    <Popover v-model:open="abierto">
        <PopoverTrigger as-child>
            <Button type="button" variant="outline" size="sm">
                Insertar campo
                <ChevronDown class="size-3.5" />
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-64 p-2" align="start">
            <div class="flex max-h-72 flex-col gap-3 overflow-y-auto">
                <div v-for="grupo in grupos" :key="grupo.grupo">
                    <p
                        class="px-2 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ grupo.grupo }}
                    </p>
                    <button
                        v-for="item in grupo.items"
                        :key="item.campo"
                        type="button"
                        class="block w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted"
                        @click="elegir(item.campo)"
                    >
                        {{ item.etiqueta }}
                    </button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
