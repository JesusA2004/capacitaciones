<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    Download,
    Hourglass,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { SiguientePaso } from '@/types';

/**
 * «Dónde está y qué sigue»: estado → qué falta → un botón → hasta dos enlaces.
 * Todo viene del backend (SiguientePasoService), que solo manda un href si quien
 * consulta puede abrirlo. El slot recibe las demás acciones de la pantalla.
 */
const props = withDefaults(
    defineProps<{
        paso: SiguientePaso;
        /** Para pantallas con su propia tarea: sin progreso ni responsable. */
        compacto?: boolean;
        titulo?: string;
    }>(),
    { compacto: false, titulo: 'Siguiente paso' },
);

const emit = defineEmits<{ dialogo: [clave: string] }>();

const icono = computed(
    () =>
        ({
            accion: ArrowRight,
            espera: Hourglass,
            terminado: CheckCircle2,
        })[props.paso.tipo],
);
const colorIcono = computed(
    () =>
        ({
            accion: 'text-[var(--mrl-dorado)]',
            espera: 'text-[var(--mrl-texto-suave)]',
            terminado: 'text-success',
        })[props.paso.tipo],
);
const conProgreso = computed(
    () =>
        !props.compacto &&
        props.paso.modo === 'lineal' &&
        props.paso.progreso !== null,
);
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        :class="compacto ? 'p-4' : 'p-5'"
        :aria-label="titulo"
        data-siguiente-paso
    >
        <div
            v-if="paso.modo === 'lineal'"
            class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm"
        >
            <span class="text-[var(--mrl-texto-suave)]">Estado actual</span>
            <span class="font-semibold text-[var(--mrl-texto)]">{{
                paso.estado.etiqueta
            }}</span>
        </div>

        <div class="flex flex-col gap-3 rounded-xl bg-[var(--mrl-fondo)] p-4">
            <!-- aria-live: el cambio de paso tras una acción se anuncia. -->
            <div aria-live="polite">
                <Transition
                    mode="out-in"
                    enter-active-class="transition-opacity duration-200 motion-reduce:transition-none"
                    leave-active-class="transition-opacity duration-150 motion-reduce:transition-none"
                    enter-from-class="opacity-0"
                    leave-to-class="opacity-0"
                >
                    <p
                        :key="`${paso.tipo}-${paso.accion?.clave ?? paso.estado.clave}`"
                        class="flex items-start gap-2"
                    >
                        <component
                            :is="icono"
                            class="mt-0.5 size-5 shrink-0"
                            :class="colorIcono"
                            aria-hidden="true"
                        />
                        <span>
                            <span
                                class="block text-xs text-[var(--mrl-texto-suave)]"
                                >{{ titulo }}</span
                            >
                            <span
                                class="text-base font-semibold text-[var(--mrl-texto)]"
                                >{{ paso.mensaje }}</span
                            >
                        </span>
                    </p>
                </Transition>
            </div>

            <div
                v-if="paso.accion || paso.relacionadas.length"
                class="flex flex-wrap items-center gap-2"
            >
                <template v-if="paso.accion">
                    <Button
                        v-if="paso.accion.modo === 'dialogo'"
                        data-siguiente-accion
                        @click="emit('dialogo', paso.accion!.clave)"
                    >
                        {{ paso.accion.etiqueta }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Button>
                    <Button
                        v-else-if="paso.accion.descarga"
                        as-child
                        data-siguiente-accion
                    >
                        <a :href="paso.accion.href ?? undefined" download>
                            <Download class="size-4" aria-hidden="true" />
                            {{ paso.accion.etiqueta }}
                        </a>
                    </Button>
                    <Button v-else as-child data-siguiente-accion>
                        <Link :href="paso.accion.href ?? ''">
                            {{ paso.accion.etiqueta }}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </template>
                <Button
                    v-for="enlace in paso.relacionadas"
                    :key="enlace.clave"
                    variant="outline"
                    as-child
                >
                    <a v-if="enlace.descarga" :href="enlace.href" download>{{
                        enlace.etiqueta
                    }}</a>
                    <Link v-else :href="enlace.href">{{
                        enlace.etiqueta
                    }}</Link>
                </Button>
            </div>
            <slot />
        </div>

        <div v-if="conProgreso" class="flex flex-col gap-1.5">
            <div
                class="h-1.5 overflow-hidden rounded-full bg-[var(--mrl-fondo)]"
                role="progressbar"
                :aria-valuenow="paso.progreso ?? 0"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="Avance del proceso"
            >
                <div
                    class="h-full rounded-full bg-[var(--mrl-petroleo)] transition-[width] duration-300 motion-reduce:transition-none"
                    :style="{ width: `${paso.progreso}%` }"
                />
            </div>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                Responsable: {{ paso.responsable.rol
                }}<template v-if="paso.responsable.nombre">
                    · {{ paso.responsable.nombre }}</template
                >
                · {{ paso.progreso }}% del proceso
            </p>
        </div>

        <ul
            v-if="paso.bloqueos.length"
            class="flex flex-col gap-1.5 rounded-xl border border-[var(--mrl-rojo)]/25 bg-[var(--mrl-rojo)]/5 p-3"
            aria-label="Qué falta"
        >
            <li
                v-for="bloqueo in paso.bloqueos"
                :key="bloqueo"
                class="flex items-start gap-2 text-sm text-[var(--mrl-texto)]"
            >
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0 text-[var(--mrl-rojo)]"
                    aria-hidden="true"
                />
                {{ bloqueo }}
            </li>
        </ul>
    </section>
</template>
