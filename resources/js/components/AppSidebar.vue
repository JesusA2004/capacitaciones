<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Bell,
    Briefcase,
    Building2,
    Cake,
    ClipboardList,
    FileStack,
    FolderKanban,
    GitBranch,
    GraduationCap,
    IdCard,
    Landmark,
    LayoutGrid,
    QrCode,
    ShieldCheck,
    Smartphone,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Button } from '@/components/ui/button';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useNavegacion } from '@/composables/useNavegacion';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard, miExpediente } from '@/routes';
import { index as indexAppReleases } from '@/routes/administracion/app-releases';
import { index as indexDepartamentos } from '@/routes/administracion/departamentos';
import { index as indexEmpresas } from '@/routes/administracion/empresas';
import { index as indexJerarquiaPuestos } from '@/routes/administracion/jerarquia-puestos';
import { index as indexPuestos } from '@/routes/administracion/puestos';
import { index as indexRoles } from '@/routes/administracion/roles';
import { index as indexSucursales } from '@/routes/administracion/sucursales';
import { index as indexUsuarios } from '@/routes/administracion/usuarios';
import { proximamente as capacitacionProximamente } from '@/routes/capacitacion';
import { index as indexNotificaciones } from '@/routes/notificaciones';
import { perfil as portalPerfil, index as indexPortal } from '@/routes/portal';
import { index as indexReportes } from '@/routes/reportes';
import { index as indexAltas } from '@/routes/rh/altas';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexCumpleanos } from '@/routes/rh/cumpleanos';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import { index as indexFormatos } from '@/routes/rh/formatos';
import { index as indexIncorporacionInvitaciones } from '@/routes/rh/incorporacion/invitaciones';
import { index as indexPlantillas } from '@/routes/rh/plantillas';
import { index as indexRhSolicitudes } from '@/routes/rh/solicitudes';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import type { NavItem } from '@/types';

const { tienePermiso } = usePermisos();
const page = usePage();
const { esColaborador, tieneAmbosModos, cambiarModo } = useNavegacion();

// El Portal RH es la experiencia principal (ver docs/PORTAL_RH.md).
// Capacitación se conserva por completo detrás del feature flag
// `capacitacion` (config/features.php, docs/CAPACITACION_PROXIMAMENTE.md):
// con la bandera apagada no se muestra ningún acceso a NADIE, ni siquiera
// super_admin — nada de "botones falsos" ni badges "Fase futura" en el
// menú mientras el módulo no esté terminado.
const capacitacionActiva = computed(() => page.props.features.capacitacion);

/**
 * Navegación del modo "colaborador": experiencia personal (mi portal, mis
 * solicitudes, mi expediente, mis notificaciones, mi perfil). Nunca incluye
 * herramientas operativas — ver docs/ROLES_Y_NAVEGACION.md.
 */
const navItemsColaborador = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Mi portal',
            href: indexPortal(),
            icon: UserRound,
        },
    ];

    if (tienePermiso('portal.solicitudes.ver') || tienePermiso('solicitudes.crear')) {
        items.push({
            title: 'Mis solicitudes',
            href: indexSolicitudes(),
            icon: ClipboardList,
        });
    }

    if (tienePermiso('expedientes.ver')) {
        items.push({
            title: 'Mi expediente',
            href: miExpediente(),
            icon: FolderKanban,
        });
    }

    if (tienePermiso('portal.notificaciones.ver')) {
        items.push({
            title: 'Mis notificaciones',
            href: indexNotificaciones(),
            icon: Bell,
        });
    }

    if (tienePermiso('portal.perfil.ver')) {
        items.push({
            title: 'Mi perfil',
            href: portalPerfil(),
            icon: IdCard,
        });
    }

    if (capacitacionActiva.value) {
        items.push({
            title: 'Capacitación',
            href: capacitacionProximamente(),
            icon: GraduationCap,
        });
    }

    return items;
});

/**
 * Navegación del modo "operativo": herramientas de RH/gerencia/dirección
 * para operar el sistema. Nunca incluye módulos personales ("Mi portal",
 * "Vacaciones" como módulo aparte) — esos viven en el modo colaborador.
 * Vacaciones vive dentro de Solicitudes (ver docs/SOLICITUDES_UNIFICADAS.md);
 * Reclutamiento se resume dentro de Vacantes; Reportes y Reportes RH son un
 * solo módulo.
 */
