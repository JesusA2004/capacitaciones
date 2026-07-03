<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import PreguntaFormDialog from '@/components/Cuestionarios/PreguntaFormDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index } from '@/routes/bancos-preguntas';
import { destroy } from '@/routes/bancos-preguntas/preguntas';
import type { BancoPreguntaItem, PreguntaItem } from '@/types';

const props = defineProps<{
    banco: BancoPreguntaItem;
    tipos: { value: string; etiqueta: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Banco de preguntas', href: index.url() },
            { title: 'Banco', href: '#' },
        ],
    },
});

const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const dialogAbierto = ref(false);
const preguntaSeleccionada = ref<PreguntaItem | null>(null);

const etiquetaTipo = (valor: string) =>
    props.tipos.find((tipo) => tipo.value === valor)?.etiqueta ?? valor;

function abrirCrear() {
    preguntaSeleccionada.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(pregunta: PreguntaItem) {
    preguntaSeleccionada.value = pregunta;
    dialogAbierto.value = true;
}

async function eliminar(pregunta: PreguntaItem) {
    const confirmado = await confirmarEliminacion('esta pregunta');

    if (!confirmado) {
        return;
    }

    router.delete(
        destroy.url({ banco: props.banco.id, pregunta: pregunta.id }),
        {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito('La pregunta se eliminó correctamente.'),
            onError: () => mostrarError('No fue posible eliminar la pregunta.'),
        },
    );
}
</script>

<template>
    <Head :title="banco.nombre" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="banco.nombre"
                :description="banco.descripcion ?? undefined"
            />
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Agregar pregunta
            </Button>
        </div>

        <EmptyState
            v-if="!banco.preguntas || banco.preguntas.length === 0"
            titulo="Sin preguntas"
            descripcion="Agrega la primera pregunta de este banco."
        />

        <div v-else class="flex flex-col gap-3">
            <div
                v-for="pregunta in banco.preguntas"
                :key="pregunta.id"
                class="rounded-lg border p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="text-sm font-medium">
                            {{ pregunta.enunciado }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">{{
                                etiquetaTipo(pregunta.tipo)
                            }}</Badge>
                            <Badge variant="outline"
                                >{{ pregunta.puntos }} pto(s)</Badge
                            >
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            title="Editar pregunta"
                            @click="abrirEditar(pregunta)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            title="Eliminar pregunta"
                            @click="eliminar(pregunta)"
                        >
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </div>
                </div>

                <ul
                    v-if="pregunta.opciones.length"
                    class="mt-3 space-y-1 text-sm text-muted-foreground"
                >
                    <li
                        v-for="opcion in pregunta.opciones"
                        :key="opcion.id"
                        :class="{
                            'font-medium text-foreground': opcion.es_correcta,
                        }"
                    >
                        {{ opcion.es_correcta ? '✓' : '—' }} {{ opcion.texto }}
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <PreguntaFormDialog
        v-model:open="dialogAbierto"
        :banco="banco"
        :pregunta="preguntaSeleccionada"
        :tipos="tipos"
        :key="preguntaSeleccionada?.id ?? 'nueva'"
    />
</template>
