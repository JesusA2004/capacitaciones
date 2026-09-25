<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Lock, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormatosTabsNav from '@/components/Rh/FormatosTabsNav.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import type { GrupoVariables } from '@/types';

/**
 * Catálogo de datos que People puede poner en un documento
 * (App\Services\Formatos\Variables\CatalogoVariablesFormato). Solo
 * consulta: agregar una variable nueva es un cambio de código documentado
 * en docs/FORMATOS_OFICIALES.md.
 */
const props = defineProps<{
    grupos: GrupoVariables[];
    formatos: Record<string, Record<string, string>>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Formatos', href: '' },
            { title: 'Variables', href: '' },
        ],
    },
});

const busqueda = ref('');
const normalizar = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const filtrados = computed(() => {
    const termino = normalizar(busqueda.value.trim());

    return props.grupos
        .map((g) => ({
            ...g,
            variables: g.variables.filter((v) => termino === '' || normalizar(`${v.etiqueta} ${v.clave} ${v.sinonimos.join(' ')}`).includes(termino)),
        }))
        .filter((g) => g.variables.length > 0);
});

const TIPO: Record<string, string> = { texto: 'Texto', fecha: 'Fecha', moneda: 'Monto', numero: 'Número', imagen: 'Imagen' };
const CONTEXTO: Record<string, string> = {
    solicitud: 'Requiere elegir la solicitud',
    prestamo: 'Requiere elegir el préstamo',
    contrato: 'Requiere elegir el contrato',
    finiquito: 'Solo desde un finiquito',
    colaborador: 'Solo colaboradores',
};
</script>

<template>
    <Head title="Variables de formatos" />

    <div class="flex w-full min-w-0 flex-col gap-4 p-4 sm:p-6">
        <FormatosTabsNav activa="variables" />

        <div class="relative w-full sm:max-w-sm">
            <Search class="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
            <Input v-model="busqueda" placeholder="Buscar dato (CURP, puesto, fecha…)" class="pl-8" />
        </div>

        <section v-for="grupo in filtrados" :key="grupo.clave" class="rounded-2xl border">
            <h2 class="border-b px-4 py-2 text-sm font-semibold">{{ grupo.etiqueta }}</h2>
            <div class="divide-y">
                <div v-for="v in grupo.variables" :key="v.clave" class="grid grid-cols-1 gap-1 px-4 py-2 text-sm md:grid-cols-[16rem_12rem_1fr]">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ v.etiqueta }}</span>
                        <Lock v-if="v.sensible" class="size-3.5 text-amber-600" aria-label="Dato salarial restringido" />
                    </div>
                    <code class="truncate text-xs text-muted-foreground">{{ v.clave }}</code>
                    <div class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        <Badge variant="outline">{{ TIPO[v.tipo] ?? v.tipo }}</Badge>
                        <span v-if="CONTEXTO[v.contexto]">{{ CONTEXTO[v.contexto] }}</span>
                        <span v-if="v.ejemplo">· Ej. {{ v.ejemplo }}</span>
                        <span v-if="formatos[v.tipo] && Object.keys(formatos[v.tipo]).length > 1">· {{ Object.keys(formatos[v.tipo]).length }} formatos</span>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
