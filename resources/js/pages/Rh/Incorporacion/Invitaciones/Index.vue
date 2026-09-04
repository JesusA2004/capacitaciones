<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, QrCode } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import IncorporacionInvitacionFormDialog from '@/components/Rh/IncorporacionInvitacionFormDialog.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/rh/incorporacion/invitaciones';
import type { RespuestaPaginada } from '@/types';
import type {
    IncorporacionInvitacionItem,
    OpcionesIncorporacionInvitacion,
} from '@/types/incorporacionInvitacion';

const props = defineProps<{
    invitaciones: RespuestaPaginada<IncorporacionInvitacionItem>;
    filtros: {
        estado?: string;
        empresa_id?: string;
        sucursal_id?: string;
        busqueda?: string;
    };
    opciones: OpcionesIncorporacionInvitacion;
    puedeCrear: boolean;
    puedeRegenerar: boolean;
    puedeRevocar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Invitaciones de incorporación', href: index.url() },
        ],
    },
});

const ESTADOS = [
    { value: 'activo', etiqueta: 'Activo' },
    { value: 'usado', etiqueta: 'Usado' },
    { value: 'vencido', etiqueta: 'Vencido' },
    { value: 'revocado', etiqueta: 'Revocado' },
];

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        estado: props.filtros.estado ?? '',
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        busqueda: props.filtros.busqueda ?? '',
    },
);
const filtroSheetAbierto = ref(false);
const dialogoAbierto = ref(false);

const columnas: ColumnaDataTable[] = [
    { clave: 'destinatario', etiqueta: 'Destinatario' },
    { clave: 'sucursal', etiqueta: 'Sucursal' },
    { clave: 'estado', etiqueta: 'Estado' },
    { clave: 'vence', etiqueta: 'Vence' },
    { clave: 'creada_por', etiqueta: 'Creada por' },
];
</script>

<template>
    <Head title="Invitaciones de incorporación" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Invitaciones de incorporación"
            descripcion="QR temporal para que un colaborador nuevo pueda registrarse en la app. Nadie se registra sin una invitación activa."
            :icono="QrCode"
        >
            <Button v-if="puedeCrear" @click="dialogoAbierto = true">
                <Plus class="size-4" />
                Nueva invitación
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre, correo o código..."
                @update:model-value="
                    (v) => {
                        filtros.busqueda = v;
                        aplicarConDebounce();
                    }
                "
            />

            <Select
                :model-value="filtros.estado"
                @update:model-value="
                    (v) => {
                        filtros.estado = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todos los estados"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in ESTADOS"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Empresa y sucursal de la invitación."
                :contador-activos="
                    [filtros.empresa_id, filtros.sucursal_id].filter(Boolean)
                        .length
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
            </CrudFilterSheet>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="invitaciones"
            mensaje-vacio="No se encontraron invitaciones."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="QrCode"
                    titulo="Todavía no hay invitaciones"
                    descripcion="Genera un QR temporal para que un colaborador nuevo pueda registrarse en la app."
                >
                    <Button
                        v-if="puedeCrear"
                        size="sm"
                        @click="dialogoAbierto = true"
                    >
                        <Plus class="size-4" />
                        Nueva invitación
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-destinatario="{ fila }">
                <div class="flex flex-col">
                    <span>{{ fila.nombre_prellenado ?? 'Sin nombre' }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        fila.email ?? fila.codigo_legible
                    }}</span>
                </div>
            </template>
            <template #celda-sucursal="{ fila }">
                {{ fila.sucursal?.nombre ?? '—' }}
            </template>
            <template #celda-estado="{ fila }">
                <EstadoBadge :estado="fila.estado" />
            </template>
            <template #celda-vence="{ fila }">
                {{ new Date(fila.expires_at).toLocaleString() }}
            </template>
            <template #celda-creada_por="{ fila }">
                {{ fila.creado_por?.name ?? '—' }}
            </template>
            <template #acciones="{ fila }">
                <Link
                    :href="show.url(fila.id)"
                    class="text-sm font-medium text-[var(--brand-primary)] hover:underline"
                    >Ver</Link
                >
            </template>
        </DataTable>
    </div>

    <IncorporacionInvitacionFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :opciones="opciones"
    />
</template>
