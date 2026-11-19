<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    ClipboardCheck,
    History,
    PartyPopper,
    UserCheck,
} from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import AvatarPersona from '@/components/evaluaciones-tecnicas/AvatarPersona.vue';
import ChipEstadoTecnica from '@/components/evaluaciones-tecnicas/ChipEstadoTecnica.vue';
import { Button } from '@/components/ui/button';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { textoPlazo } from '@/lib/evaluacionesTecnicas';
import { formatearFecha } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { contestar, show } from '@/routes/evaluaciones-tecnicas';
import { index as indexPortal } from '@/routes/portal';
import type { FilaEvaluacionTecnica } from '@/types';

/**
 * Mi espacio → «Mis evaluaciones técnicas»: las «Seguimiento N» que me toca
 * contestar, mis resultados y (si soy jefe directo) las de mi equipo.
 */
defineProps<{
    pendientes: FilaEvaluacionTecnica[];
    historial: FilaEvaluacionTecnica[];
    por_evaluar: FilaEvaluacionTecnica[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mi inicio', href: indexPortal() },
            { title: 'Mis evaluaciones técnicas', href: '' },
        ],
    },
});
</script>

<template>
    <Head title="Mis evaluaciones técnicas" />

    <div class="pagina-ancha flex flex-col gap-6 pb-10">
        <!-- Por contestar -->
        <section
            data-tour="mis-seguimientos-por-contestar"
            class="flex flex-col gap-3"
        >
            <h2 class="flex items-center gap-2 px-1 font-semibold">
                <ClipboardCheck class="size-5 text-bronce" /> Por contestar
            </h2>
            <ListaAnimada
                v-if="pendientes.length"
                tag="div"
                :cantidad="pendientes.length"
                class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3"
            >
                <Link
                    v-for="e in pendientes"
                    :key="e.id ?? e.nombre"
                    :href="contestar.url(e.id as number)"
                    :class="
                        cn(
                            'group relative flex tarjeta-interactiva flex-col gap-3 overflow-hidden rounded-3xl border bg-gradient-to-br from-salvia/60 via-card to-crema/50 p-5 shadow-xs',
                            e.estado === 'vencida'
                                ? 'border-destructive/40'
                                : 'border-border',
                        )
                    "
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-display text-xl font-semibold">
                                {{ e.nombre }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Día {{ e.dias }} desde tu ingreso<template
                                    v-if="e.es_final"
                                >
                                    · técnica final</template
                                >
                            </p>
                        </div>
                        <ChipEstadoTecnica :estado="e.estado" />
                    </div>
                    <p class="text-sm">
                        Apruebas con <strong>{{ e.calificacion_minima }}</strong
                        >. Tienes hasta el
                        <strong>{{ formatearFecha(e.fecha_limite) }}</strong>
                        <span
                            :class="
                                cn(
                                    'ml-1 text-xs font-medium',
                                    e.estado === 'vencida'
                                        ? 'text-destructive'
                                        : 'text-warning',
                                )
                            "
                            >({{ textoPlazo(e.dias_restantes) }})</span
                        >
                    </p>
                    <span
                        class="mt-auto inline-flex items-center gap-1.5 text-sm font-semibold text-primary"
                    >
                        Contestar ahora
                        <ArrowRight
                            class="size-4 transition-transform group-hover:translate-x-1"
                        />
                    </span>
                </Link>
            </ListaAnimada>
            <EmptyState
                v-else
                :icono="PartyPopper"
                titulo="No tienes evaluaciones técnicas pendientes"
                descripcion="Cuando llegue el día de tu siguiente Seguimiento (15, 30, 60…) te avisaremos y aparecerá aquí."
            />
        </section>

        <!-- Por evaluar (jefe directo) -->
        <section
            v-if="por_evaluar.length"
            data-tour="mis-seguimientos-por-evaluar"
            class="flex flex-col gap-3"
        >
            <h2 class="flex items-center gap-2 px-1 font-semibold">
                <UserCheck class="size-5 text-primary" /> Te toca evaluar a tu
                equipo
            </h2>
            <ListaAnimada
                tag="div"
                :cantidad="por_evaluar.length"
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4"
            >
                <Link
                    v-for="e in por_evaluar"
                    :key="e.id ?? e.nombre"
                    :href="contestar.url(e.id as number)"
                    class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border border-border bg-card p-3"
                >
                    <AvatarPersona
                        :nombre="e.colaborador?.nombre ?? ''"
                        :foto-url="e.colaborador?.foto_url"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ e.colaborador?.nombre }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ e.nombre }} · {{ textoPlazo(e.dias_restantes) }}
                        </p>
                    </div>
                </Link>
            </ListaAnimada>
        </section>

        <!-- Historial -->
        <section
            data-tour="mis-seguimientos-resultados"
            class="flex flex-col gap-3"
        >
            <h2 class="flex items-center gap-2 px-1 font-semibold">
                <History class="size-5 text-bronce" /> Mis resultados
            </h2>
            <div
                v-if="historial.length"
                class="overflow-hidden rounded-2xl border border-border bg-card shadow-xs"
            >
                <ul class="divide-y divide-border">
                    <li
                        v-for="e in historial"
                        :key="e.id ?? e.nombre"
                        class="flex flex-wrap items-center gap-4 px-5 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ e.nombre }}</p>
                            <p class="text-xs text-muted-foreground">
                                Contestada el
                                {{ formatearFecha(e.contestada_en ?? null) }} ·
                                {{ e.contesta_etiqueta }}
                            </p>
                        </div>
                        <span
                            v-if="e.calificacion !== null"
                            class="text-lg font-semibold tabular-nums"
                            >{{ e.calificacion }}</span
                        >
                        <ChipEstadoTecnica :estado="e.estado" />
                        <Button v-if="e.id" variant="ghost" size="sm" as-child>
                            <Link :href="show(e.id)">Ver</Link>
                        </Button>
                    </li>
                </ul>
            </div>
            <p
                v-else
                class="rounded-2xl bg-muted/40 px-4 py-6 text-center text-sm text-muted-foreground"
            >
                Aún no has contestado evaluaciones técnicas.
            </p>
        </section>
    </div>
</template>
