<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Award,
    CalendarClock,
    ClipboardList,
    Fingerprint,
    Smartphone,
    Sparkles,
    Star,
} from '@lucide/vue';
import logo from '@/assets/brand/logo.png';
import mascotRight from '@/assets/brand/mascot-right.png';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const CARACTERISTICAS = [
    { icono: CalendarClock, texto: 'Solicita tus vacaciones y permisos en minutos' },
    { icono: ClipboardList, texto: 'Da seguimiento a tus solicitudes y documentos' },
    { icono: Smartphone, texto: 'También disponible desde la app móvil' },
];

const ICONOS_FLOTANTES = [
    { icono: Sparkles, top: '20%', left: '18%', delay: '0s' },
    { icono: Star, top: '68%', left: '14%', delay: '1.4s' },
    { icono: Award, top: '30%', left: '78%', delay: '2.6s' },
    { icono: Fingerprint, top: '72%', left: '72%', delay: '0.8s' },
];
</script>

<template>
    <div class="relative flex h-svh overflow-hidden bg-background">
        <!-- Panel izquierdo: marca, decorativo, oculto en móvil -->
        <div
            class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-gradient-to-br from-[var(--brand-primary)] via-[var(--brand-primary)] to-[var(--brand-secondary)] p-8 lg:flex xl:p-10"
        >
            <!-- Textura de puntos -->
            <div
                aria-hidden="true"
                class="absolute inset-0 opacity-[0.15]"
                style="background-image: radial-gradient(circle, white 1.5px, transparent 1.5px); background-size: 26px 26px;"
            />

            <!-- Manchas suaves de fondo -->
            <div
                aria-hidden="true"
                class="absolute -top-24 -left-16 size-80 rounded-full bg-white/10 blur-3xl"
            />
            <div
                aria-hidden="true"
                class="absolute -right-20 bottom-0 size-96 rounded-full bg-black/10 blur-3xl"
            />

            <!-- Puntos flotantes -->
            <span
                v-for="(punto, i) in 10"
                :key="i"
                aria-hidden="true"
                class="punto-flotante absolute rounded-full bg-white"
                :class="`punto-flotante--${i + 1}`"
            />

            <!-- Iconos flotantes -->
            <span
                v-for="(item, i) in ICONOS_FLOTANTES"
                :key="i"
                aria-hidden="true"
                class="icono-flotante absolute flex size-10 items-center justify-center rounded-2xl bg-white/10 text-white/70 backdrop-blur-sm"
                :style="{ top: item.top, left: item.left, animationDelay: item.delay }"
            >
                <component :is="item.icono" class="size-4.5" />
            </span>

            <Link
                :href="home()"
                class="relative z-10 flex items-center gap-2 text-sm font-medium text-white/90 transition-opacity hover:opacity-80"
            >
                <span
                    class="flex size-8 items-center justify-center rounded-lg bg-white/15 backdrop-blur-sm"
                >
                    <img :src="logo" alt="" class="size-5 object-contain" />
                </span>
                MR. LANA <span class="font-semibold">PEOPLE</span>
            </Link>

            <div class="relative z-10 flex flex-1 flex-col justify-center gap-3 pr-[38%]">
                <span
                    class="animate-in fade-in zoom-in-90 flex w-fit items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold tracking-wide text-white/90 backdrop-blur-sm duration-700"
                >
                    <Sparkles class="size-3.5" />
                    BIENVENIDO DE VUELTA
                </span>
                <p
                    class="animate-in fade-in slide-in-from-bottom-2 text-2xl leading-snug font-semibold text-white delay-150 duration-700 xl:text-3xl"
                >
                    Reclutamiento, personal y RH en un solo lugar
                </p>
            </div>

            <div class="relative z-10 flex items-end justify-between gap-4">
                <ul class="flex flex-col gap-3 pr-[38%] pb-2">
                    <li
                        v-for="(item, i) in CARACTERISTICAS"
                        :key="item.texto"
                        class="animate-in fade-in slide-in-from-bottom-2 flex items-center gap-3 text-sm text-white/90 duration-700"
                        :style="{ animationDelay: `${200 + i * 120}ms` }"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full bg-white/15"
                        >
                            <component :is="item.icono" class="size-3.5" />
                        </span>
                        {{ item.texto }}
                    </li>
                </ul>
            </div>

            <!-- Mascota: fuera del flujo, anclada a la esquina inferior derecha (estática, sin flotar) -->
            <img
                :src="mascotRight"
                alt=""
                aria-hidden="true"
                class="pointer-events-none absolute right-[-12px] bottom-6 z-10 w-[46%] max-w-[270px] drop-shadow-2xl"
            />
        </div>

        <!-- Panel derecho: formulario -->
        <div
            class="relative flex w-full flex-col items-center justify-center gap-6 overflow-hidden p-4 sm:p-6 md:p-8 lg:w-1/2"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(ellipse_80%_60%_at_50%_-10%,color-mix(in_oklab,var(--brand-primary)_14%,transparent),transparent)] lg:hidden"
            />

            <div class="animate-in fade-in slide-in-from-bottom-4 flex w-full max-w-md flex-col gap-4 duration-500">
                <Link
                    :href="home()"
                    class="group flex flex-col items-center gap-2 font-medium"
                >
                    <div
                        class="flex size-12 items-center justify-center rounded-2xl bg-[var(--brand-primary)] text-[var(--brand-foreground)] shadow-lg shadow-[var(--brand-primary)]/20 transition-shadow duration-300 group-hover:shadow-xl"
                    >
                        <img :src="logo" alt="" class="size-7 object-contain" />
                    </div>
                    <span class="text-base font-semibold tracking-tight text-foreground">
                        MR. LANA <span class="text-[var(--brand-primary)]">PEOPLE</span>
                    </span>
                </Link>

                <div
                    class="rounded-3xl border border-border/60 bg-card/95 p-5 shadow-xl shadow-black/5 backdrop-blur-sm transition-shadow duration-300 hover:shadow-2xl sm:p-6"
                >
                    <div class="mb-4 space-y-1 text-center">
                        <h1 class="text-lg font-semibold tracking-tight">
                            {{ title }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes flotar {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-14px);
    }
}

