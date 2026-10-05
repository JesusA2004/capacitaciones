<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    KeyRound,
    Lock,
    Plus,
    Unlock,
    UserCheck,
    UserX,
    Users as UsersIcon,
} from '@lucide/vue';
import { ref, watch } from 'vue';
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
import InputError from '@/components/InputError.vue';
import GenerarCredencialesDialog from '@/components/Rh/GenerarCredencialesDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
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
    filtros: { busqueda?: string; estado: 'activos' | 'inactivos' | 'todos' };
    /** Prop opcional: solo llega al abrir "Nuevo usuario" (recarga parcial). */
    colaboradoresSinCuenta?: {
        id: number;
        name: string;
        apellidos: string | null;
    }[];
    rolesDisponibles: string[];
    estadisticas: { total: number; activas: number; inactivas: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Usuarios', href: index.url() },
        ],
    },
});

// Por defecto solo cuentas activas; las dadas de baja se ven en "Inactivos".
const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        estado: props.filtros.estado ?? 'activos',
    },
);

function cambiarEstado(valor: string | number) {
    filtros.estado = String(valor) as typeof filtros.estado;
    aplicar();
}
const { mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'colaborador', etiqueta: 'Colaborador' },
    { clave: 'username', etiqueta: 'Usuario' },
    { clave: 'email', etiqueta: 'Correo' },
    { clave: 'roles_nombres', etiqueta: 'Roles' },
    { clave: 'estado_cuenta', etiqueta: 'Cuenta' },
    { clave: 'estado_colaborador', etiqueta: 'Situación laboral' },
    { clave: 'email_verified_at', etiqueta: 'Correo verificado' },
    { clave: 'ultimo_acceso', etiqueta: 'Último acceso' },
];

const dialogCrearAbierto = ref(false);
const cargandoColaboradores = ref(false);

// El catálogo de colaboradores sin cuenta no viaja con el listado: se pide
// solo cuando se abre el diálogo (UsuarioController::index, Inertia::optional).
watch(dialogCrearAbierto, (abierto) => {
    if (!abierto) {
        return;
    }

    cargandoColaboradores.value = true;
    router.reload({
        only: ['colaboradoresSinCuenta'],
        onFinish: () => (cargandoColaboradores.value = false),
    });
});
const usuarioEditarRoles = ref<UsuarioItem | null>(null);
const usuarioPassword = ref<UsuarioItem | null>(null);

function abrirEditarRoles(usuario: UsuarioItem) {
    usuarioEditarRoles.value = usuario;
}

function abrirPassword(usuario: UsuarioItem) {
    usuarioPassword.value = usuario;
}

// Recién creada desde «Nuevo usuario»: se abre directo «Generar credenciales».
const credencialesNueva = ref<{
    id: number;
    name: string;
    apellidos: string | null;
} | null>(null);

// Baja de CUENTA: se confirma y se pide motivo (queda en la bitácora).
const usuarioRevocar = ref<UsuarioItem | null>(null);
const formRevocar = useForm({ motivo: '' });

function revocar(usuario: UsuarioItem) {
    formRevocar.reset();
    formRevocar.clearErrors();
    usuarioRevocar.value = usuario;
}

function confirmarRevocar() {
    if (!usuarioRevocar.value) {
        return;
    }

    formRevocar.post(revocarAcceso.url(usuarioRevocar.value.id), {
        preserveScroll: true,
        onSuccess: () => (usuarioRevocar.value = null),
        onError: () => mostrarError('No fue posible revocar el acceso.'),
    });
}

const tonoLaboral: Record<string, string> = {
    activo: 'text-[var(--success)]',
    en_incorporacion: 'text-muted-foreground',
    baja_en_tramite: 'text-[var(--warning)]',
    baja: 'text-destructive',
};

