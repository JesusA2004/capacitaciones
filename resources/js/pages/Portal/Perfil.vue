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
        etiqueta: 'Departamento',
        valor: (p: PerfilColaborador) => p.departamento ?? 'Sin asignar',
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
] as const;
</script>

<template>
    <Head title="Mi perfil" />

    <div class="grid grid-cols-1 gap-6 p-4 lg:grid-cols-3 lg:p-6">
        <!-- Tarjeta de foto: precargada desde el expediente del colaborador. -->
        <div
            class="flex flex-col items-center gap-4 self-start rounded-3xl border border-border/60 bg-gradient-to-br from-card to-muted/30 p-8 text-center shadow-sm transition-shadow duration-200 hover:shadow-md lg:col-span-1"
        >
            <Avatar
                class="size-28 border-4 border-[var(--brand-primary)]/20 shadow-md sm:size-32"
            >
                <AvatarImage
                    v-if="perfil.foto_url"
                    :src="perfil.foto_url"
                    :alt="perfil.nombre_completo"
                    class="object-cover"
                />
                <AvatarFallback
                    class="bg-[var(--brand-primary)]/10 text-3xl text-[var(--brand-primary)]"
                >
                    {{ iniciales(perfil.nombre, perfil.apellidos) }}
                </AvatarFallback>
            </Avatar>
            <div>
                <h1 class="text-xl font-semibold">
                    {{ perfil.nombre_completo }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
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

        <!-- Datos: grid de dos columnas en pantallas grandes, aprovechando el ancho. -->
        <div class="flex flex-col gap-4 lg:col-span-2">
            <div
                class="grid grid-cols-1 gap-3 rounded-3xl border border-border/60 bg-card p-5 shadow-sm transition-shadow duration-200 hover:shadow-md sm:grid-cols-2"
            >
                <div
                    v-for="dato in DATOS"
                    :key="dato.etiqueta"
                    class="flex items-center gap-3 rounded-2xl p-3 transition-colors duration-150 hover:bg-muted/40"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground"
                    >
                        <component :is="dato.icono" class="size-4" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs text-muted-foreground">
                            {{ dato.etiqueta }}
                        </p>
                        <p class="truncate text-sm font-medium">
                            {{ dato.valor(props.perfil) }}
                        </p>
                    </div>
                </div>
            </div>

            <p
                class="rounded-2xl border border-dashed border-border/60 p-4 text-center text-xs text-muted-foreground"
            >
                ¿Algún dato incorrecto? Solicita su actualización desde
                <span class="font-medium text-foreground">Mis solicitudes</span
                >.
            </p>
        </div>
    </div>
</template>
