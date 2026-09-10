<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Briefcase,
    Building2,
    Cake,
    CalendarDays,
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
    Users2,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
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
import { index as indexPortal } from '@/routes/portal';
import { reclutamiento as indexReclutamiento } from '@/routes/rh';
import { index as indexAltas } from '@/routes/rh/altas';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexCumpleanos } from '@/routes/rh/cumpleanos';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import { index as indexFormatos } from '@/routes/rh/formatos';
import { index as indexIncorporacionInvitaciones } from '@/routes/rh/incorporacion/invitaciones';
import { index as indexPlantillas } from '@/routes/rh/plantillas';
import { index as indexReportesRh } from '@/routes/rh/reportes';
import { index as indexRhSolicitudes } from '@/routes/rh/solicitudes';
import { index as indexRhVacaciones } from '@/routes/rh/vacaciones';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import { index as indexVacaciones } from '@/routes/vacaciones';
import type { NavItem } from '@/types';

const { tienePermiso, tieneRol } = usePermisos();
const page = usePage();

// El Portal RH es la experiencia principal (ver docs/PORTAL_RH.md).
// Capacitación se conserva por completo detrás del feature flag
// `capacitacion` (config/features.php, docs/CAPACITACION_PROXIMAMENTE.md):
// con la bandera apagada no se muestra ningún acceso a colaboradores
// normales — nada de "botones falsos" ni textos "Próximamente" para el
// público general. Solo super_admin (o un ambiente que no sea producción,
// para poder revisar la pantalla mientras se desarrolla) sigue viendo un
// acceso, marcado explícitamente como interno.
const capacitacionActiva = computed(() => page.props.features.capacitacion);
const capacitacionVisibleParaAdmin = computed(
    () =>
        !capacitacionActiva.value &&
        (tieneRol('super_admin') || page.props.environment !== 'production'),
);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Inicio',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Mi portal',
            href: indexPortal(),
            icon: UserRound,
        },
    ];

    if (
        tienePermiso('expedientes.ver_todos') ||
        tienePermiso('expedientes.ver_sucursal')
    ) {
        items.push({
            title: 'Expedientes',
            href: indexExpedientes(),
            icon: FolderKanban,
        });
    } else if (tienePermiso('expedientes.ver')) {
        items.push({
            title: 'Mi expediente',
            href: miExpediente(),
            icon: FolderKanban,
        });
    }

    items.push({
        title: 'Vacaciones',
        href: indexVacaciones(),
        icon: CalendarDays,
    });

    if (
        tienePermiso('vacaciones.aprobar') ||
        tienePermiso('vacaciones.rechazar')
    ) {
        items.push({
            title: 'Vacaciones (revisión)',
            href: indexRhVacaciones(),
            icon: CalendarDays,
        });
    }

    if (tienePermiso('solicitudes.crear') || tienePermiso('solicitudes.ver')) {
        items.push({
            title: 'Solicitudes',
            href: indexSolicitudes(),
            icon: ClipboardList,
        });
    }

    if (
        tienePermiso('solicitudes.revisar') ||
        tienePermiso('solicitudes.aprobar')
    ) {
        items.push({
            title: 'Solicitudes (revisión)',
            href: indexRhSolicitudes(),
            icon: ClipboardList,
        });
    }

    if (tienePermiso('vacantes.ver')) {
        items.push({
            title: 'Reclutamiento',
            href: indexReclutamiento(),
            icon: Users2,
        });
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

    if (tienePermiso('plantillas.ver')) {
        items.push({
            title: 'Plantillas',
            href: indexPlantillas(),
            icon: FileStack,
        });
    }

    if (tienePermiso('plantillas.generar')) {
        items.push({
            title: 'Formatos',
            href: indexFormatos(),
            icon: FileStack,
        });
    }

    if (tienePermiso('reportes_rh.ver')) {
        items.push({
            title: 'Reportes RH',
            href: indexReportesRh(),
            icon: BarChart3,
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
    } else if (capacitacionVisibleParaAdmin.value) {
        items.push({
            title: 'Capacitación',
            href: capacitacionProximamente(),
            icon: GraduationCap,
            badge: 'Fase futura',
        });
    }

    return items;
});

const adminNavItems = computed<NavItem[]>(() => {
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

    if (
        tienePermiso('departamentos.administrar') ||
        tienePermiso('puestos.administrar')
    ) {
        items.push({
            title: 'Departamentos',
            href: indexDepartamentos(),
            icon: Briefcase,
        });
        items.push({ title: 'Puestos', href: indexPuestos(), icon: Briefcase });
    }

    if (tienePermiso('puestos.administrar')) {
        items.push({
            title: 'Jerarquía de puestos',
            href: indexJerarquiaPuestos(),
            icon: GitBranch,
        });
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