function restablecer(usuario: UsuarioItem) {
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
</script>

<template>
    <Head title="Usuarios" />

    <div class="pagina-ancha flex flex-col gap-6">
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
                {
                    etiqueta: 'Cuentas activas',
                    valor: estadisticas.activas,
                    icono: UserCheck,
                },
                {
                    etiqueta: 'Cuentas inactivas',
                    valor: estadisticas.inactivas,
                    icono: UserX,
                    tono: 'danger',
                },
            ]"
        />

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <Tabs
                :model-value="filtros.estado"
                class="shrink-0"
                @update:model-value="cambiarEstado"
            >
                <TabsList>
                    <TabsTrigger value="activos"
                        >Activos · {{ estadisticas.activas }}</TabsTrigger
                    >
                    <TabsTrigger value="inactivos"
                        >Inactivos · {{ estadisticas.inactivas }}</TabsTrigger
                    >
                    <TabsTrigger value="todos">Todos</TabsTrigger>
                </TabsList>
            </Tabs>
            <CrudToolbar
                class="flex-1"
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre, usuario o correo..."
                @update:model-value="
                    (valor) => {
                        filtros.busqueda = valor;
                        aplicarConDebounce();
                    }
                "
                @limpiar="limpiar"
            />
        </div>

        <DataTable
            :columnas="columnas"
            :datos="usuarios"
            mensaje-vacio="No se encontraron usuarios."
            filas-clicables
            @click-fila="abrirEditarRoles"
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
            <template #celda-estado_cuenta="{ fila }">
                <span
                    class="inline-flex items-center gap-1.5"
                    :class="
                        fila.estado_cuenta === 'inactiva'
                            ? 'text-destructive'
                            : 'text-[var(--success)]'
                    "
                >
                    <Lock
                        v-if="fila.estado_cuenta === 'inactiva'"
                        class="size-3.5"
                    />
                    <CheckCircle2 v-else class="size-3.5" />
                    {{
                        fila.estado_cuenta === 'inactiva'
                            ? 'Inactiva'
                            : 'Activa'
                    }}
                </span>
                <span
                    v-if="fila.motivo_inactiva"
                    class="block max-w-56 truncate text-xs text-muted-foreground"
                    :title="fila.motivo_inactiva"
                    >{{ fila.motivo_inactiva }}</span
                >
            </template>
            <template #celda-estado_colaborador="{ fila }">
                <span
                    class="text-sm"
                    :class="
                        tonoLaboral[fila.estado_colaborador.clave] ??
                        'text-muted-foreground'
                    "
                    >{{ fila.estado_colaborador.etiqueta }}</span
                >
            </template>
            <template #celda-email_verified_at="{ fila }">
                <span class="text-muted-foreground">{{
                    fila.email_verified_at ? 'Verificado' : 'Sin verificar'
                }}</span>
            </template>
            <template #celda-ultimo_acceso="{ fila }">
                <span class="whitespace-nowrap text-muted-foreground">{{
                    fila.ultimo_acceso
                        ? new Date(fila.ultimo_acceso).toLocaleString('es-MX', {
                              dateStyle: 'medium',
                              timeStyle: 'short',
                          })
                        : 'Nunca'
                }}</span>
            </template>
            <template #acciones="{ fila }">
                <CrudActionMenu>
                    <DropdownMenuItem @select="abrirEditarRoles(fila)"
                        >Editar cuenta</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        v-if="fila.colaborador_id !== null"
                        @select="abrirPassword(fila)"
                    >
                        <KeyRound class="size-3.5" />
                        Generar credenciales
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="fila.puede_restablecer"
                        @select="restablecer(fila)"
                    >
                        <Unlock class="size-3.5" />
                        Restablecer acceso
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="fila.puede_revocar"
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
                    :subtitulo="fila.username"
                    @click="abrirEditarRoles(fila)"
                >
                    <template #badge>
                        <span
                            class="inline-flex items-center gap-1.5 text-xs"
                            :class="
                                fila.estado_cuenta === 'inactiva'
                                    ? 'text-destructive'
                                    : 'text-[var(--success)]'
                            "
                        >
                            <Lock
                                v-if="fila.estado_cuenta === 'inactiva'"
                                class="size-3.5"
                            />
                            <CheckCircle2 v-else class="size-3.5" />
                            {{
                                fila.estado_cuenta === 'inactiva'
                                    ? 'Inactiva'
                                    : 'Activa'
                            }}
                            · {{ fila.estado_colaborador.etiqueta }}
                        </span>
                    </template>
                    <template #acciones>
                        <CrudActionMenu>
                            <DropdownMenuItem @select="abrirEditarRoles(fila)"
                                >Editar cuenta</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="fila.colaborador_id !== null"
                                @select="abrirPassword(fila)"
                                >Generar credenciales</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="fila.puede_restablecer"
                                @select="restablecer(fila)"
                                >Restablecer acceso</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="fila.puede_revocar"
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
        :colaboradores-sin-cuenta="colaboradoresSinCuenta ?? []"
        :cargando="cargandoColaboradores"
        :roles-disponibles="rolesDisponibles"
        @creado="(c) => (credencialesNueva = c)"
    />

    <GenerarCredencialesDialog
        v-if="credencialesNueva"
        :open="credencialesNueva !== null"
        :colaborador-id="credencialesNueva.id"
        :colaborador-nombre="`${credencialesNueva.name} ${credencialesNueva.apellidos ?? ''}`"
        :tiene-cuenta="false"
        @update:open="(v) => (credencialesNueva = v ? credencialesNueva : null)"
    />

    <UsuarioRolesDialog
        v-if="usuarioEditarRoles"
        :open="usuarioEditarRoles !== null"
        :usuario="usuarioEditarRoles"
        :roles-disponibles="rolesDisponibles"
        @update:open="
            (v) => (usuarioEditarRoles = v ? usuarioEditarRoles : null)
        "
    />

    <Dialog
        :open="usuarioRevocar !== null"
        @update:open="(abierto: boolean) => !abierto && (usuarioRevocar = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Desactivar cuenta</DialogTitle>
                <DialogDescription>
                    {{ usuarioRevocar?.name }} {{ usuarioRevocar?.apellidos }}
                    ya no podrá entrar a la web ni a la app. Es baja de la
                    CUENTA, no laboral: su expediente, contratos e historial se
                    conservan y el acceso puede restablecerse.
                </DialogDescription>
            </DialogHeader>
            <form
                class="flex flex-col gap-4"
                @submit.prevent="confirmarRevocar"
            >
                <div class="grid gap-1.5">
                    <Label for="motivo-revocar">Motivo</Label>
                    <Textarea
                        id="motivo-revocar"
                        v-model="formRevocar.motivo"
                        rows="2"
                        placeholder="Ej. Ya no requiere acceso al sistema"
                    />
                    <InputError :message="formRevocar.errors.motivo" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="usuarioRevocar = null"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="formRevocar.processing"
                        >Desactivar cuenta</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <GenerarCredencialesDialog
        v-if="usuarioPassword && usuarioPassword.colaborador_id !== null"
        :open="usuarioPassword !== null"
        :colaborador-id="usuarioPassword.colaborador_id"
        :colaborador-nombre="`${usuarioPassword.name} ${usuarioPassword.apellidos ?? ''}`"
        tiene-cuenta
        @update:open="(v) => (usuarioPassword = v ? usuarioPassword : null)"
    />
</template>
