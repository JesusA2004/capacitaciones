<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FileText, Plus } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudActionMenu from '@/components/DataTable/CrudActionMenu.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PlantillaFormDialog from '@/components/Rh/PlantillaFormDialog.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { useAlertas } from '@/composables/useAlertas';
import { destroy } from '@/routes/rh/plantillas';
import type { OpcionesPlantillas, PlantillaItem } from '@/types';

defineProps<{
    plantillas: PlantillaItem[];
    opciones: OpcionesPlantillas;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Plantillas', href: '' }],
    },
});

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
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva plantilla
            </Button>
        </CrudPageHeader>

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
