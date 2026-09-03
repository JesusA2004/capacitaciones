<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FileSpreadsheet, FileText, Plus } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudActionMenu from '@/components/DataTable/CrudActionMenu.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import PlantillaFormDialog from '@/components/Rh/PlantillaFormDialog.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import {
    destroy,
    exportarExcel,
    exportarPdf,
    index,
} from '@/routes/rh/plantillas';
import type { OpcionesPlantillas, PlantillaItem } from '@/types';

const props = defineProps<{
    plantillas: PlantillaItem[];
    filtros: {
        tipo?: string;
        empresa_id?: string;
        sucursal_id?: string;
        puesto_id?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
    };
    opciones: OpcionesPlantillas;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Plantillas', href: '' }],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        tipo: props.filtros.tipo ?? '',
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        puesto_id: props.filtros.puesto_id ?? '',
        busqueda: props.filtros.busqueda ?? '',
        fecha_inicio: props.filtros.fecha_inicio ?? '',
        fecha_fin: props.filtros.fecha_fin ?? '',
    },
);
const filtroSheetAbierto = ref(false);
function urlExportar(
    destino: typeof exportarExcel | typeof exportarPdf,
): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();
const dialogoAbierto = ref(false);
const seleccionada = ref<PlantillaItem | null>(null);

function abrirCrear() {
    seleccionada.value = null;
    dialogoAbierto.value = true;
}

function abrirEditar(plantilla: PlantillaItem) {
    seleccionada.value = plantilla;
    dialogoAbierto.value = true;
}

async function eliminar(plantilla: PlantillaItem) {
    const confirmado = await confirmarEliminacion(
        `la plantilla «${plantilla.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(plantilla.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('Plantilla eliminada.'),
        onError: () => mostrarError('No fue posible eliminar la plantilla.'),
    });
}
</script>

<template>
    <Head title="Plantillas" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Plantillas"
            descripcion="Formatos oficiales (DOCX) que el sistema usa para generar documentos precargados."
            :icono="FileText"
        >
            <Button as-child variant="outline" size="sm">
                <a :href="urlExportar(exportarExcel)">
                    <FileSpreadsheet class="size-4" />
                    Excel
                </a>
            </Button>
            <Button as-child variant="outline" size="sm">
                <a :href="urlExportar(exportarPdf)">
                    <FileText class="size-4" />
                    PDF
                </a>
            </Button>
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva plantilla
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre..."
                @update:model-value="
                    (v) => {
                        filtros.busqueda = v;
                        aplicarConDebounce();
                    }
                "
            />

            <Select
                :model-value="filtros.tipo"
                @update:model-value="
                    (v) => {
                        filtros.tipo = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-52"
                    ><SelectValue placeholder="Todos los tipos"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.tipos"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Alcance de la plantilla y fecha de creación."
                :contador-activos="
                    [
                        filtros.empresa_id,
                        filtros.sucursal_id,
                        filtros.puesto_id,
                        filtros.fecha_inicio,
                        filtros.fecha_fin,
                    ].filter(Boolean).length
                "
                :open="filtroSheetAbierto"
                @update:open="(v) => (filtroSheetAbierto = v)"
                @aplicar="aplicar"
                @limpiar="limpiar"
            >
                <div class="grid gap-2">
                    <Label>Empresa</Label>
                    <Select
                        :model-value="filtros.empresa_id"
                        @update:model-value="
                            (v) => (filtros.empresa_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todas"
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
                </div>

                <div class="grid gap-2">
                    <Label>Sucursal</Label>
                    <Select
                        :model-value="filtros.sucursal_id"
                        @update:model-value="
                            (v) => (filtros.sucursal_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todas"
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
                </div>

                <div class="grid gap-2">
                    <Label>Puesto</Label>
                    <Select
                        :model-value="filtros.puesto_id"
                        @update:model-value="
                            (v) => (filtros.puesto_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
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

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label>Creada desde</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_inicio"
                            @update:model-value="
                                (v) => (filtros.fecha_inicio = String(v ?? ''))
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Creada hasta</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_fin"
                            @update:model-value="
                                (v) => (filtros.fecha_fin = String(v ?? ''))
                            "
                        />
                    </div>
                </div>
            </CrudFilterSheet>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <CrudEmptyState
            v-if="!plantillas.length"
            :icono="FileText"
            titulo="Todavía no hay plantillas"
            descripcion="Sube el primer formato oficial en DOCX con placeholders para empezar a generar documentos precargados."
        >
            <Button size="sm" @click="abrirCrear">
                <Plus class="size-4" />
                Nueva plantilla
            </Button>
        </CrudEmptyState>

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="plantilla in plantillas"
                :key="plantilla.id"
                class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold">
                            {{ plantilla.nombre }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ plantilla.original_name }} · v{{
                                plantilla.version
                            }}
                        </p>
                    </div>
                    <CrudActionMenu>
                        <DropdownMenuItem @select="abrirEditar(plantilla)"
                            >Editar</DropdownMenuItem
                        >
                        <DropdownMenuItem
                            variant="destructive"
                            @select="eliminar(plantilla)"
                            >Eliminar</DropdownMenuItem
                        >
                    </CrudActionMenu>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ plantilla.descripcion ?? 'Sin descripción.' }}
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <EstadoBadge
                        :estado="plantilla.activo ? 'activo' : 'inactivo'"
                    />
                    <span
                        v-if="plantilla.empresa"
                        class="text-xs text-muted-foreground"
                        >{{ plantilla.empresa.nombre }}</span
                    >
                    <span
                        v-if="plantilla.sucursal"
                        class="text-xs text-muted-foreground"
                        >· {{ plantilla.sucursal.nombre }}</span
                    >
                    <span
                        v-if="plantilla.puesto"
                        class="text-xs text-muted-foreground"
                        >· {{ plantilla.puesto.nombre }}</span
                    >
                </div>
            </div>
        </div>
    </div>

    <PlantillaFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :plantilla="seleccionada"
        :opciones="opciones"
        :key="seleccionada?.id ?? 'nueva'"
    />
</template>