.punto-flotante {
    animation: flotar 5s ease-in-out infinite;
}

.punto-flotante--1 {
    top: 18%;
    left: 12%;
    width: 8px;
    height: 8px;
    opacity: 0.5;
    animation-delay: 0s;
}
.punto-flotante--2 {
    top: 30%;
    right: 16%;
    width: 6px;
    height: 6px;
    opacity: 0.4;
    animation-delay: 0.8s;
}
.punto-flotante--3 {
    top: 55%;
    left: 22%;
    width: 10px;
    height: 10px;
    opacity: 0.35;
    animation-delay: 1.6s;
}
.punto-flotante--4 {
    top: 68%;
    right: 24%;
    width: 7px;
    height: 7px;
    opacity: 0.45;
    animation-delay: 2.4s;
}
.punto-flotante--5 {
    top: 42%;
    left: 45%;
    width: 5px;
    height: 5px;
    opacity: 0.3;
    animation-delay: 3.2s;
}
.punto-flotante--6 {
    top: 12%;
    right: 38%;
    width: 9px;
    height: 9px;
    opacity: 0.4;
    animation-delay: 4s;
}
.punto-flotante--7 {
    top: 82%;
    left: 50%;
    width: 6px;
    height: 6px;
    opacity: 0.35;
    animation-delay: 0.4s;
}
.punto-flotante--8 {
    top: 8%;
    left: 48%;
    width: 5px;
    height: 5px;
    opacity: 0.3;
    animation-delay: 2s;
}
.punto-flotante--9 {
    top: 60%;
    right: 8%;
    width: 8px;
    height: 8px;
    opacity: 0.4;
    animation-delay: 3.6s;
}
.punto-flotante--10 {
    top: 46%;
    left: 8%;
    width: 6px;
    height: 6px;
    opacity: 0.35;
    animation-delay: 1.2s;
}

@keyframes flotarIcono {
    0%,
    100% {
        transform: translateY(0) rotate(0deg);
    }
    50% {
        transform: translateY(-10px) rotate(6deg);
    }
}

.icono-flotante {
    animation: flotarIcono 6s ease-in-out infinite;
}

@media (prefers-reduced-motion: reduce) {
    .punto-flotante,
    .icono-flotante {
        animation: none;
    }
}
</style>
