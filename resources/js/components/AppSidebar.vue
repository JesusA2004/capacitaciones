<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Briefcase,
    BookOpen,
    Building2,
    CalendarDays,
    CheckSquare,
    ClipboardList,
    FolderOpen,
    GraduationCap,
    HelpCircle,
    LayoutGrid,
    LineChart,
    ShieldCheck,
    Users,
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
import { calendario, dashboard } from '@/routes';
import { index as indexDepartamentos } from '@/routes/administracion/departamentos';
import { index as indexPuestos } from '@/routes/administracion/puestos';
import { index as indexRoles } from '@/routes/administracion/roles';
import { index as indexSucursales } from '@/routes/administracion/sucursales';
import { index as indexUsuarios } from '@/routes/administracion/usuarios';
import { index as indexAsignaciones } from '@/routes/asignaciones';
import { index as indexBancosPreguntas } from '@/routes/bancos-preguntas';
import { index as indexCalificacionesActividades } from '@/routes/calificaciones/actividades';
import { index as indexCalificacionesCuestionarios } from '@/routes/calificaciones/cuestionarios';
import { index as indexCursos } from '@/routes/cursos';
import { index as indexMiCapacitacion } from '@/routes/mi-capacitacion';
import { index as indexMultimedia } from '@/routes/multimedia';
import { index as indexReporteCumplimiento } from '@/routes/reportes/cumplimiento';
import type { NavItem } from '@/types';

const { tienePermiso } = usePermisos();

const mainNavItems: NavItem[] = [
    {
        title: 'Inicio',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Mi capacitación',
        href: indexMiCapacitacion(),
        icon: GraduationCap,
    },
    {
        title: 'Calendario',
        href: calendario(),
        icon: CalendarDays,
    },
];

const capacitacionNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];

    if (tienePermiso('cursos.ver')) {
        items.push({ title: 'Cursos', href: indexCursos(), icon: BookOpen });
    }

    if (tienePermiso('asignaciones.ver')) {
        items.push({
            title: 'Asignaciones',
            href: indexAsignaciones(),
            icon: ClipboardList,
        });
    }

    if (tienePermiso('multimedia.administrar')) {
        items.push({
            title: 'Biblioteca multimedia',
            href: indexMultimedia(),
            icon: FolderOpen,
        });
    }

    if (tienePermiso('cuestionarios.administrar')) {
        items.push({
            title: 'Banco de preguntas',
            href: indexBancosPreguntas(),
            icon: HelpCircle,
        });
    }

    if (tienePermiso('respuestas.calificar')) {
        items.push({
            title: 'Calificar cuestionarios',
            href: indexCalificacionesCuestionarios(),
            icon: CheckSquare,
        });
        items.push({
            title: 'Calificar actividades',
            href: indexCalificacionesActividades(),
            icon: CheckSquare,
        });
    }

    if (
        tienePermiso('reportes.sucursal') ||
        tienePermiso('reportes.globales')
    ) {
        items.push({
            title: 'Reporte de cumplimiento',
            href: indexReporteCumplimiento(),
            icon: LineChart,
        });
    }

    return items;
});

const adminNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];

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

    if (tienePermiso('roles.administrar')) {
        items.push({
            title: 'Roles y permisos',
            href: indexRoles(),
            icon: ShieldCheck,
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
                v-if="capacitacionNavItems.length"
                :items="capacitacionNavItems"
                titulo="Capacitación"
            />
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
