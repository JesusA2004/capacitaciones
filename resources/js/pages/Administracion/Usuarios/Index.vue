<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    MailWarning,
    Plus,
    ShieldOff,
    Users,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import UsuarioFormDialog from '@/components/Administracion/UsuarioFormDialog.vue';
import CrudActionMenu from '@/components/DataTable/CrudActionMenu.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudMobileCard from '@/components/DataTable/CrudMobileCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
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
import { dashboard } from '@/routes';
import {
    index,
    restablecerAcceso,
    revocarAcceso,
} from '@/routes/administracion/usuarios';
import type {
    ColaboradorSinCuenta,
    EstadisticasUsuarios,
    RespuestaPaginada,
    UsuarioItem,
} from '@/types';

const props = defineProps<{
    usuarios: RespuestaPaginada<UsuarioItem>;
    filtros: { busqueda?: string; estado_acceso?: string };
    colaboradoresSinCuenta: ColaboradorSinCuenta[];
    rolesDisponibles: string[];
    puedeRevocarAcceso: boolean;
    estadisticas: EstadisticasUsuarios;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Usuarios', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, aplicar, limpiar } = useFiltros(
    index.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        estado_acceso: props.filtros.estado_acceso ?? '',
    },
);
const { mostrarExito, mostrarError } = useAlertas();

const contadorFiltrosActivos = computed(
    () => [filtros.estado_acceso].filter((valor) => valor !== '').length,
);

const columnas: ColumnaDataTable[] = [
    { clave: 'name', etiqueta: 'Colaborador' },
    { clave: 'email', etiqueta: 'Correo' },
    { clave: 'roles', etiqueta: 'Roles' },
    { clave: 'acceso', etiqueta: 'Acceso' },
    { clave: 'verificado', etiqueta: 'Verificado' },
    { clave: 'ultimo_acceso', etiqueta: 'Último acceso' },
];

const dialogAbierto = ref(false);
const seleccionado = ref<UsuarioItem | null>(null);

