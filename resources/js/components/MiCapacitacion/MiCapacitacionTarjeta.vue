<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Award, GraduationCap, ListTodo } from '@lucide/vue';
import AnilloProgreso from '@/components/Capacitacion/AnilloProgreso.vue';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';
import { formatearFecha } from '@/lib/fechas';
import {
    index as miCapacitacion,
    pendientes as misPendientes,
    show as showCurso,
} from '@/routes/mi-capacitacion';
import type { ResumenMiCapacitacionPortal } from '@/types';

/**
 * Tarjeta del inicio del colaborador: cuántos cursos lleva, cuánto le falta
 * y la siguiente lección que puede hacer (mismos datos que «Mi capacitación»).
 */
defineProps<{ resumen: ResumenMiCapacitacionPortal }>();
</script>

<template>
    <section
        class="relative entrada-suave overflow-hidden rounded-3xl border border-border/60 bg-linear-to-br from-salvia via-card to-menta p-5 shadow-sm sm:p-6"
        aria-labelledby="titulo-mi-capacitacion"
    >
        <span
            class="pointer-events-none absolute -top-14 -right-10 size-44 rounded-full bg-card/40"
            aria-hidden="true"
        />
        <div
            class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between"
        >
            <div class="flex items-center gap-4">
                <AnilloProgreso
                    class="shrink-0 text-esmeralda"
                    :valor="resumen.progreso_promedio"
                    :tamano="64"
                    :grosor="6"
                />
                <div class="min-w-0">
                    <p
                        class="inline-flex items-center gap-1.5 text-xs font-semibold tracking-wide text-primary uppercase"
                    >
                        <GraduationCap class="size-3.5" /> Mi capacitación
                    </p>
                    <h2
                        id="titulo-mi-capacitacion"
                        class="text-lg font-semibold text-pretty"
                    >
                        {{
                            resumen.pendientes > 0
                                ? `Tienes ${resumen.pendientes} ${resumen.pendientes === 1 ? 'lección pendiente' : 'lecciones pendientes'}`
                                : resumen.en_curso > 0
                                  ? 'Vas al día con tus cursos'
                                  : 'Terminaste todos tus cursos'
                        }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        <NumeroAnimado :valor="resumen.en_curso" />
                        {{ resumen.en_curso === 1 ? 'curso' : 'cursos' }} en
                        curso ·
                        <Award class="inline size-3.5 text-bronce" />
                        <NumeroAnimado :valor="resumen.constancias" />
                        {{
                            resumen.constancias === 1
                                ? 'constancia'
                                : 'constancias'
                        }}
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <Link
                    v-if="resumen.siguiente"
                    :href="showCurso(resumen.siguiente.curso_id)"
                    class="group flex min-w-0 tarjeta-interactiva items-center gap-3 rounded-2xl border border-border/60 bg-card/90 px-4 py-3 shadow-sm sm:max-w-md"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block text-xs text-muted-foreground"
                            >Sigue con · {{ resumen.siguiente.tipo_etiqueta
                            }}<template v-if="resumen.siguiente.fecha">
                                · {{ formatearFecha(resumen.siguiente.fecha) }}
                            </template></span
                        >
                        <span class="block truncate text-sm font-semibold">{{
                            resumen.siguiente.titulo
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ resumen.siguiente.curso }}</span
                        >
                    </span>
                    <ArrowRight
                        class="size-4 shrink-0 icono-avanza text-primary"
                    />
                </Link>
                <div class="flex gap-2">
                    <Link
                        v-if="resumen.pendientes > 0"
                        :href="misPendientes()"
                        class="flex presionable items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium text-primary transicion-ui hover:bg-card/70"
                    >
                        <ListTodo class="size-4" /> Pendientes
                    </Link>
                    <Link
                        :href="miCapacitacion()"
                        class="flex presionable items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium text-primary transicion-ui hover:bg-card/70"
                    >
                        Ver todo
                    </Link>
                </div>
            </div>
        </div>
    </section>
</template>
