<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        titulo?: string;
    }>(),
    {
        titulo: 'Navegación',
    },
);

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-1">
        <SidebarGroupLabel
            class="text-[11px] font-semibold tracking-[0.12em] text-bronce uppercase"
            >{{ titulo }}</SidebarGroupLabel
        >
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                    class="relative h-10 rounded-xl text-sidebar-foreground transition-colors before:absolute before:inset-y-2 before:left-0 before:w-[3px] before:rounded-full before:bg-transparent hover:bg-salvia/70 hover:text-primary data-[active=true]:bg-verde-suave/80 data-[active=true]:font-semibold data-[active=true]:text-primary data-[active=true]:before:bg-oro"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <Badge
                            v-if="item.badge"
                            variant="secondary"
                            class="ml-auto text-[10px]"
                        >
                            {{ item.badge }}
                        </Badge>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