const navItemsOperativo = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Inicio',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (tienePermiso('expedientes.ver_todos') || tienePermiso('expedientes.ver_sucursal')) {
        items.push({
            title: 'Expedientes',
            href: indexExpedientes(),
            icon: FolderKanban,
        });
    }

    if (tienePermiso('solicitudes.revisar') || tienePermiso('solicitudes.aprobar')) {
        items.push({
            title: 'Solicitudes',
            href: indexRhSolicitudes(),
            icon: ClipboardList,
        });
    }

    if (tienePermiso('organigrama.ver')) {
        items.push({
            title: 'Organigrama',
            href: indexJerarquiaPuestos(),
            icon: GitBranch,
        });
    }

    if (tienePermiso('vacantes.ver')) {
        items.push({
            title: 'Vacantes',
            href: indexVacantes(),
            icon: Briefcase,
        });
    }

    if (tienePermiso('candidatos.ver')) {
        items.push({
            title: 'Candidatos',
            href: indexCandidatos(),
            icon: UserRound,
        });
    }

    if (tienePermiso('altas.ver')) {
        items.push({
            title: 'Altas digitales',
            href: indexAltas(),
            icon: IdCard,
        });
    }

    if (tienePermiso('rh.incorporacion.invitaciones.ver')) {
        items.push({
            title: 'Invitaciones QR',
            href: indexIncorporacionInvitaciones(),
            icon: QrCode,
        });
    }

    if (tienePermiso('formatos_oficiales.ver')) {
        items.push({
            title: 'Formatos',
            href: indexFormatos(),
            icon: FileStack,
        });
    }

    // "Plantillas avanzadas": administrar el catálogo de plantillas DOCX
    // editables y generar documentos libres desde ellas — reservado a
    // quien puede crear/editar plantillas (rh_admin/super_admin), no a RH
    // operativo (ver "Preferido" en docs/PLANTILLAS_FORMATOS.md).
    if (tienePermiso('plantillas.crear')) {
        items.push({
            title: 'Plantillas avanzadas',
            href: indexPlantillas(),
            icon: FileStack,
        });
    }

    if (tienePermiso('reportes_rh.ver')) {
        items.push({
            title: 'Reportes',
            href: indexReportes(),
            icon: Activity,
        });
    }

    if (tienePermiso('rh.cumpleanos.ver')) {
        items.push({
            title: 'Cumpleaños',
            href: indexCumpleanos(),
            icon: Cake,
        });
    }

    if (capacitacionActiva.value) {
        items.push({
            title: 'Capacitación',
            href: capacitacionProximamente(),
            icon: GraduationCap,
        });
    }

    return items;
});

const mainNavItems = computed<NavItem[]>(() =>
    esColaborador.value ? navItemsColaborador.value : navItemsOperativo.value,
);

// La Administración (catálogos de empresas/colaboradores/sucursales/
// departamentos/puestos/roles) solo existe en el modo operativo.
const adminNavItems = computed<NavItem[]>(() => {
    if (esColaborador.value) {
        return [];
    }

    const items: NavItem[] = [];

    if (tienePermiso('empresas.ver')) {
        items.push({
            title: 'Empresas',
            href: indexEmpresas(),
            icon: Landmark,
        });
    }

    if (tienePermiso('usuarios.ver')) {
        items.push({
            title: 'Colaboradores',
            href: indexUsuarios(),
            icon: Users,
        });
    }

    if (tienePermiso('sucursales.administrar')) {
        items.push({
            title: 'Sucursales',
            href: indexSucursales(),
            icon: Building2,
        });
    }

    if (tienePermiso('departamentos.administrar') || tienePermiso('puestos.administrar')) {
        items.push({
            title: 'Departamentos',
            href: indexDepartamentos(),
            icon: Briefcase,
        });
        items.push({ title: 'Puestos', href: indexPuestos(), icon: Briefcase });
    }

    if (tienePermiso('roles.administrar')) {
        items.push({
            title: 'Roles y permisos',
            href: indexRoles(),
            icon: ShieldCheck,
        });
    }

    if (tienePermiso('app_releases.ver')) {
        items.push({
            title: 'Versiones de app',
            href: indexAppReleases(),
            icon: Smartphone,
        });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <!-- Selector "Mi espacio" / "Operación RH": solo para quien tiene
                 ambos modos disponibles (ver App\Services\Navigation\NavigationService).
                 Si el usuario solo tiene uno, no se muestra selector alguno. -->
            <div
                v-if="tieneAmbosModos"
                class="mt-1 grid grid-cols-2 gap-1 rounded-lg bg-sidebar-accent/40 p-1 group-data-[collapsible=icon]:hidden"
            >
                <Button
                    :variant="esColaborador ? 'default' : 'ghost'"
                    size="sm"
                    class="h-7 text-xs"
                    @click="cambiarModo('colaborador')"
                >
                    Mi espacio
                </Button>
                <Button
                    :variant="!esColaborador ? 'default' : 'ghost'"
                    size="sm"
                    class="h-7 text-xs"
                    @click="cambiarModo('operativo')"
                >
                    Operación RH
                </Button>
            </div>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="adminNavItems.length"
                :items="adminNavItems"
                titulo="Administración"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
