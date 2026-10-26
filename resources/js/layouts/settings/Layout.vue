<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermisos } from '@/composables/usePermisos';
import { toUrl } from '@/lib/utils';
import { edit as editAvisosSucursal } from '@/routes/avisos-sucursal';
import { edit as editPersonalizacion } from '@/routes/personalizacion';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const { tienePermiso } = usePermisos();

const baseNavItems: NavItem[] = [
    {
        title: 'Perfil',
        href: editProfile(),
    },
    {
        title: 'Seguridad',
        href: editSecurity(),
    },
    {
        title: 'Personalización',
        href: editPersonalizacion(),
    },
];

// «Avisos por sucursal» solo para quien su rol se lo permite.
const sidebarNavItems = computed<NavItem[]>(() =>
    tienePermiso('notificaciones.silenciar_sucursales')
        ? [
              ...baseNavItems,
              { title: 'Avisos por sucursal', href: editAvisosSucursal() },
          ]
        : baseNavItems,
);

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="pagina-media">
        <Heading
            title="Configuración"
            description="Administra tu perfil y las preferencias de tu cuenta"
        />

        <div class="flex flex-col gap-6 lg:flex-row lg:gap-12">
            <!-- Móvil/tablet: pestañas horizontales con scroll; escritorio:
                 menú vertical a la izquierda. -->
            <aside
                class="-mx-4 min-w-0 px-4 sm:mx-0 sm:px-0 lg:w-52 lg:shrink-0"
            >
                <nav
                    class="flex [scrollbar-width:none] gap-1 overflow-x-auto border-b border-border/60 pb-2 lg:flex-col lg:border-b-0 lg:pb-0"
                    aria-label="Configuración"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'min-h-10 shrink-0 justify-start lg:w-full',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <div class="min-w-0 flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
