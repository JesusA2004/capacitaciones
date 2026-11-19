<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    MinusCircle,
    PlusCircle,
    Search,
    ShieldCheck,
    Sparkles,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { coincide, normalizar } from '@/lib/rolesPermisos';
import { dashboard } from '@/routes';
import { index as indexRoles } from '@/routes/administracion/roles';
import { index as indexUsuarios } from '@/routes/administracion/usuarios';
import { show } from '@/routes/administracion/usuarios/permisos';
import type { PersonaConPermisosEspeciales } from '@/types';

/**
 * Quién tiene hoy permisos especiales (concedidos o quitados sin tocar su
 * rol). Para dar uno nuevo: Usuarios → la persona → «Permisos especiales».
 */
const props = defineProps<{
    personas: PersonaConPermisosEspeciales[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Roles y permisos', href: '/administracion/roles' },
            {
                title: 'Permisos especiales',
                href: '/administracion/permisos-especiales',
            },
        ],
    },
});

const busqueda = ref('');
const filtradas = computed(() => {
    const filtro = normalizar(busqueda.value);

    return props.personas.filter((p) =>
        coincide(filtro, p.nombre, p.username, ...p.roles),
    );
});

function fecha(valor: string): string {
    return new Date(valor).toLocaleString('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <Head title="Permisos especiales" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Permisos especiales"
            descripcion="Personas con permisos concedidos (+) o quitados (−) por encima de su rol. Su rol no cambia."
            :icono="Sparkles"
        >
            <Button variant="outline" as-child>
                <Link :href="indexRoles.url()">
                    <ShieldCheck class="size-4" />
                    Roles y permisos
                </Link>
            </Button>
            <Button as-child>
                <Link :href="indexUsuarios.url()">
                    <Users class="size-4" />
                    Dar a una persona
                </Link>
            </Button>
        </CrudPageHeader>

        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="busqueda"
                class="pl-9"
                placeholder="Buscar persona, usuario o rol…"
                aria-label="Buscar persona"
            />
        </div>

        <EmptyState
            v-if="filtradas.length === 0"
            :icono="Sparkles"
            titulo="Nadie tiene permisos especiales"
            descripcion="Todos tienen exactamente lo de su rol. Para dar uno: Usuarios → menú de la persona → «Permisos especiales»."
        />

        <ul
            v-else
            class="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3"
        >
            <li v-for="p in filtradas" :key="p.id">
                <Link
                    :href="show.url(p.id)"
                    class="flex h-full tarjeta-interactiva flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <span class="font-semibold">{{ p.nombre }}</span>
                    <span class="flex flex-wrap gap-1">
                        <Badge
                            v-for="rol in p.roles"
                            :key="rol"
                            variant="outline"
                            class="capitalize"
                            >{{ rol.replace(/_/g, ' ') }}</Badge
                        >
                    </span>
                    <span class="flex flex-wrap gap-2 text-sm">
                        <span
                            v-if="p.concedidos > 0"
                            class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-success"
                            ><PlusCircle class="size-3.5" />
                            {{ p.concedidos }} concedido(s)</span
                        >
                        <span
                            v-if="p.quitados > 0"
                            class="inline-flex items-center gap-1 rounded-full bg-danger-soft px-2 py-0.5 text-destructive"
                            ><MinusCircle class="size-3.5" />
                            {{ p.quitados }} quitado(s)</span
                        >
                    </span>
                    <span
                        v-if="p.vence"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                        ><CalendarClock class="size-3.5" /> Vence
                        {{ fecha(p.vence) }}</span
                    >
                </Link>
            </li>
        </ul>
    </div>
</template>
