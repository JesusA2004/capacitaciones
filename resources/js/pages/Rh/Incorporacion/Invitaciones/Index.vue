<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Clock, Plus, QrCode } from '@lucide/vue';
import { ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import IncorporacionInvitacionFormDialog from '@/components/Rh/IncorporacionInvitacionFormDialog.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFiltros } from '@/composables/useFiltros';
import { opcionesDe } from '@/lib/opcionesSelect';
import type { Tono } from '@/lib/tonos';
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

function elegirEstado(valor: string) {
    filtros.estado = valor;
    aplicar();
}

// Cuánto le queda a una invitación activa: menos de un día ya urge.
function vencimiento(fila: IncorporacionInvitacionItem): {
    etiqueta: string;
    tono: Tono;
} {
    const horas = (new Date(fila.expires_at).getTime() - Date.now()) / 36e5;

    if (fila.estado !== 'activo') {
        return { etiqueta: 'Cerrada', tono: 'neutro' };
    }

    if (horas <= 0) {
        return { etiqueta: 'Ya venció', tono: 'peligro' };
    }

    if (horas < 24) {
        return {
            etiqueta: `Vence en ${Math.max(1, Math.round(horas))} h`,
            tono: 'alerta',
        };
    }

    const dias = Math.round(horas / 24);

    return {
        etiqueta: `Vence en ${dias} ${dias === 1 ? 'día' : 'días'}`,
        tono: 'exito',
    };
}

const columnas: ColumnaDataTable[] = [
    { clave: 'destinatario', etiqueta: 'Destinatario' },
    { clave: 'sucursal', etiqueta: 'Sucursal' },
    { clave: 'estado', etiqueta: 'Estado' },
    { clave: 'vence', etiqueta: 'Vence' },
    {
        clave: 'creada_por',
        etiqueta: 'Creada por',
        clase: 'hidden xl:table-cell',
    },
];
</script>

<template>
    <Head title="Invitaciones de incorporación" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Invitaciones de incorporación"
            descripcion="QR temporal para que un colaborador nuevo pueda registrarse en la app. Nadie se registra sin una invitación activa."
            :icono="QrCode"
        >
            <Button
                v-if="puedeCrear"
                data-tour="invitaciones-nueva"
                @click="dialogoAbierto = true"
            >
                <Plus class="size-4" />
                Nueva invitación
            </Button>
        </CrudPageHeader>

        <div
            data-tour="invitaciones-filtros"
            class="flex flex-wrap items-center gap-2"
        >
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

            <div
                class="scroll-x-limpio flex max-w-full items-center gap-1 rounded-xl border border-border/60 bg-card p-1 shadow-sm"
                role="group"
                aria-label="Estado de la invitación"
            >
                <button
                    v-for="opcion in [
                        { value: '', etiqueta: 'Todas' },
                        ...ESTADOS,
                    ]"
                    :key="opcion.value"
                    type="button"
                    class="shrink-0 presionable rounded-lg px-3 py-1 text-sm whitespace-nowrap transicion-ui"
                    :class="
                        filtros.estado === opcion.value
                            ? 'bg-primary text-primary-foreground shadow-sm'
                            : 'text-muted-foreground hover:bg-menta hover:text-foreground'
                    "
                    :aria-pressed="filtros.estado === opcion.value"
                    @click="elegirEstado(opcion.value)"
                >
                    {{ opcion.etiqueta }}
                </button>
            </div>

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
                    <SelectSimple
                        :model-value="filtros.empresa_id"
                        :opciones="opcionesDe(opciones.empresas)"
                        placeholder="Todas"
                        opcion-vacia="Todas las empresas"
                        @update:model-value="
                            (v) => (filtros.empresa_id = String(v ?? ''))
                        "
                    />
                </div>

                <div class="grid gap-2">
                    <Label>Sucursal</Label>
                    <SelectSimple
                        :model-value="filtros.sucursal_id"
                        :opciones="opcionesDe(opciones.sucursales)"
                        placeholder="Todas"
                        opcion-vacia="Todas las sucursales"
                        @update:model-value="
                            (v) => (filtros.sucursal_id = String(v ?? ''))
                        "
                    />
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
                <div class="flex min-w-0 items-center gap-3">
                    <ColaboradorAvatar
                        :nombre="fila.nombre_prellenado ?? 'Sin nombre'"
                        tamano="sm"
                    />
                    <div class="flex min-w-0 flex-col">
                        <span class="truncate font-medium">{{
                            fila.nombre_prellenado ?? 'Sin nombre'
                        }}</span>
                        <span class="truncate text-xs text-muted-foreground">{{
                            fila.email ?? fila.codigo_legible
                        }}</span>
                    </div>
                </div>
            </template>
            <template #celda-sucursal="{ fila }">
                {{ fila.sucursal?.nombre ?? '—' }}
            </template>
            <template #celda-estado="{ fila }">
                <EstadoBadge :estado="fila.estado" />
            </template>
            <template #celda-vence="{ fila }">
                <div class="flex flex-col items-start gap-0.5">
                    <ChipEstado
                        v-if="fila.estado === 'activo'"
                        :icono="Clock"
                        :tono="vencimiento(fila).tono"
                        :etiqueta="vencimiento(fila).etiqueta"
                    />
                    <span class="text-xs text-muted-foreground">{{
                        new Date(fila.expires_at).toLocaleString('es-MX', {
                            dateStyle: 'medium',
                            timeStyle: 'short',
                        })
                    }}</span>
                </div>
            </template>
            <template #celda-creada_por="{ fila }">
                {{ fila.creado_por?.name ?? '—' }}
            </template>
            <template #acciones="{ fila }">
                <Button
                    variant="outline"
                    size="sm"
                    class="border-primary/25 bg-salvia/40 text-primary hover:bg-salvia"
                    as-child
                >
                    <Link :href="show.url(fila.id)">
                        Ver QR
                        <ArrowRight class="size-3.5 icono-avanza" />
                    </Link>
                </Button>
            </template>
        </DataTable>
    </div>

    <IncorporacionInvitacionFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :opciones="opciones"
    />
</template>
