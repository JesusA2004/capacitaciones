<script setup lang="ts">
import { Download, Share, SquarePlus, X } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { useInstalarApp } from '@/composables/useInstalarApp';
import { useWebPush } from '@/composables/useWebPush';

/**
 * Franja discreta para instalar PEOPLE como app desde el navegador (PWA):
 * en Android/Chrome/Edge abre el diálogo del navegador; en iPhone/iPad
 * explica «Compartir › Agregar a inicio». No aparece si ya está instalada.
 * «Ahora no» la oculta 30 días en este navegador. Una sola franja a la
 * vez: mientras está la de notificaciones (BannerNotificacionesEscritorio),
 * esta espera.
 */
const { modo, visible: disponible, instalar, ahoraNo } = useInstalarApp();
const { estado } = useWebPush();

function avisoNotificacionesOculto(): boolean {
    try {
        return (
            Number(
                localStorage.getItem(
                    'people:banner-notificaciones-oculto-hasta',
                ) ?? 0,
            ) > Date.now()
        );
    } catch {
        return false;
    }
}

const visible = computed(
    () =>
        disponible.value &&
        (estado.value !== 'pendiente' || avisoNotificacionesOculto()),
);

async function alInstalar(): Promise<void> {
    if (await instalar()) {
        toast.success('PEOPLE quedó instalada', {
            description: 'Ábrela desde el ícono en tu pantalla de inicio.',
        });
    }
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="-translate-y-2 opacity-0"
        leave-active-class="transition duration-200 ease-in"
        leave-to-class="-translate-y-2 opacity-0"
    >
        <div
            v-if="visible"
            role="region"
            aria-label="Instalar PEOPLE como app"
            class="mx-4 mt-2 flex items-center gap-2 rounded-xl border border-esmeralda/25 bg-menta px-2.5 py-1.5 sm:mx-6 sm:mt-3 sm:gap-3 sm:rounded-2xl sm:px-3 sm:py-2.5 md:mx-8"
            data-banner-instalar-app
        >
            <span
                class="hidden size-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground sm:flex"
            >
                <Download class="size-4" />
            </span>

            <div
                v-if="modo === 'android'"
                class="flex min-w-0 flex-1 flex-col sm:flex-row sm:items-center sm:gap-2"
            >
                <p
                    class="truncate text-xs font-semibold text-foreground sm:text-sm"
                >
                    Instala PEOPLE como app
                </p>
                <p
                    class="hidden text-xs text-muted-foreground sm:block sm:text-sm"
                >
                    Ábrela desde tu inicio, sin buscar la página en el
                    navegador.
                </p>
            </div>

            <div v-else class="min-w-0 flex-1">
                <p class="text-xs font-semibold text-foreground sm:text-sm">
                    Instala PEOPLE en tu iPhone
                </p>
                <p class="text-xs leading-snug text-muted-foreground">
                    En Safari toca
                    <Share
                        class="inline size-3.5 align-text-bottom text-foreground"
                        aria-label="Compartir"
                    />
                    <span class="font-medium text-foreground">Compartir</span>
                    y luego
                    <SquarePlus
                        class="inline size-3.5 align-text-bottom text-foreground"
                        aria-hidden="true"
                    />
                    <span class="font-medium text-foreground"
                        >Agregar a inicio</span
                    >.
                </p>
            </div>

            <Button
                v-if="modo === 'android'"
                size="sm"
                class="shrink-0"
                @click="alInstalar"
            >
                Instalar
            </Button>
            <button
                type="button"
                class="flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-background/60 hover:text-foreground"
                aria-label="Ahora no"
                title="Ahora no"
                @click="ahoraNo"
            >
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