function abrirCrear() {
    seleccionado.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(usuario: UsuarioItem) {
    seleccionado.value = usuario;
    dialogAbierto.value = true;
}

function revocarAccesoUsuario(usuario: UsuarioItem) {
    router.post(
        revocarAcceso.url(usuario.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito(
                    'Acceso revocado. El colaborador sigue activo en la plantilla.',
                ),
            onError: () => mostrarError('No fue posible revocar el acceso.'),
        },
    );
}

function restablecerAccesoUsuario(usuario: UsuarioItem) {
    router.post(
        restablecerAcceso.url(usuario.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Acceso restablecido.'),
            onError: () =>
                mostrarError('No fue posible restablecer el acceso.'),
        },
    );
}

function nombreCompleto(usuario: UsuarioItem): string {
    if (!usuario.colaborador) {
        return usuario.name;
    }

    return `${usuario.colaborador.name} ${usuario.colaborador.apellidos ?? ''}`.trim();
}
</script>

<template>
    <Head title="Usuarios" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Usuarios"
            descripcion="Cuenta de acceso (correo, roles, estatus) de cada colaborador. Para su expediente y datos laborales, ve a Expedientes."
            :icono="Users"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo usuario
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                {
                    etiqueta: 'Usuarios activos',
                    valor: estadisticas.activos,
                    icono: CheckCircle2,
                    tono: 'success',
                },
                {
                    etiqueta: 'Acceso bloqueado',
                    valor: estadisticas.bloqueados,
                    icono: ShieldOff,
                    tono: 'danger',
                },
                {
                    etiqueta: 'Correo sin verificar',
                    valor: estadisticas.sin_verificar,
                    icono: MailWarning,
                },
            ]"
        />

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre, correo o número de empleado..."
            :contador-filtros-activos="contadorFiltrosActivos"
            titulo-filtros="Filtrar usuarios"
            descripcion-filtros="Acota la lista por estado de acceso."
            @update:model-value="
                (valor) => {
                    filtros.busqueda = valor;
                    aplicarConDebounce();
                }
            "
            @limpiar="limpiar"
            @aplicar-filtros="aplicar"
        >
            <template #filtros>
                <div class="grid gap-2">
                    <Label>Acceso</Label>
                    <Select
                        :model-value="filtros.estado_acceso"
                        @update:model-value="
                            (v) => (filtros.estado_acceso = String(v ?? ''))
                        "
                    >
                        <SelectTrigger class="w-full"
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="activo">Activo</SelectItem>
                            <SelectItem value="bloqueado">Bloqueado</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </template>
        </CrudToolbar>

        <DataTable
            :columnas="columnas"
            :datos="usuarios"
            mensaje-vacio="No se encontraron usuarios."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="Users"
                    titulo="Todavía no hay usuarios"
                    descripcion="Crea una cuenta de acceso para un colaborador existente."
                >
                    <Button size="sm" @click="abrirCrear">
                        <Plus class="size-4" />
                        Crear usuario
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-name="{ fila }">
                <div class="font-medium">{{ nombreCompleto(fila) }}</div>
                <div class="text-xs text-muted-foreground">
                    {{ fila.colaborador?.numero_empleado ?? 'Sin número' }} ·
                    {{ fila.colaborador?.sucursal_principal?.nombre ?? 'Sin sucursal' }}
                </div>
            </template>
            <template #celda-email="{ fila }">
                {{ fila.email }}
            </template>
            <template #celda-roles="{ fila }">
                <span class="text-sm">{{
                    fila.roles?.map((rol) => rol.name).join(', ') || '—'
                }}</span>
            </template>
            <template #celda-acceso="{ fila }">
                <span
                    v-if="fila.acceso_bloqueado_en"
                    class="inline-flex items-center gap-1 text-xs font-medium text-destructive"
                >
                    <XCircle class="size-3.5" /> Bloqueado
                </span>
                <span
                    v-else
                    class="inline-flex items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                >
                    <CheckCircle2 class="size-3.5" /> Activo
                </span>
            </template>
            <template #celda-verificado="{ fila }">
                {{ fila.email_verified_at ? 'Sí' : 'No' }}
            </template>
            <template #celda-ultimo_acceso="{ fila }">
                {{ fila.ultimo_acceso ?? 'Nunca' }}
            </template>
            <template #acciones="{ fila }">
                <CrudActionMenu>
                    <DropdownMenuItem @select="abrirEditar(fila)"
                        >Editar</DropdownMenuItem
                    >
                    <template v-if="puedeRevocarAcceso">
                        <DropdownMenuItem
                            v-if="fila.acceso_bloqueado_en"
                            @select="restablecerAccesoUsuario(fila)"
                            >Restablecer acceso</DropdownMenuItem
                        >
                        <DropdownMenuItem
                            v-else
                            @select="revocarAccesoUsuario(fila)"
                            >Revocar acceso</DropdownMenuItem
                        >
                    </template>
                </CrudActionMenu>
            </template>

            <template #mobile-card="{ fila }">
                <CrudMobileCard
                    :titulo="nombreCompleto(fila)"
                    :subtitulo="fila.email"
                >
                    <span>{{ fila.roles?.map((rol) => rol.name).join(', ') || 'Sin rol' }}</span>
                    <span>{{ fila.acceso_bloqueado_en ? 'Bloqueado' : 'Activo' }}</span>
                    <template #acciones>
                        <CrudActionMenu>
                            <DropdownMenuItem @select="abrirEditar(fila)"
                                >Editar</DropdownMenuItem
                            >
                            <template v-if="puedeRevocarAcceso">
                                <DropdownMenuItem
                                    v-if="fila.acceso_bloqueado_en"
                                    @select="restablecerAccesoUsuario(fila)"
                                    >Restablecer acceso</DropdownMenuItem
                                >
                                <DropdownMenuItem
                                    v-else
                                    @select="revocarAccesoUsuario(fila)"
                                    >Revocar acceso</DropdownMenuItem
                                >
                            </template>
                        </CrudActionMenu>
                    </template>
                </CrudMobileCard>
            </template>
        </DataTable>
    </div>

    <UsuarioFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :usuario="seleccionado"
        :colaboradores-sin-cuenta="colaboradoresSinCuenta"
        :roles-disponibles="rolesDisponibles"
        :key="seleccionado?.id ?? 'nuevo'"
    />
</template>
