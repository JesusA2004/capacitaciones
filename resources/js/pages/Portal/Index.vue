<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bell,
    CalendarDays,
    ChevronRight,
    ClipboardList,
    Clock,
    FolderOpen,
    ListChecks,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import CelebracionesHoyCard from '@/components/Celebraciones/CelebracionesHoyCard.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Progress } from '@/components/ui/progress';
import { useNotificaciones } from '@/composables/useNotificaciones';
import { dashboard, miExpediente } from '@/routes';
import {
    miProceso,
    notificaciones as indexNotificaciones,
    perfil as rutaMiPerfil,
} from '@/routes/portal';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import type {
    MisPendientes,
    NotificacionPortalItem,
    PerfilColaborador,
    ResumenNotificaciones,
    SaldoVacaciones,
    SolicitudInternaItem,
} from '@/types';

const props = defineProps<{
    perfil: PerfilColaborador;
    vacaciones: SaldoVacaciones;
    solicitudes_recientes: SolicitudInternaItem[];
    notificaciones: ResumenNotificaciones;
    pendientes: MisPendientes | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});

const { abrirNotificacion } = useNotificaciones();

// Lleva al recurso que avisa y la marca como leída (ver useNotificaciones).
function abrir(notificacion: NotificacionPortalItem): void {
    notificacion.leida = true;
    void abrirNotificacion(notificacion.id);
}

function iniciales(nombre: string, apellidos: string | null): string {
    return `${nombre.charAt(0)}${apellidos?.charAt(0) ?? ''}`.toUpperCase();
}

const porHacer = computed(
    () => props.pendientes?.pendientes.filter((p) => p.tipo === 'accion') ?? [],
);
const enEspera = computed(
    () => props.pendientes?.pendientes.filter((p) => p.tipo === 'espera') ?? [],
);

const accesos = computed(() => [
    {
        href: rutaMiPerfil().url,
        icono: UserRound,
        color: 'bg-[var(--mrl-primary)]/10 text-[var(--mrl-primary)] group-hover:bg-[var(--mrl-primary)] group-hover:text-white',
        titulo: 'Mi perfil',
        descripcion: 'Datos básicos y antigüedad',
    },
    {
        href: miExpediente().url,
        icono: FolderOpen,
        color: 'bg-[var(--mrl-navy)]/10 text-[var(--mrl-navy)] group-hover:bg-[var(--mrl-navy)] group-hover:text-white',
        titulo: 'Mi expediente',
        descripcion: 'Tus documentos y datos',
    },
    {
        href: indexSolicitudes().url,
        icono: CalendarDays,
        color: 'bg-[var(--mrl-success)]/10 text-[var(--mrl-success)] group-hover:bg-[var(--mrl-success)] group-hover:text-white',
        titulo: 'Mis vacaciones',
        descripcion: `${props.vacaciones.dias_disponibles} días disponibles`,
    },
    {
        href: indexSolicitudes().url,
        icono: ClipboardList,
        color: 'bg-[var(--mrl-gold)]/15 text-[var(--mrl-gold-dark)] group-hover:bg-[var(--mrl-gold)] group-hover:text-white',
        titulo: 'Mis solicitudes',
        descripcion: `${props.solicitudes_recientes.length} recientes`,
    },
]);

const tarjeta =
    'rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5 shadow-sm transition-all duration-200 hover:shadow-md';
const enlace =
    'flex items-center rounded-md text-xs font-medium text-[var(--mrl-primary)] transition-all duration-200 hover:translate-x-0.5 hover:underline focus-visible:ring-2 focus-visible:ring-[var(--mrl-primary)] focus-visible:outline-none';
</script>

