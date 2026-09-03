<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    CalendarClock,
    Mail,
    UserRound,
    Users2,
} from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { dashboard } from '@/routes';
import { index as indexPortal } from '@/routes/portal';
import type { PerfilColaborador } from '@/types';

const props = defineProps<{
    perfil: PerfilColaborador;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi portal', href: indexPortal() },
            { title: 'Mi perfil', href: '' },
        ],
    },
});

function iniciales(nombre: string, apellidos: string | null): string {
    return `${nombre.charAt(0)}${apellidos?.charAt(0) ?? ''}`.toUpperCase();
}

const DATOS = [
    {
        icono: Briefcase,
        etiqueta: 'Puesto',
        valor: (p: PerfilColaborador) => p.puesto ?? 'Sin asignar',
    },
    {
        icono: Building2,
        etiqueta: 'Sucursal',
        valor: (p: PerfilColaborador) => p.sucursal ?? 'Sin asignar',
    },
    {
        icono: Building2,
        etiqueta: 'Empresa',
        valor: (p: PerfilColaborador) => p.empresa ?? 'Sin asignar',
    },
    {
        icono: Users2,
        etiqueta: 'Jefe directo',
        valor: (p: PerfilColaborador) => p.jefe_directo ?? 'Sin asignar',
    },
    {
        icono: Mail,
        etiqueta: 'Correo',
        valor: (p: PerfilColaborador) => p.correo,
    },
    {
        icono: CalendarClock,
        etiqueta: 'Fecha de ingreso',
        valor: (p: PerfilColaborador) => p.fecha_ingreso ?? '—',
    },
];
</script>

<template>
    <Head title="Mi perfil" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 pb-10">
        <div
            class="flex flex-col items-center gap-3 rounded-3xl border border-border/60 bg-card p-6 text-center shadow-sm"
        >
            <Avatar class="size-20 border-4 border-[var(--brand-primary)]/15">
                <AvatarImage
                    v-if="perfil.foto_url"
                    :src="perfil.foto_url"
                    :alt="perfil.nombre_completo"
                />
                <AvatarFallback
                    class="bg-[var(--brand-primary)]/10 text-lg text-[var(--brand-primary)]"
                >
                    {{ iniciales(perfil.nombre, perfil.apellidos) }}
                </AvatarFallback>
            </Avatar>
            <div>
                <h1 class="text-lg font-semibold">
                    {{ perfil.nombre_completo }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ perfil.numero_empleado ?? 'Sin número de empleado' }}
                </p>
            </div>
            <div
                class="flex items-center gap-2 rounded-full bg-[var(--brand-primary)]/10 px-4 py-1.5 text-sm font-medium text-[var(--brand-primary)]"
            >
                <UserRound class="size-4" />
                {{ perfil.antiguedad_anios }}
                {{ perfil.antiguedad_anios === 1 ? 'año' : 'años' }} de
                antigüedad
            </div>
        </div>

        <div class="rounded-3xl border border-border/60 bg-card p-2">
            <div
                v-for="dato in DATOS"
                :key="dato.etiqueta"
                class="flex items-center gap-3 border-b border-border/40 p-3 text-sm last:border-0"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground"
                >
                    <component :is="dato.icono" class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">
                        {{ dato.etiqueta }}
                    </p>
                    <p class="truncate font-medium">
                        {{ dato.valor(props.perfil) }}
                    </p>
                </div>
            </div>
        </div>

        <p class="text-center text-xs text-muted-foreground">
            ¿Algún dato incorrecto? Solicita su actualización desde
            <span class="font-medium">Mis solicitudes</span>.
        </p>
    </div>
</template>
