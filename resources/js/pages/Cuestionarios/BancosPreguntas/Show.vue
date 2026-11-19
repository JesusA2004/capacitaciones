<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    Circle,
    HelpCircle,
    Library,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import PreguntaFormDialog from '@/components/Cuestionarios/PreguntaFormDialog.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index } from '@/routes/bancos-preguntas';
import { destroy } from '@/routes/bancos-preguntas/preguntas';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import type { BancoPreguntaItem, PreguntaItem } from '@/types';

const props = defineProps<{
    banco: BancoPreguntaItem;
    tipos: { value: string; etiqueta: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Banco de preguntas', href: index.url() },
            { title: 'Banco', href: '#' },
        ],
    },
});

const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const dialogAbierto = ref(false);
const preguntaSeleccionada = ref<PreguntaItem | null>(null);

const preguntas = computed(() => props.banco.preguntas ?? []);
const resumenTipos = computed(() => {
    const conteo = new Map<string, number>();

    for (const p of preguntas.value) {
        conteo.set(p.tipo, (conteo.get(p.tipo) ?? 0) + 1);
    }

    return [...conteo].map(([tipo, total]) => ({ tipo, total }));
});

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

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="banco.nombre"
            :descripcion="
                banco.descripcion ??
                `${preguntas.length} pregunta(s) reutilizables en los cuestionarios.`
            "
            :icono="Library"
            detalle
        >
            <Button variant="outline" as-child>
                <Link :href="index()">
                    <ArrowLeft class="size-4" /> Bancos
                </Link>
            </Button>
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Agregar pregunta
            </Button>
        </CrudPageHeader>

        <div
            v-if="preguntas.length"
            class="flex flex-wrap gap-2"
            role="group"
            aria-label="Preguntas por tipo"
        >
            <span
                v-for="t in resumenTipos"
                :key="t.tipo"
                class="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-card px-3 py-1 text-xs"
            >
                {{ etiquetaTipo(t.tipo) }}
                <strong class="tabular-nums">{{ t.total }}</strong>
            </span>
        </div>

        <EmptyState
            v-if="preguntas.length === 0"
            :icono="HelpCircle"
            titulo="Sin preguntas"
            descripcion="Agrega la primera pregunta de este banco; después podrás usarla en cualquier cuestionario."
            class="rounded-2xl border border-dashed border-border bg-card"
        >
            <Button variant="outline" @click="abrirCrear">
                <Plus class="size-4" />
                Agregar pregunta
            </Button>
        </EmptyState>

        <ListaAnimada
            v-else
            tag="div"
            :cantidad="preguntas.length"
            class="grid grid-cols-1 gap-3 lg:grid-cols-2 2xl:grid-cols-3"
        >
            <article
                v-for="(pregunta, i) in preguntas"
                :key="pregunta.id"
                class="group flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transicion-ui hover:border-oro/40 hover:shadow-md"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-crema text-sm font-bold text-bronce tabular-nums"
                        aria-hidden="true"
                        >{{ i + 1 }}</span
                    >
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <p class="text-sm font-medium text-pretty">
                            {{ pregunta.enunciado }}
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge
                                variant="secondary"
                                class="bg-salvia text-primary"
                                >{{ etiquetaTipo(pregunta.tipo) }}</Badge
                            >
                            <Badge variant="outline"
                                >{{ pregunta.puntos }} pto(s)</Badge
                            >
                        </div>
                    </div>
                    <div
                        class="flex shrink-0 items-center gap-1 opacity-70 transicion-ui group-hover:opacity-100"
                    >
                        <Button
                            variant="ghost"
                            size="icon"
                            title="Editar pregunta"
                            aria-label="Editar pregunta"
                            @click="abrirEditar(pregunta)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            title="Eliminar pregunta"
                            aria-label="Eliminar pregunta"
                            @click="eliminar(pregunta)"
                        >
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </div>
                </div>

                <ul
                    v-if="pregunta.opciones.length"
                    class="flex flex-col gap-1 border-t border-border/60 pt-3 text-sm"
                >
                    <li
                        v-for="opcion in pregunta.opciones"
                        :key="opcion.id"
                        class="flex items-start gap-2 rounded-lg px-2 py-1"
                        :class="
                            opcion.es_correcta
                                ? 'bg-success-soft font-medium text-foreground'
                                : 'text-muted-foreground'
                        "
                    >
                        <CheckCircle2
                            v-if="opcion.es_correcta"
                            class="mt-0.5 size-4 shrink-0 text-primary"
                        />
                        <Circle v-else class="mt-0.5 size-4 shrink-0" />
                        {{ opcion.texto }}
                    </li>
                </ul>
            </article>
        </ListaAnimada>
    </div>

    <PreguntaFormDialog
        v-model:open="dialogAbierto"
        :banco="banco"
        :pregunta="preguntaSeleccionada"
        :tipos="tipos"
        :key="preguntaSeleccionada?.id ?? 'nueva'"
    />
</template>
