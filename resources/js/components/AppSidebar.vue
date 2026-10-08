<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
import { useMainNavItems } from '@/composables/useMainNavItems';
import { dashboard } from '@/routes';

// El Portal RH es la experiencia principal (ver docs/PORTAL_RH.md).
// Capacitación se conserva por completo detrás del feature flag
// `capacitacion` (config/features.php, docs/CAPACITACION_PROXIMAMENTE.md):
// con la bandera apagada no se muestra ningún acceso a NADIE, ni siquiera
// super_admin — nada de "botones falsos" ni badges "Fase futura" en el
// menú mientras el módulo no esté terminado.
//
// La lista de accesos (modo colaborador/operativo + Administración) vive en
// useMainNavItems() — compartida con MobileBottomNav.vue, ver
// docs/ROLES_Y_NAVEGACION.md. Nunca dupliques esa lógica aquí.
const {
    navGroups,
    tieneAmbosModos,
    esColaborador,
    cambiarModo,
} = useMainNavItems();
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
                data-tour="selector-modo"
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
            <!-- Un grupo por proceso: Reclutamiento → Desarrollo → Personal →
                 Organización → Administración (useMainNavItems). -->
            <NavMain
                v-for="grupo in navGroups"
                :key="grupo.titulo"
                :items="grupo.items"
                :titulo="grupo.titulo"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
