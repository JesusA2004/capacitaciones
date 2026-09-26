<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    KeyRound,
    Lock,
    Plus,
    Unlock,
    Users as UsersIcon,
} from '@lucide/vue';
import { ref } from 'vue';
import UsuarioFormDialog from '@/components/Administracion/UsuarioFormDialog.vue';
import UsuarioRolesDialog from '@/components/Administracion/UsuarioRolesDialog.vue';
import CrudActionMenu from '@/components/DataTable/CrudActionMenu.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudMobileCard from '@/components/DataTable/CrudMobileCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import EstablecerPasswordDialog from '@/components/Rh/EstablecerPasswordDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import {
    index,
    restablecerAcceso,
    revocarAcceso,
} from '@/routes/administracion/usuarios';
import type { UsuarioItem } from '@/types';
import type { RespuestaPaginada } from '@/types';

const props = defineProps<{
    usuarios: RespuestaPaginada<UsuarioItem>;
    filtros: { busqueda?: string };
    colaboradoresSinCuenta: { id: number; name: string; apellidos: string | null }[];
    rolesDisponibles: string[];
    estadisticas: { total: number; bloqueados: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Usuarios', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
});
const { mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'colaborador', etiqueta: 'Colaborador' },
    { clave: 'email', etiqueta: 'Correo' },
    { clave: 'roles_nombres', etiqueta: 'Roles' },
    { clave: 'acceso_bloqueado_en', etiqueta: 'Estado acceso' },
    { clave: 'email_verified_at', etiqueta: 'Correo verificado' },
    { clave: 'ultimo_acceso', etiqueta: 'Último acceso' },
];

const dialogCrearAbierto = ref(false);
const usuarioEditarRoles = ref<UsuarioItem | null>(null);
const usuarioPassword = ref<UsuarioItem | null>(null);

function abrirEditarRoles(usuario: UsuarioItem) {
    usuarioEditarRoles.value = usuario;
}

function abrirPassword(usuario: UsuarioItem) {
    usuarioPassword.value = usuario;
}

