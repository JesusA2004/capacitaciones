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
import { Progress } from '@/components/ui/progress';
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

const ACCESOS = [
    {
        href: rutaMiPerfil,
        icono: UserRound,
        color: 'bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]',
        titulo: 'Mi perfil',
        descripcion: 'Datos básicos y antigüedad',
    },
    {
        href: indexVacaciones,
        icono: CalendarDays,
        color: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        titulo: 'Mis vacaciones',
        descripcion: null,
    },
    {
        href: indexSolicitudes,
        icono: ClipboardList,
        color: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        titulo: 'Mis solicitudes',
        descripcion: null,
    },
] as const;
</script>

<template>
    <Head title="Mi portal" />

    <div class="flex flex-col gap-6 p-4 lg:p-6">
        <!-- Encabezado: avatar (precargado desde el expediente) + saludo -->
        <div
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[var(--brand-primary)] to-[var(--brand-secondary,var(--brand-primary))] p-6 text-white shadow-lg sm:p-8"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -top-16 -right-16 size-64 rounded-full bg-white/10"
            />
            <div
                class="relative flex flex-col items-center gap-5 text-center sm:flex-row sm:items-center sm:text-left"
            >
                <Avatar
                    class="size-20 shrink-0 border-4 border-white/30 shadow-md sm:size-24"
                >
                    <AvatarImage
                        v-if="perfil.foto_url"
                        :src="perfil.foto_url"
                        :alt="perfil.nombre_completo"
                        class="object-cover"
                    />
                    <AvatarFallback class="bg-white/20 text-2xl text-white">
                        {{ iniciales(perfil.nombre, perfil.apellidos) }}
                    </AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1">
                    <p class="text-xl font-semibold sm:text-2xl">
                        {{ perfil.nombre_completo }}
                    </p>
                    <p class="mt-1 text-sm text-white/85 sm:text-base">
                        {{ perfil.puesto ?? 'Sin puesto asignado' }} ·
                        {{ perfil.sucursal ?? 'Sin sucursal' }}
                    </p>
                    <div
                        class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-medium"
                    >
                        <UserRound class="size-3.5" />
                        {{ perfil.numero_empleado ?? 'Sin número de empleado' }}
                    </div>
                </div>
                <Link
                    :href="indexNotificaciones()"
                    class="relative flex size-11 shrink-0 items-center justify-center rounded-full bg-white/15 transition-all duration-200 hover:-translate-y-0.5 hover:bg-white/25"
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
        </div>

        <!-- Accesos rápidos -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Link
                v-for="acceso in ACCESOS"
                :key="acceso.titulo"
                :href="acceso.href()"
                class="group flex flex-col gap-2 rounded-2xl border border-border/60 bg-gradient-to-br from-card to-muted/30 p-4 shadow-sm transition-all duration-200 ease-out hover:-translate-y-1 hover:border-[var(--brand-primary)]/40 hover:shadow-lg"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl transition-transform duration-200 group-hover:scale-110"
                    :class="acceso.color"
                >
                    <component :is="acceso.icono" class="size-5" />
                </span>
                <span class="text-sm font-semibold">{{ acceso.titulo }}</span>
                <span class="text-xs text-muted-foreground">
                    {{
                        acceso.descripcion ??
                        (acceso.titulo === 'Mis vacaciones'
                            ? `${vacaciones.dias_disponibles} días disponibles`
                            : `${solicitudes_recientes.length} recientes`)
                    }}
                </span>
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

        <!-- Resumen: vacaciones, solicitudes y notificaciones lado a lado en desktop -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div
                class="flex flex-col gap-4 rounded-3xl border border-border/60 bg-card p-5 shadow-sm transition-shadow duration-200 hover:shadow-md"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Vacaciones</h2>
                    <Link
                        :href="indexVacaciones()"
                        class="flex items-center text-xs font-medium text-[var(--brand-primary)] transition-transform duration-200 hover:translate-x-0.5"
                    >
                        Ver detalle <ChevronRight class="size-3.5" />
                    </Link>
                </div>

                <Progress
                    :model-value="
                        vacaciones.dias_generados > 0
                            ? (vacaciones.dias_usados /
                                  vacaciones.dias_generados) *
                              100
                            : 0
                    "
                    class="h-2"
                />

                <div class="grid grid-cols-3 gap-3 text-center">
                    <div>
                        <p class="text-2xl font-bold tabular-nums">
                            {{ vacaciones.dias_generados }}
                        </p>
                        <p class="text-xs text-muted-foreground">Generados</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold tabular-nums">
                            {{ vacaciones.dias_usados }}
                        </p>
                        <p class="text-xs text-muted-foreground">Usados</p>
                    </div>
                    <div>
                        <p
                            class="text-2xl font-bold text-[var(--brand-primary)] tabular-nums"
                        >
                            {{ vacaciones.dias_disponibles }}
                        </p>
                        <p class="text-xs text-muted-foreground">Disponibles</p>
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-5 shadow-sm transition-shadow duration-200 hover:shadow-md"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Solicitudes recientes</h2>
                    <Link
                        :href="indexSolicitudes()"
                        class="flex items-center text-xs font-medium text-[var(--brand-primary)] transition-transform duration-200 hover:translate-x-0.5"
                    >
                        Ver todas <ChevronRight class="size-3.5" />
                    </Link>
                </div>

                <div
                    v-if="!solicitudes_recientes.length"
                    class="flex flex-1 items-center justify-center rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
                >
                    Todavía no tienes solicitudes.
                </div>

                <div v-else class="flex flex-col gap-2">
                    <Link
                        v-for="solicitud in solicitudes_recientes.slice(0, 4)"
                        :key="solicitud.id"
                        :href="indexSolicitudes()"
                        class="flex items-center justify-between gap-2 rounded-xl border border-transparent p-3 text-sm transition-colors duration-150 hover:border-border/60 hover:bg-muted/40"
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
                    </Link>
                </div>
            </div>

            <div
                class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-5 shadow-sm transition-shadow duration-200 hover:shadow-md"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Notificaciones</h2>
                    <Link
                        :href="indexNotificaciones()"
                        class="flex items-center text-xs font-medium text-[var(--brand-primary)] transition-transform duration-200 hover:translate-x-0.5"
                    >
                        Ver todas <ChevronRight class="size-3.5" />
                    </Link>
                </div>

                <div
                    v-if="!notificaciones.recientes.length"
                    class="flex flex-1 items-center justify-center rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
                >
                    Sin notificaciones por ahora.
                </div>

                <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="notificacion in notificaciones.recientes.slice(
                            0,
                            4,
                        )"
                        :key="notificacion.id"
                        class="flex items-start gap-2 rounded-xl p-2 text-sm transition-colors duration-150"
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
    </div>
</template>
