<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Briefcase,
    Building2,
    Cake,
    ClipboardList,
    Compass,
    FileStack,
    FolderKanban,
    GitBranch,
    GraduationCap,
    Landmark,
    LayoutGrid,
    Megaphone,
    QrCode,
    ShieldCheck,
    Smartphone,
    UserRound,
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
import { dashboard } from '@/routes';
import { index as indexAppReleases } from '@/routes/administracion/app-releases';
import { index as indexDepartamentos } from '@/routes/administracion/departamentos';
import { index as indexEmpresas } from '@/routes/administracion/empresas';
import { index as indexJerarquiaPuestos } from '@/routes/administracion/jerarquia-puestos';
import { index as indexPuestos } from '@/routes/administracion/puestos';
import { index as indexRoles } from '@/routes/administracion/roles';
import { index as indexSucursales } from '@/routes/administracion/sucursales';
import { proximamente as capacitacionProximamente } from '@/routes/capacitacion';
import { index as indexPortal } from '@/routes/portal';
import { index as indexReportes } from '@/routes/reportes';
import { index as indexCampanas } from '@/routes/rh/campanas';
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

const { tienePermiso, tieneRol } = usePermisos();
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

    // Nada de "Mi expediente" para el colaborador: el expediente es un
    // módulo operativo de RH (documentos, revisiones, historial laboral) —
    // el colaborador ve lo que le toca de su perfil dentro de "Mi portal".
    // "Mi perfil" tampoco es un módulo aparte: Mi portal ya trae el resumen
    // del perfil y enlaza al detalle completo desde ahí, un solo destino en
    // el menú. "Mis notificaciones" tampoco: la campana del encabezado ya
    // cubre eso (no hay una página Inertia dedicada, solo un endpoint JSON
    // para la campana — no se debe navegar ahí con un <Link>).

    if (capacitacionActiva.value) {
        items.push({
            title: 'Capacitación',
            href: capacitacionProximamente(),
            icon: GraduationCap,
        });
    }

    items.push({ title: 'Ayuda', href: '/ayuda', icon: Compass });

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

    // Gasto de campañas de reclutamiento: autorización simple por rol (sin
    // permiso granular propio, ver Rh\CampanaReclutamientoController).
    if (tieneRol('rh_admin') || tieneRol('super_admin')) {
        items.push({
            title: 'Campañas',
            href: indexCampanas(),
            icon: Megaphone,
        });
    }

    // Altas digitales NO es un módulo suelto del menú: es un paso del flujo
    // Candidato -> Alta digital (ver botones dentro de Rh/Candidatos/Show.vue).
    // La ruta/controller sigue existiendo para uso interno/depuración, solo
    // se quitó la entrada de navegación.
    //
    // Invitaciones QR SÍ se queda: es como un colaborador genera su alta e
    // incorpora su expediente desde la app móvil (QR de incorporación) — no
    // es un paso interno de RH, es la puerta de entrada real de ese flujo.
    if (tienePermiso('rh.incorporacion.invitaciones.ver') && tieneRol('super_admin')) {
        items.push({
            title: 'Invitaciones QR',
            href: indexIncorporacionInvitaciones(),
            icon: QrCode,
        });
    }

    // "Formatos" es un único menú con tabs (Oficiales PDF, Plantillas
    // avanzadas DOCX, Generados, Configuración de campos) — ver
    // docs/PLANTILLAS_FORMATOS.md. No se muestran como módulos sueltos.
    if (tienePermiso('formatos_oficiales.ver') || tienePermiso('plantillas.ver')) {
        items.push({
            title: 'Formatos',
            href: tienePermiso('formatos_oficiales.ver') ? indexFormatos() : indexPlantillas(),
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

    items.push({ title: 'Ayuda', href: '/ayuda', icon: Compass });

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

    // "Usuarios" como listado aparte se retiró: cuenta de acceso, roles y
    // contraseña ahora se gestionan desde la pestaña «Cuenta» de cada
    // expediente (rh.expedientes) — ver docs/ROLES_Y_NAVEGACION.md.
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
