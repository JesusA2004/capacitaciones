<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Briefcase, Plus, Trash2, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import VacanteFormDialog from '@/components/Rh/VacanteFormDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { destroy, estado as estadoUrl, index } from '@/routes/rh/vacantes';
import type { OpcionesReclutamiento, VacanteItem } from '@/types';

const props = defineProps<{
    vacantes: VacanteItem[];
    filtros: {
        empresa_id?: string;
        sucursal_id?: string;
        puesto_id?: string;
    };
    opciones: OpcionesReclutamiento;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Vacantes', href: index.url() },
        ],
    },
});

const { filtros, aplicar } = useFiltros(index.url(), {
    empresa_id: props.filtros.empresa_id ?? '',
    sucursal_id: props.filtros.sucursal_id ?? '',
    puesto_id: props.filtros.puesto_id ?? '',
});
const { confirmarEliminacion, mostrarError, mostrarExito } = useAlertas();

const COLUMNAS = [
    { estado: 'abierta', titulo: 'Abierta' },
    { estado: 'en_reclutamiento', titulo: 'En reclutamiento' },
    { estado: 'con_candidatos', titulo: 'Con candidatos' },
    { estado: 'en_revision', titulo: 'En revisión' },
    { estado: 'cubierta', titulo: 'Cubierta' },
    { estado: 'cancelada', titulo: 'Cancelada' },
];

const columnas = computed(() =>
    COLUMNAS.map((columna) => ({
        ...columna,
        vacantes: props.vacantes.filter((v) => v.estado === columna.estado),
    })),
);

const dialogoAbierto = ref(false);
const seleccionada = ref<VacanteItem | null>(null);

function abrirCrear() {
    seleccionada.value = null;
    dialogoAbierto.value = true;
}

function abrirEditar(vacante: VacanteItem) {
    seleccionada.value = vacante;
    dialogoAbierto.value = true;
}

async function eliminar(vacante: VacanteItem) {
    const confirmado = await confirmarEliminacion(
        `la vacante de «${vacante.puesto?.nombre ?? 'este puesto'}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(vacante.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('Vacante eliminada.'),
        onError: () => mostrarError('No fue posible eliminar la vacante.'),
    });
}

const arrastrando = ref<number | null>(null);

function alSoltar(nuevoEstado: string) {
    if (arrastrando.value === null) {
        return;
    }

    router.put(
        estadoUrl.url(arrastrando.value),
        { estado: nuevoEstado },
        {
            preserveScroll: true,
            onError: () =>
                mostrarError('No tienes permiso para mover esta vacante.'),
        },
    );
    arrastrando.value = null;
}
</script>

<template>
    <Head title="Vacantes" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Vacantes"
            descripcion="Da seguimiento a las vacantes abiertas y su cobertura."
            :icono="Briefcase"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva vacante
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <Select
                :model-value="filtros.empresa_id"
                @update:model-value="
                    (v) => {
                        filtros.empresa_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todas las empresas"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.empresas"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.sucursal_id"
                @update:model-value="
                    (v) => {
                        filtros.sucursal_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todas las sucursales"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.sucursales"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.puesto_id"
                @update:model-value="
                    (v) => {
                        filtros.puesto_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todos los puestos"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.puestos"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </div>

        <div class="flex gap-4 overflow-x-auto pb-4">
            <div
                v-for="columna in columnas"
                :key="columna.estado"
                class="flex w-72 shrink-0 flex-col gap-3 rounded-2xl border border-border/60 bg-muted/20 p-3"
                @dragover.prevent
                @drop="alSoltar(columna.estado)"
            >
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-sm font-semibold">{{ columna.titulo }}</h3>
                    <Badge variant="outline">{{
                        columna.vacantes.length
                    }}</Badge>
                </div>

                <div class="flex flex-col gap-2">
                    <div
                        v-for="vacante in columna.vacantes"
                        :key="vacante.id"
                        draggable="true"
                        role="button"
                        tabindex="0"
                        class="group flex cursor-pointer flex-col gap-1 rounded-xl border border-border/60 bg-card p-3 text-left shadow-sm transition-colors hover:border-primary/40"
                        @dragstart="arrastrando = vacante.id"
                        @click="abrirEditar(vacante)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm font-medium">{{
                                vacante.puesto?.nombre ?? 'Sin puesto'
                            }}</span>
                            <button
                                type="button"
                                class="text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:text-destructive"
                                @click.stop="eliminar(vacante)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                        <span class="text-xs text-muted-foreground">{{
                            vacante.sucursal?.nombre ?? 'Sin sucursal'
                        }}</span>
                        <div
                            class="mt-1 flex items-center justify-between text-xs text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1">
                                <Users class="size-3" />
                                {{ vacante.candidatos_count }}
                            </span>
                            <span>{{ vacante.fecha_apertura }}</span>
                        </div>
                    </div>

                    <p
                        v-if="!columna.vacantes.length"
                        class="rounded-xl border border-dashed p-3 text-center text-xs text-muted-foreground"
                    >
                        Sin vacantes
                    </p>
                </div>
            </div>
        </div>
    </div>

    <VacanteFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :vacante="seleccionada"
        :opciones="opciones"
        :key="seleccionada?.id ?? 'nueva'"
    />
</template>