<template>
    <Head title="Mi portal" />

    <div class="pagina-ancha flex flex-col gap-6">
        <!-- Encabezado: foto (precargada del expediente) + saludo -->
        <div
            data-tour="portal-encabezado"
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[var(--mrl-primary)] to-[var(--mrl-deep-green,var(--mrl-verde-profundo))] p-6 text-white shadow-lg sm:p-8"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -top-20 -right-16 size-72 rounded-full bg-white/10"
            />
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -bottom-24 left-1/3 size-56 rounded-full bg-white/5"
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
                    <p class="text-sm text-white/80">¡Hola!</p>
                    <p class="text-2xl font-semibold sm:text-3xl">
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
                    class="relative flex size-12 shrink-0 items-center justify-center rounded-full bg-white/15 transition-all duration-200 hover:scale-105 hover:bg-white/25 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    :aria-label="`Notificaciones (${notificaciones.no_leidas} sin leer)`"
                >
                    <Bell class="size-5" />
                    <span
                        v-if="notificaciones.no_leidas > 0"
                        class="absolute -top-1 -right-1 flex size-5 items-center justify-center rounded-full bg-[var(--mrl-danger)] text-[10px] font-bold text-white"
                    >
                        {{ notificaciones.no_leidas }}
                    </span>
                </Link>
            </div>
        </div>

        <!-- Lo que necesitas hacer (sin nombres de etapas internas) -->
        <section
            v-if="porHacer.length || enEspera.length"
            data-tour="portal-pendientes"
            class="flex flex-col gap-4 rounded-3xl border-2 border-[var(--mrl-primary)]/25 bg-[var(--mrl-primary)]/5 p-5"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2
                    class="flex items-center gap-2 text-base font-semibold text-[var(--mrl-texto)]"
                >
                    <ListChecks class="size-5 text-[var(--mrl-primary)]" /> Lo
                    que necesitas hacer
                </h2>
                <Link :href="miProceso()" :class="enlace"
                    >Ver mis pendientes <ChevronRight class="size-3.5"
                /></Link>
            </div>
            <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                <Link
                    v-for="p in porHacer"
                    :key="p.clave"
                    :href="miProceso()"
                    class="group flex items-center gap-4 rounded-2xl border border-[var(--mrl-primary)]/20 bg-[var(--mrl-surface)] p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-[var(--mrl-primary)] hover:shadow-lg focus-visible:ring-2 focus-visible:ring-[var(--mrl-primary)] focus-visible:outline-none"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-[var(--mrl-texto)]">
                            {{ p.titulo }}
                        </p>
                        <p
                            class="line-clamp-2 text-sm text-[var(--mrl-texto-suave)]"
                        >
                            {{ p.descripcion }}
                        </p>
                    </div>
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[var(--mrl-primary)] text-white transition-transform group-hover:translate-x-1"
                    >
                        <ArrowRight class="size-4" />
                    </span>
                </Link>
                <div
                    v-for="p in enEspera"
                    :key="p.clave"
                    class="flex items-center gap-4 rounded-2xl border border-dashed border-[var(--mrl-gold)]/50 bg-[var(--mrl-surface)] p-4"
                >
                    <Clock class="size-5 shrink-0 text-[var(--mrl-gold)]" />
                    <div class="min-w-0">
                        <p class="font-medium text-[var(--mrl-texto)]">
                            {{ p.titulo }}
                        </p>
                        <p
                            class="line-clamp-2 text-sm text-[var(--mrl-texto-suave)]"
                        >
                            {{ p.descripcion }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <CelebracionesHoyCard />

        <!-- Accesos rápidos -->
        <div
            data-tour="portal-accesos"
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <Link
                v-for="acceso in accesos"
                :key="acceso.titulo"
                :href="acceso.href"
                class="group flex items-center gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-4 shadow-sm transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-[var(--mrl-primary)]/40 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-[var(--mrl-primary)] focus-visible:outline-none"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl transition-colors duration-200"
                    :class="acceso.color"
                >
                    <component :is="acceso.icono" class="size-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span
                        class="block text-sm font-semibold text-[var(--mrl-texto)]"
                        >{{ acceso.titulo }}</span
                    >
                    <span
                        class="block truncate text-xs text-[var(--mrl-texto-suave)]"
                        >{{ acceso.descripcion }}</span
                    >
                </span>
                <ChevronRight
                    class="size-4 shrink-0 text-[var(--mrl-texto-suave)] opacity-0 transition-all group-hover:translate-x-0.5 group-hover:opacity-100"
                />
            </Link>
        </div>

        <!-- Resumen: vacaciones, solicitudes y notificaciones -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-3">
            <div
                data-tour="portal-vacaciones"
                class="flex flex-col gap-4"
                :class="tarjeta"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Vacaciones</h2>
                    <Link :href="indexSolicitudes()" :class="enlace"
                        >Ver detalle <ChevronRight class="size-3.5"
                    /></Link>
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
                    <div
                        class="rounded-xl p-2 transition-colors hover:bg-[var(--mrl-fondo)]"
                    >
                        <p class="text-2xl font-bold tabular-nums">
                            {{ vacaciones.dias_generados }}
                        </p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Generados
                        </p>
                    </div>
                    <div
                        class="rounded-xl p-2 transition-colors hover:bg-[var(--mrl-fondo)]"
                    >
                        <p class="text-2xl font-bold tabular-nums">
                            {{ vacaciones.dias_usados }}
                        </p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Usados
                        </p>
                    </div>
                    <div
                        class="rounded-xl p-2 transition-colors hover:bg-[var(--mrl-fondo)]"
                    >
                        <p
                            class="text-2xl font-bold text-[var(--mrl-primary)] tabular-nums"
                        >
                            {{ vacaciones.dias_disponibles }}
                        </p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Disponibles
                        </p>
                    </div>
                </div>
            </div>

            <div
                data-tour="portal-solicitudes"
                class="flex flex-col gap-3"
                :class="tarjeta"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Solicitudes recientes</h2>
                    <Link :href="indexSolicitudes()" :class="enlace"
                        >Ver todas <ChevronRight class="size-3.5"
                    /></Link>
                </div>

                <div
                    v-if="!solicitudes_recientes.length"
                    class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-[var(--mrl-borde)] p-6 text-center text-sm text-[var(--mrl-texto-suave)]"
                >
                    Todavía no tienes solicitudes.
                    <Link
                        :href="indexSolicitudes()"
                        class="font-medium text-[var(--mrl-primary)] hover:underline"
                        >Hacer una solicitud</Link
                    >
                </div>

                <div v-else class="flex flex-col gap-1">
                    <Link
                        v-for="solicitud in solicitudes_recientes.slice(0, 4)"
                        :key="solicitud.id"
                        :href="indexSolicitudes()"
                        class="group flex items-center justify-between gap-2 rounded-xl border border-transparent p-3 text-sm transition-all duration-150 hover:border-[var(--mrl-borde)] hover:bg-[var(--mrl-fondo)]"
                    >
                        <div class="min-w-0">
                            <p
                                class="truncate font-medium group-hover:text-[var(--mrl-primary)]"
                            >
                                {{ solicitud.folio }}
                            </p>
                            <p
                                class="truncate text-xs text-[var(--mrl-texto-suave)]"
                            >
                                {{ solicitud.motivo }}
                            </p>
                        </div>
                        <EstadoBadge :estado="solicitud.estado" />
                    </Link>
                </div>
            </div>

            <div
                data-tour="portal-notificaciones"
                class="flex flex-col gap-3 lg:col-span-2 xl:col-span-1"
                :class="tarjeta"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Notificaciones</h2>
                    <Link :href="indexNotificaciones()" :class="enlace"
                        >Ver todas <ChevronRight class="size-3.5"
                    /></Link>
                </div>

                <div
                    v-if="!notificaciones.recientes.length"
                    class="flex flex-1 items-center justify-center rounded-xl border border-dashed border-[var(--mrl-borde)] p-6 text-center text-sm text-[var(--mrl-texto-suave)]"
                >
                    Sin notificaciones por ahora.
                </div>

                <div v-else class="flex flex-col gap-1">
                    <button
                        v-for="notificacion in notificaciones.recientes.slice(
                            0,
                            4,
                        )"
                        :key="notificacion.id"
                        type="button"
                        class="flex w-full items-start gap-2 rounded-xl p-2.5 text-left text-sm transition-colors duration-150 hover:bg-[var(--mrl-fondo)] focus-visible:ring-2 focus-visible:ring-[var(--mrl-primary)] focus-visible:outline-none"
                        :class="
                            !notificacion.leida
                                ? 'bg-[var(--mrl-primary)]/5'
                                : ''
                        "
                        @click="abrir(notificacion)"
                    >
                        <span
                            class="mt-1.5 size-2 shrink-0 rounded-full"
                            :class="
                                !notificacion.leida
                                    ? 'bg-[var(--mrl-primary)]'
                                    : 'bg-transparent'
                            "
                        />
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ notificacion.titulo }}
                            </p>
                            <p
                                class="line-clamp-2 text-xs text-[var(--mrl-texto-suave)]"
                            >
                                {{ notificacion.mensaje }}
                            </p>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
