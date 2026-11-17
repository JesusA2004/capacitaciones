<script setup lang="ts">
import { Deferred, Head } from '@inertiajs/vue3';
import { Award, Info, Users } from '@lucide/vue';
import { computed } from 'vue';
import InsigniaNivel from '@/components/Capacitacion/InsigniaNivel.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import DashboardChartCard from '@/components/Dashboard/DashboardChartCard.vue';
import Heading from '@/components/Heading.vue';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import {
    certificacion as rutaCertificacion,
    index as indexCapacitacion,
} from '@/routes/capacitacion';
import type { NivelCertificacionItem, TableroCertificacion } from '@/types';

const props = defineProps<{
    niveles: NivelCertificacionItem[];
    tablero?: TableroCertificacion;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Certificación Mr. Lana', href: rutaCertificacion() },
        ],
    },
});

const distribucion = computed(() => props.tablero?.distribucion ?? []);
</script>

<template>
    <Head title="Certificación Mr. Lana" />

    <div class="pagina-ancha flex flex-col gap-6">
        <section
            class="relative entrada-suave overflow-hidden rounded-3xl border border-oro/30 bg-linear-to-br from-crema via-card to-salvia p-6 shadow-sm sm:p-8"
        >
            <span
                class="pointer-events-none absolute -top-12 -right-12 size-52 rounded-full bg-oro/10"
                aria-hidden="true"
            />
            <div class="relative flex flex-col gap-4">
                <Heading
                    title="Certificación Mr. Lana"
                    description="Cada constancia de capacitación suma hacia el siguiente nivel."
                />
                <ul class="grid gap-3 md:grid-cols-3">
                    <li
                        v-for="nivel in niveles"
                        :key="nivel.clave"
                        class="flex flex-col gap-2 rounded-2xl bg-card/80 p-4 shadow-sm"
                    >
                        <InsigniaNivel
                            :nivel="nivel.clave"
                            :etiqueta="nivel.etiqueta"
                            tamano="lg"
                            class="self-start"
                        />
                        <p class="text-sm text-muted-foreground">
                            {{ nivel.descripcion }}
                        </p>
                        <p class="text-xs font-medium text-bronce">
                            Desde {{ nivel.umbral }}
                            {{
                                nivel.umbral === 1
                                    ? 'constancia'
                                    : 'constancias'
                            }}
                        </p>
                    </li>
                </ul>
                <p class="flex items-start gap-2 text-xs text-muted-foreground">
                    <Info class="mt-0.5 size-3.5 shrink-0" />
                    Base inicial: el nivel se calcula con las constancias
                    emitidas. Las rutas de cursos por puesto se definirán con
                    RH.
                </p>
            </div>
        </section>

        <Deferred data="tablero">
            <template #fallback>
                <div class="grid gap-4" aria-busy="true">
                    <div class="grid grid-cols-2 gap-3">
                        <Skeleton class="h-24 rounded-2xl" />
                        <Skeleton class="h-24 rounded-2xl" />
                    </div>
                    <Skeleton class="h-64 rounded-2xl" />
                </div>
            </template>

            <template v-if="tablero">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <MetricCard
                        etiqueta="Personas certificadas"
                        :valor="tablero.total_certificados"
                        :icono="Users"
                        color-clase="bg-salvia text-primary"
                    />
                    <MetricCard
                        etiqueta="Constancias emitidas"
                        :valor="tablero.total_constancias"
                        :icono="Award"
                        color-clase="bg-oro/20 text-bronce"
                    />
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <DashboardChartCard
                        title="Personas por nivel"
                        type="donut"
                        :data="distribucion"
                        label-key="etiqueta"
                        value-key="personas"
                        :colors="[
                            'var(--pastel-bronce)',
                            'var(--pastel-esmeralda)',
                            'var(--pastel-oro)',
                        ]"
                        empty-title="Nadie certificado todavía"
                        empty-description="Los niveles aparecen cuando se emiten constancias."
                    />

                    <section
                        class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-5 shadow-sm lg:col-span-2"
                    >
                        <h2 class="text-base font-semibold">
                            Quién va adelante
                        </h2>
                        <EmptyState
                            v-if="tablero.personas.length === 0"
                            :icono="Award"
                            titulo="Sin constancias en tu alcance"
                            descripcion="Cuando tu gente termine cursos con constancia, la verás aquí."
                        />
                        <ListaAnimada
                            v-else
                            tag="ol"
                            :cantidad="tablero.personas.length"
                            class="flex flex-col divide-y divide-border/60"
                        >
                            <li
                                v-for="(persona, i) in tablero.personas"
                                :key="persona.id"
                                class="flex items-center gap-3 py-2.5"
                            >
                                <span
                                    class="w-6 text-right text-xs font-semibold text-muted-foreground tabular-nums"
                                    >{{ i + 1 }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium">
                                        {{ persona.nombre }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ persona.constancias }}
                                        {{
                                            persona.constancias === 1
                                                ? 'constancia'
                                                : 'constancias'
                                        }}
                                        <template v-if="persona.ultima">
                                            · última
                                            {{ formatearFecha(persona.ultima) }}
                                        </template>
                                    </p>
                                </div>
                                <InsigniaNivel
                                    v-if="persona.nivel"
                                    :nivel="persona.nivel.clave"
                                    :etiqueta="persona.nivel.etiqueta"
                                />
                            </li>
                        </ListaAnimada>
                    </section>
                </div>
            </template>
        </Deferred>
    </div>
</template>
