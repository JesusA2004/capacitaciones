<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookOpen, Compass } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { tomarTourPendiente, useTourGuiado } from '@/composables/useTourGuiado';
import { toursDisponibles } from '@/lib/tours/registro';

/**
 * Botón flotante presente en todas las pantallas autenticadas (montado una
 * sola vez en `AppSidebarLayout.vue`). Ofrece el/los tours guiados
 * disponibles para la pantalla actual (según `toursDisponibles()`) y acceso
 * directo a la guía ilustrada completa (`/ayuda`). El pulso de atención solo
 * se muestra si hay un tour nuevo (nunca visto) en esta pantalla, para no
 * volverse ruidoso una vez que el usuario ya conoce el sistema.
 */
const { currentUrl } = useCurrentUrl();
const { iniciar, haVisto } = useTourGuiado();

const tours = computed(() => toursDisponibles(currentUrl.value));
const hayTourNuevo = computed(() => tours.value.some((t) => !haVisto(t.id)));

const abierto = ref(false);

function lanzar(id: string): void {
    const tour = tours.value.find((t) => t.id === id);
    abierto.value = false;

    if (tour) {
iniciar(tour);
}
}

// Si se llegó aquí desde "Ver cómo funciona esto" en la guía ilustrada,
// inicia el tour correspondiente automáticamente en vez de exigir un
// segundo clic sobre este botón. Este componente vive dentro del layout
// persistente de Inertia (no se remonta entre visitas), así que hay que
// reaccionar al CAMBIO de URL — `onMounted` solo dispararía una vez por
// carga completa de página, no en cada navegación SPA.
watch(
    currentUrl,
    (url) => {
        const idPendiente = tomarTourPendiente(url);

        if (!idPendiente) {
return;
}

        const tour = toursDisponibles(url).find((t) => t.id === idPendiente);

        if (tour) {
iniciar(tour);
}
    },
    { immediate: true },
);
</script>

<template>
    <Popover v-model:open="abierto">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="fixed right-5 bottom-5 z-50 flex size-12 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg transition-transform hover:scale-105 hover:bg-primary/90 active:scale-95"
                aria-label="Ayuda y guías del sistema"
            >
                <span
                    v-if="hayTourNuevo"
                    class="absolute inset-0 animate-ping rounded-full bg-primary opacity-75"
                />
                <Compass class="relative size-5" />
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            class="w-[min(20rem,calc(100vw-2rem))] space-y-3"
        >
            <div class="space-y-1">
                <p class="text-sm font-semibold">¿Necesitas ayuda aquí?</p>
                <p class="text-xs text-muted-foreground">
                    Un tour te muestra, sobre la propia pantalla, para qué sirve
                    cada cosa.
                </p>
            </div>

            <div v-if="tours.length" class="space-y-1.5">
                <Button
                    v-for="tour in tours"
                    :key="tour.id"
                    variant="outline"
                    size="sm"
                    class="h-auto w-full justify-start gap-2 py-2 text-left whitespace-normal"
                    @click="lanzar(tour.id)"
                >
                    <Compass class="size-3.5 shrink-0" />
                    <span class="min-w-0 flex-1 break-words">{{
                        tour.titulo
                    }}</span>
                    <span
                        v-if="!haVisto(tour.id)"
                        class="size-1.5 shrink-0 rounded-full bg-primary"
                    />
                </Button>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                Esta pantalla todavía no tiene un tour dedicado.
            </p>

            <Button
                variant="ghost"
                size="sm"
                class="w-full justify-start"
                as-child
            >
                <Link href="/ayuda">
                    <BookOpen class="size-3.5" /> Ver guía completa del sistema
                </Link>
            </Button>
        </PopoverContent>
    </Popover>
</template>
