<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CandidatoFormDialog from '@/components/Rh/CandidatoFormDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { estado as estadoUrl, index, show } from '@/routes/rh/candidatos';
import type { CandidatoItem, OpcionesReclutamiento } from '@/types';

const props = defineProps<{
    candidatos: CandidatoItem[];
    filtros: {
        sucursal_id?: string;
        puesto_objetivo_id?: string;
        busqueda?: string;
    };
    opciones: OpcionesReclutamiento;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Candidatos', href: index.url() },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce } = useFiltros(index.url(), {
    sucursal_id: props.filtros.sucursal_id ?? '',
    puesto_objetivo_id: props.filtros.puesto_objetivo_id ?? '',
    busqueda: props.filtros.busqueda ?? '',
});
const { mostrarError } = useAlertas();

const COLUMNAS = props.opciones.estados ?? [];

const columnas = computed(() =>
    COLUMNAS.map((columna) => ({
        ...columna,
        candidatos: props.candidatos.filter((c) => c.estado === columna.value),
    })),
);

const dialogoAbierto = ref(false);
const seleccionado = ref<CandidatoItem | null>(null);

function abrirCrear() {
    seleccionado.value = null;
    dialogoAbierto.value = true;
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
                mostrarError('No tienes permiso para mover este candidato.'),
        },
    );
    arrastrando.value = null;
}
</script>

<template>
    <Head title="Candidatos" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Candidatos"
            descripcion="Seguimiento de prospectos y candidatos en proceso de reclutamiento."
            :icono="UserRound"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo candidato
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <Input
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre o correo..."
                class="w-64"
                @update:model-value="
                    (v) => {
                        filtros.busqueda = String(v ?? '');
                        aplicarConDebounce();
                    }
                "
            />

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
                :model-value="filtros.puesto_objetivo_id"
                @update:model-value="
                    (v) => {
                        filtros.puesto_objetivo_id = String(v ?? '');
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
                :key="columna.value"
                class="flex w-64 shrink-0 flex-col gap-3 rounded-2xl border border-border/60 bg-muted/20 p-3"
                @dragover.prevent
                @drop="alSoltar(columna.value)"
            >
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-xs font-semibold">
                        {{ columna.etiqueta }}
                    </h3>
                    <Badge variant="outline">{{
                        columna.candidatos.length
                    }}</Badge>
                </div>

                <div class="flex flex-col gap-2">
                    <Link
                        v-for="candidato in columna.candidatos"
                        :key="candidato.id"
                        :href="show.url(candidato.id)"
                        draggable="true"
                        class="flex flex-col gap-1 rounded-xl border border-border/60 bg-card p-3 text-left shadow-sm transition-colors hover:border-primary/40"
                        @dragstart="arrastrando = candidato.id"
                    >
                        <span class="text-sm font-medium">{{
                            `${candidato.nombre} ${candidato.apellidos ?? ''}`.trim()
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            candidato.puesto_objetivo?.nombre ??
                            'Sin puesto objetivo'
                        }}</span>
                        <span
                            v-if="candidato.tiene_cv"
                            class="text-xs text-muted-foreground"
                            >CV cargado</span
                        >
                    </Link>

                    <p
                        v-if="!columna.candidatos.length"
                        class="rounded-xl border border-dashed p-3 text-center text-xs text-muted-foreground"
                    >
                        Sin candidatos
                    </p>
                </div>
            </div>
        </div>
    </div>

    <CandidatoFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :candidato="seleccionado"
        :opciones="opciones"
        :key="seleccionado?.id ?? 'nuevo'"
    />
</template>
