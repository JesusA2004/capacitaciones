<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookOpen, CircleHelp, Compass, Route } from '@lucide/vue';
import { useWindowScroll } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useNavegacion } from '@/composables/useNavegacion';
import { usePermisos } from '@/composables/usePermisos';
import { useTourGuiado } from '@/composables/useTourGuiado';
import { articulo } from '@/lib/guia';
import { articuloDePantalla } from '@/lib/guia/contextual';
import { tourCompleto, toursDisponibles } from '@/lib/tours/registro';
import type { ContextoGuia, Tour } from '@/lib/tours/tipos';

/**
 * Botón flotante presente en todas las pantallas autenticadas (montado una
 * sola vez en `AppSidebarLayout.vue`). Ofrece el recorrido paso a paso de
 * la pantalla actual, el recorrido completo del sistema (que navega solo
 * por todos los módulos del usuario) y la guía ilustrada (`/ayuda`). El
 * pulso de atención solo aparece si hay algo que el usuario nunca ha visto.
 */
const { currentUrl } = useCurrentUrl();
const { iniciar, haVisto } = useTourGuiado();
const { tienePermiso } = usePermisos();
const { esColaborador } = useNavegacion();

const contexto = computed<ContextoGuia>(() => ({
    tienePermiso,
    modo: esColaborador.value ? 'colaborador' : 'operativo',
}));

const tours = computed(() =>
    toursDisponibles(currentUrl.value, contexto.value),
);
const recorrido = computed(() => tourCompleto(contexto.value));
const hayTourNuevo = computed(
    () =>
        !haVisto(recorrido.value.id) || tours.value.some((t) => !haVisto(t.id)),
);

/** Artículo de la Guía que explica la pantalla actual (si hay). */
const articuloPantalla = computed(() => {
    const id = articuloDePantalla(currentUrl.value);

    return id ? articulo(id) : undefined;
});

const abierto = ref(false);

/*
 * Discreto: 40 px, semitransparente hasta que lo apuntas, y se ESCONDE
 * mientras bajas por la página (vuelve al subir o al llegar al final). El
 * layout deja un margen inferior para que al final del contenido nunca
 * tape un botón (AppSidebarLayout).
 */
const { y } = useWindowScroll();
const escondido = ref(false);

watch(y, (actual, anterior) => {
    const alFinal =
        window.innerHeight + actual >=
        document.documentElement.scrollHeight - 8;

    if (alFinal || actual < 80) {
        escondido.value = false;
    } else if (Math.abs(actual - anterior) > 4) {
        escondido.value = actual > anterior;
    }
});

const ocultarBoton = computed(() => escondido.value && !abierto.value);

function lanzar(tour: Tour): void {
    abierto.value = false;
    iniciar(tour);
}
</script>

<template>
    <Popover v-model:open="abierto">
        <PopoverTrigger as-child>
            <button
                type="button"
                data-tour="boton-ayuda"
                class="fixed right-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-50 flex size-10 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-md transition-[transform,opacity] duration-200 hover:bg-primary/90 active:scale-95 sm:right-4 sm:bottom-4"
                :class="
                    ocultarBoton
                        ? 'pointer-events-none translate-y-28 opacity-0'
                        : 'opacity-85 hover:scale-105 hover:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100'
                "
                :aria-hidden="ocultarBoton || undefined"
                :tabindex="ocultarBoton ? -1 : undefined"
                aria-label="Ayuda y guías del sistema"
            >
                <span
                    v-if="hayTourNuevo"
                    class="absolute inset-0 animate-ping rounded-full bg-primary opacity-75"
                />
                <Compass class="relative size-[18px]" />
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            class="w-[min(21rem,calc(100vw-2rem))] space-y-3"
        >
            <div class="space-y-1">
                <p class="text-sm font-semibold">¿Necesitas ayuda?</p>
                <p class="text-xs text-muted-foreground">
                    La guía te lleva paso a paso sobre la propia pantalla y
                    cambia de módulo por ti.
                </p>
            </div>

            <div class="space-y-1.5">
                <p
                    class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    En esta pantalla
                </p>
                <Button
                    v-for="tour in tours"
                    :key="tour.id"
                    variant="outline"
                    size="sm"
                    class="h-auto w-full justify-start gap-2 py-2 text-left whitespace-normal"
                    @click="lanzar(tour)"
                >
                    <Compass class="size-3.5 shrink-0" />
                    <span class="min-w-0 flex-1 break-words">
                        {{ tour.titulo }}
                        <span class="block text-[11px] text-muted-foreground">
                            {{ tour.pasos.length }} pasos
                        </span>
                    </span>
                    <span
                        v-if="!haVisto(tour.id)"
                        class="size-1.5 shrink-0 rounded-full bg-primary"
                    />
                </Button>
                <p v-if="!tours.length" class="text-xs text-muted-foreground">
                    Esta pantalla no tiene un recorrido propio, pero el
                    recorrido completo te enseña todos tus módulos.
                </p>
            </div>

            <Button
                size="sm"
                class="h-auto w-full justify-start gap-2 py-2 text-left whitespace-normal"
                @click="lanzar(recorrido)"
            >
                <Route class="size-3.5 shrink-0" />
                <span class="min-w-0 flex-1">
                    Recorrido completo del sistema
                    <span class="block text-[11px] opacity-80">
                        {{ recorrido.pasos.length }} pasos · navega solo por
                        cada módulo
                    </span>
                </span>
            </Button>

            <Button
                v-if="articuloPantalla"
                variant="outline"
                size="sm"
                class="h-auto w-full justify-start gap-2 py-2 text-left whitespace-normal"
                as-child
            >
                <Link
                    :href="`/ayuda/articulo/${articuloPantalla.id}`"
                    @click="abierto = false"
                >
                    <CircleHelp class="size-3.5 shrink-0" />
                    <span class="min-w-0 flex-1">
                        ¿Cómo funciona esta pantalla?
                        <span class="block text-[11px] text-muted-foreground">
                            {{ articuloPantalla.titulo }}
                        </span>
                    </span>
                </Link>
            </Button>

            <Button
                variant="ghost"
                size="sm"
                class="w-full justify-start"
                as-child
            >
                <Link href="/ayuda" @click="abierto = false">
                    <BookOpen class="size-3.5" /> Ver guía completa del sistema
                </Link>
            </Button>
        </PopoverContent>
    </Popover>
</template>