function revocar(usuario: UsuarioItem) {
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

function restablecer(usuario: UsuarioItem) {
    router.post(
        restablecerAcceso.url(usuario.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Acceso restablecido.'),
            onError: () => mostrarError('No fue posible restablecer el acceso.'),
        },
    );
}
</script>

<template>
    <Head title="Usuarios" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Usuarios"
            descripcion="Cuentas de acceso al sistema: correo, roles, estado y seguridad. Los datos laborales viven en Expedientes."
            :icono="UsersIcon"
        >
            <Button @click="dialogCrearAbierto = true">
                <Plus class="size-4" />
                Nuevo usuario
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                { etiqueta: 'Cuentas', valor: estadisticas.total, icono: UsersIcon },
                {
                    etiqueta: 'Acceso bloqueado',
                    valor: estadisticas.bloqueados,
                    icono: Lock,
                    tono: 'danger',
                },
            ]"
        />

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre o correo..."
            @update:model-value="
                (valor) => {
                    filtros.busqueda = valor;
                    aplicarConDebounce();
                }
            "
            @limpiar="limpiar"
        />

        <DataTable
            :columnas="columnas"
            :datos="usuarios"
            mensaje-vacio="No se encontraron usuarios."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="UsersIcon"
                    titulo="Todavía no hay cuentas de acceso"
                    descripcion="Crea la primera cuenta para un colaborador ya dado de alta."
                >
                    <Button size="sm" @click="dialogCrearAbierto = true">
                        <Plus class="size-4" />
                        Crear usuario
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-colaborador="{ fila }">
                <span>{{ fila.name }} {{ fila.apellidos }}</span>
            </template>
            <template #celda-roles_nombres="{ fila }">
                <div class="flex flex-wrap gap-1">
                    <Badge
                        v-for="rol in fila.roles_nombres"
                        :key="rol"
                        variant="outline"
                        class="capitalize"
                    >
                        {{ rol.replace(/_/g, ' ') }}
                    </Badge>
                    <span
                        v-if="fila.roles_nombres.length === 0"
                        class="text-xs text-muted-foreground"
                        >Sin roles</span
                    >
                </div>
            </template>
            <template #celda-acceso_bloqueado_en="{ fila }">
                <span
                    class="inline-flex items-center gap-1.5"
                    :class="fila.acceso_bloqueado_en ? 'text-destructive' : 'text-[var(--success)]'"
                >
                    <Lock v-if="fila.acceso_bloqueado_en" class="size-3.5" />
                    <CheckCircle2 v-else class="size-3.5" />
                    {{ fila.acceso_bloqueado_en ? 'Bloqueado' : 'Activo' }}
                </span>
            </template>
            <template #celda-email_verified_at="{ fila }">
                <span class="text-muted-foreground">{{
                    fila.email_verified_at ? 'Verificado' : 'Sin verificar'
                }}</span>
            </template>
            <template #celda-ultimo_acceso="{ fila }">
                <span class="whitespace-nowrap text-muted-foreground">{{
                    fila.ultimo_acceso
                        ? new Date(fila.ultimo_acceso).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' })
                        : 'Nunca'
                }}</span>
            </template>
            <template #acciones="{ fila }">
                <CrudActionMenu>
                    <DropdownMenuItem @select="abrirEditarRoles(fila)"
                        >Editar cuenta</DropdownMenuItem
                    >
                    <DropdownMenuItem @select="abrirPassword(fila)">
                        <KeyRound class="size-3.5" />
                        Establecer contraseña
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="fila.acceso_bloqueado_en"
                        @select="restablecer(fila)"
                    >
                        <Unlock class="size-3.5" />
                        Restablecer acceso
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-else
                        variant="destructive"
                        @select="revocar(fila)"
                    >
                        <Lock class="size-3.5" />
                        Revocar acceso
                    </DropdownMenuItem>
                </CrudActionMenu>
            </template>

            <template #mobile-card="{ fila }">
                <CrudMobileCard
                    :titulo="`${fila.name} ${fila.apellidos ?? ''}`"
                    :subtitulo="fila.email"
                >
                    <template #badge>
                        <span
                            class="inline-flex items-center gap-1.5 text-xs"
                            :class="fila.acceso_bloqueado_en ? 'text-destructive' : 'text-[var(--success)]'"
                        >
                            <Lock v-if="fila.acceso_bloqueado_en" class="size-3.5" />
                            <CheckCircle2 v-else class="size-3.5" />
                            {{ fila.acceso_bloqueado_en ? 'Bloqueado' : 'Activo' }}
                        </span>
                    </template>
                    <template #acciones>
                        <CrudActionMenu>
                            <DropdownMenuItem @select="abrirEditarRoles(fila)"
                                >Editar cuenta</DropdownMenuItem
                            >
                            <DropdownMenuItem @select="abrirPassword(fila)"
                                >Establecer contraseña</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="fila.acceso_bloqueado_en"
                                @select="restablecer(fila)"
                                >Restablecer acceso</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-else
                                variant="destructive"
                                @select="revocar(fila)"
                                >Revocar acceso</DropdownMenuItem
                            >
                        </CrudActionMenu>
                    </template>
                </CrudMobileCard>
            </template>
        </DataTable>
    </div>

    <UsuarioFormDialog
        v-if="dialogCrearAbierto"
        v-model:open="dialogCrearAbierto"
        :colaboradores-sin-cuenta="colaboradoresSinCuenta"
        :roles-disponibles="rolesDisponibles"
    />

    <UsuarioRolesDialog
        v-if="usuarioEditarRoles"
        :open="usuarioEditarRoles !== null"
        :usuario="usuarioEditarRoles"
        :roles-disponibles="rolesDisponibles"
        @update:open="(v) => (usuarioEditarRoles = v ? usuarioEditarRoles : null)"
    />

    <EstablecerPasswordDialog
        v-if="usuarioPassword"
        :open="usuarioPassword !== null"
        :colaborador-id="usuarioPassword.id"
        :colaborador-nombre="`${usuarioPassword.name} ${usuarioPassword.apellidos ?? ''}`"
        @update:open="(v) => (usuarioPassword = v ? usuarioPassword : null)"
    />
</template>
