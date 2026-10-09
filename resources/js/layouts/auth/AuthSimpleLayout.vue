<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    ClipboardList,
    Smartphone,
} from '@lucide/vue';
import horizontalColor from '@/assets/brand/people/MrLanaPeople_horizontal_color.svg';
import verticalNegativo from '@/assets/brand/people/MrLanaPeople_vertical_negativo.svg';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const CARACTERISTICAS = [
    {
        icono: CalendarClock,
        texto: 'Solicita tus vacaciones y permisos en minutos',
    },
    {
        icono: ClipboardList,
        texto: 'Da seguimiento a tus solicitudes y documentos',
    },
    { icono: Smartphone, texto: 'También disponible desde la app móvil' },
];

</script>

<template>
    <div class="relative flex h-svh overflow-hidden bg-background">
        <!-- Panel de marca: petróleo con el logo vertical negativo (zona
             amplia, fondo oscuro). Decorativo, oculto en móvil. -->
        <div
            class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-gradient-to-br from-[#0d3e43] via-[#174a4a] to-[#225c54] p-8 lg:flex xl:p-12"
        >
            <!-- Textura sutil -->
            <div
                aria-hidden="true"
                class="absolute inset-0 opacity-[0.07]"
                style="
                    background-image: radial-gradient(
                        circle,
                        #e9d6b0 1.2px,
                        transparent 1.2px
                    );
                    background-size: 28px 28px;
                "
            />
            <div
                aria-hidden="true"
                class="absolute -top-32 -right-24 size-[28rem] rounded-full bg-[#af8b51]/15 blur-3xl"
            />
            <div
                aria-hidden="true"
                class="absolute -bottom-24 -left-24 size-96 rounded-full bg-[#2f5937]/40 blur-3xl"
            />

            <Link
                :href="home()"
                class="relative z-10 text-xs font-semibold tracking-[0.3em] text-[#e9d6b0]/80 uppercase transition-opacity hover:opacity-80"
            >
                Portal de Recursos Humanos
            </Link>

            <div
                class="relative z-10 flex flex-1 flex-col items-center justify-center gap-10"
            >
                <img
                    :src="verticalNegativo"
                    alt="MR. LANA PEOPLE"
                    class="w-full max-w-sm animate-in object-contain duration-700 fade-in zoom-in-95"
                />
                <p
                    class="max-w-md animate-in text-center text-xl leading-snug font-medium text-white/90 delay-150 duration-700 fade-in slide-in-from-bottom-2 xl:text-2xl"
                    style="font-family: var(--font-display)"
                >
                    Reclutamiento, personal y RH en un solo lugar
                </p>
            </div>

            <ul class="relative z-10 grid gap-3 xl:grid-cols-3">
                <li
                    v-for="(item, i) in CARACTERISTICAS"
                    :key="item.texto"
                    class="flex animate-in items-center gap-3 rounded-2xl border border-white/10 bg-white/5 p-3 text-sm text-white/85 backdrop-blur-sm duration-700 fade-in slide-in-from-bottom-2"
                    :style="{ animationDelay: `${200 + i * 120}ms` }"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-[#af8b51]/25 text-[#e9d6b0]"
                    >
                        <component :is="item.icono" class="size-4" />
                    </span>
                    {{ item.texto }}
                </li>
            </ul>
        </div>

        <!-- Panel del formulario -->
        <div
            class="relative flex w-full flex-col items-center justify-center gap-6 overflow-y-auto p-4 sm:p-6 md:p-8 lg:w-1/2"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(ellipse_80%_60%_at_50%_-10%,color-mix(in_oklab,#af8b51_14%,transparent),transparent)]"
            />

            <div
                class="flex w-full max-w-md animate-in flex-col gap-6 duration-500 fade-in slide-in-from-bottom-4"
            >
                <Link :href="home()" class="mx-auto block w-full max-w-[18rem]">
                    <img
                        :src="horizontalColor"
                        alt="MR. LANA PEOPLE"
                        class="w-full object-contain"
                    />
                </Link>

                <div
                    class="rounded-3xl border border-border/70 bg-card p-6 shadow-xl shadow-[#0d3e43]/5 transition-shadow duration-300 hover:shadow-2xl sm:p-8"
                >
                    <div class="mb-5 space-y-1 text-center">
                        <h1 class="text-xl font-semibold tracking-tight">
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

