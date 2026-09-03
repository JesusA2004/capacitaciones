<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Bell,
    Briefcase,
    CalendarDays,
    ChevronRight,
    ClipboardList,
    UserRound,
} from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';
import { index as indexNotificaciones } from '@/routes/notificaciones';
import { perfil as rutaMiPerfil } from '@/routes/portal';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import { index as indexVacaciones } from '@/routes/vacaciones';
import type {
    PerfilColaborador,
    ResumenNotificaciones,
    SaldoVacaciones,
    SolicitudInternaItem,
} from '@/types';

defineProps<{
    perfil: PerfilColaborador;
    vacaciones: SaldoVacaciones;
    solicitudes_recientes: SolicitudInternaItem[];
    notificaciones: ResumenNotificaciones;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});

function iniciales(nombre: string, apellidos: string | null): string {
    return `${nombre.charAt(0)}${apellidos?.charAt(0) ?? ''}`.toUpperCase();
}
</script>

<template>
    <Head title="Mi portal" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 pb-10">
        <!-- Encabezado tipo app: avatar + saludo -->
        <div
            class="flex items-center gap-4 rounded-3xl bg-gradient-to-br from-[var(--brand-primary)] to-[var(--brand-secondary,var(--brand-primary))] p-5 text-white shadow-lg"
        >
            <Avatar class="size-14 border-2 border-white/40">
                <AvatarImage
                    v-if="perfil.foto_url"
                    :src="perfil.foto_url"
                    :alt="perfil.nombre_completo"
                />
                <AvatarFallback class="bg-white/20 text-white">
                    {{ iniciales(perfil.nombre, perfil.apellidos) }}
                </AvatarFallback>
            </Avatar>
            <div class="min-w-0 flex-1">
                <p class="truncate text-lg font-semibold">
                    {{ perfil.nombre_completo }}
                </p>
                <p class="truncate text-sm text-white/85">
                    {{ perfil.puesto ?? 'Sin puesto asignado' }} ·
                    {{ perfil.sucursal ?? 'Sin sucursal' }}
                </p>
            </div>
            <Link
                :href="indexNotificaciones()"
                class="relative flex size-10 shrink-0 items-center justify-center rounded-full bg-white/15 transition-colors hover:bg-white/25"
            >
                <Bell class="size-5" />
                <span
                    v-if="notificaciones.no_leidas > 0"
                    class="absolute -top-1 -right-1 flex size-5 items-center justify-center rounded-full bg-destructive text-[10px] font-bold text-white"
                >
                    {{ notificaciones.no_leidas }}
                </span>
            </Link>
        </div>

        <!-- Accesos rápidos: cards grandes -->
        <div class="grid grid-cols-2 gap-3">
            <Link
                :href="rutaMiPerfil()"
                class="group flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:border-[var(--brand-primary)]/40 hover:shadow-md"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]"
                >
                    <UserRound class="size-5" />
                </span>
                <span class="text-sm font-semibold">Mi perfil</span>
                <span class="text-xs text-muted-foreground"
                    >Datos básicos y antigüedad</span
                >
            </Link>

            <Link
                :href="indexVacaciones()"
                class="group flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:border-[var(--brand-primary)]/40 hover:shadow-md"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                >
                    <CalendarDays class="size-5" />
                </span>
                <span class="text-sm font-semibold">Mis vacaciones</span>
                <span class="text-xs text-muted-foreground"
                    >{{ vacaciones.dias_disponibles }} días disponibles</span
                >
            </Link>

            <Link
                :href="indexSolicitudes()"
                class="group flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:border-[var(--brand-primary)]/40 hover:shadow-md"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400"
                >
                    <ClipboardList class="size-5" />
                </span>
                <span class="text-sm font-semibold">Mis solicitudes</span>
                <span class="text-xs text-muted-foreground"
                    >{{ solicitudes_recientes.length }} recientes</span
                >
            </Link>

            <div
                class="flex flex-col gap-2 rounded-2xl border border-dashed border-border/60 bg-muted/30 p-4"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-muted text-muted-foreground"
                >
                    <Briefcase class="size-5" />
                </span>
                <span class="text-sm font-semibold text-muted-foreground"
                    >Capacitación</span
                >
                <Badge variant="secondary" class="w-fit">Próximamente</Badge>
            </div>
        </div>

        <!-- Resumen de vacaciones -->
        <div class="rounded-3xl border border-border/60 bg-card p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">Vacaciones</h2>
                <Link
                    :href="indexVacaciones()"
                    class="flex items-center text-xs font-medium text-[var(--brand-primary)] hover:underline"
                >
                    Ver detalle <ChevronRight class="size-3.5" />
                </Link>
            </div>
            <div class="grid grid-cols-3 gap-3 text-center">
                <div>
                    <p class="text-2xl font-bold">
                        {{ vacaciones.dias_generados }}
                    </p>
                    <p class="text-xs text-muted-foreground">Generados</p>
                </div>
                <div>
                    <p class="text-2xl font-bold">
                        {{ vacaciones.dias_usados }}
                    </p>
                    <p class="text-xs text-muted-foreground">Usados</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-[var(--brand-primary)]">
                        {{ vacaciones.dias_disponibles }}
                    </p>
                    <p class="text-xs text-muted-foreground">Disponibles</p>
                </div>
            </div>
        </div>

        <!-- Solicitudes recientes -->
        <div class="rounded-3xl border border-border/60 bg-card p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">Solicitudes recientes</h2>
                <Link
                    :href="indexSolicitudes()"
                    class="flex items-center text-xs font-medium text-[var(--brand-primary)] hover:underline"
                >
                    Ver todas <ChevronRight class="size-3.5" />
                </Link>
            </div>

            <div
                v-if="!solicitudes_recientes.length"
                class="rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
            >
                Todavía no tienes solicitudes.
            </div>

            <div v-else class="flex flex-col gap-2">
                <div
                    v-for="solicitud in solicitudes_recientes.slice(0, 4)"
                    :key="solicitud.id"
                    class="flex items-center justify-between gap-2 rounded-xl border border-border/50 p-3 text-sm"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ solicitud.folio }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ solicitud.motivo }}
                        </p>
                    </div>
                    <EstadoBadge :estado="solicitud.estado" />
                </div>
            </div>
        </div>

        <!-- Notificaciones recientes -->
        <div class="rounded-3xl border border-border/60 bg-card p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">Notificaciones</h2>
                <Link
                    :href="indexNotificaciones()"
                    class="flex items-center text-xs font-medium text-[var(--brand-primary)] hover:underline"
                >
                    Ver todas <ChevronRight class="size-3.5" />
                </Link>
            </div>

            <div
                v-if="!notificaciones.recientes.length"
                class="rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
            >
                Sin notificaciones por ahora.
            </div>

            <div v-else class="flex flex-col gap-2">
                <div
                    v-for="notificacion in notificaciones.recientes.slice(0, 4)"
                    :key="notificacion.id"
                    class="flex items-start gap-2 rounded-xl p-2 text-sm"
                    :class="!notificacion.leida ? 'bg-muted/40' : ''"
                >
                    <span
                        class="mt-1.5 size-1.5 shrink-0 rounded-full"
                        :class="
                            !notificacion.leida
                                ? 'bg-[var(--brand-primary)]'
                                : 'bg-transparent'
                        "
                    />
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ notificacion.titulo }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ notificacion.mensaje }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
