<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Camera,
    ChevronRight,
    FileText,
    MessageSquareWarning,
    UserPen,
} from '@lucide/vue';
import { computed } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import { formatearFechaHora } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import type { CorreccionItem, CorreccionesColaborador } from '@/types';

/**
 * «Mis correcciones»: datos, documentos y foto que el colaborador pidió
 * corregir (o que RH le rechazó), con un estado simple, el comentario de
 * Recursos Humanos y la acción que le toca. Lo mismo que ve la app
 * (GET /api/v1/correcciones, CorreccionesService).
 */
const props = withDefaults(
    defineProps<{
        correcciones: CorreccionesColaborador;
        /** Cuántas mostrar; el resto se ve en Mis solicitudes. */
        limite?: number;
    }>(),
    { limite: 6 },
);

const visibles = computed(() =>
    props.correcciones.items.slice(0, props.limite),
);

const ICONO: Record<CorreccionItem['origen'], typeof FileText> = {
    solicitud: UserPen,
    documento: FileText,
    foto: Camera,
};

/** EstadoBadge ya conoce los colores; «requiere información» = advertencia. */
function estadoBadge(item: CorreccionItem): string {
    return item.estado === 'requiere_informacion'
        ? 'requiere_correccion'
        : item.estado;
}

function accion(item: CorreccionItem): string {
    if (item.puede_responder) {
        return 'Responder a RH';
    }

    if (item.requiere_accion) {
        return item.origen === 'foto' ? 'Subir otra foto' : 'Subir de nuevo';
    }

    return 'Ver detalle';
}

/** Tres pasos simples: enviada → en revisión → resuelta. */
function paso(item: CorreccionItem): number {
    return (
        {
            enviada: 1,
            en_revision: 2,
            requiere_informacion: 2,
            aprobada: 3,
            rechazada: 3,
            cancelada: 3,
        } as Record<string, number>
    )[item.estado];
}

const PASOS = ['Enviada', 'En revisión', 'Resuelta'];
</script>

<template>
    <section
        class="rounded-2xl border border-border/60 bg-card p-4 sm:p-5"
        aria-labelledby="mis-correcciones-titulo"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="mis-correcciones-titulo" class="text-base font-semibold">
                Mis correcciones
            </h2>
            <div class="flex flex-wrap gap-2 text-xs font-medium">
                <span
                    v-if="correcciones.conteo.requieren_accion"
                    class="rounded-full bg-warning-soft px-2.5 py-1 text-warning"
                >
                    {{ correcciones.conteo.requieren_accion }}
                    {{
                        correcciones.conteo.requieren_accion === 1
                            ? 'requiere tu atención'
                            : 'requieren tu atención'
                    }}
                </span>
                <span
                    v-if="correcciones.conteo.en_proceso"
                    class="rounded-full bg-muted px-2.5 py-1 text-muted-foreground"
                >
                    {{ correcciones.conteo.en_proceso }} en revisión de RH
                </span>
            </div>
        </div>

        <ul class="mt-4 flex flex-col gap-3">
            <li v-for="item in visibles" :key="item.id">
                <Link
                    :href="item.url"
                    :class="
                        cn(
                            'flex tarjeta-interactiva flex-col gap-3 rounded-xl border p-3 sm:p-4',
                            item.requiere_accion
                                ? 'border-warning/50 bg-warning-soft/40'
                                : 'border-border/60',
                        )
                    "
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-crema text-[var(--brand-primary)]"
                        >
                            <component
                                :is="ICONO[item.origen]"
                                class="size-4"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold break-words">
                                    {{ item.titulo }}
                                </p>
                                <span
                                    v-if="item.folio"
                                    class="text-xs text-muted-foreground"
                                    >{{ item.folio }}</span
                                >
                            </div>
                            <p
                                v-if="item.campos.length"
                                class="mt-0.5 text-xs break-words text-muted-foreground"
                            >
                                {{ item.campos.join(' · ') }}
                            </p>
                        </div>
                        <EstadoBadge
                            :estado="estadoBadge(item)"
                            :etiqueta="item.estado_etiqueta"
                        />
                    </div>

                    <!-- Avance simple -->
                    <ol
                        class="flex items-center gap-1.5"
                        :aria-label="`Avance: ${item.estado_etiqueta}`"
                    >
                        <li
                            v-for="(nombre, i) in PASOS"
                            :key="nombre"
                            class="flex flex-1 flex-col gap-1"
                        >
                            <span
                                :class="
                                    cn(
                                        'h-1.5 rounded-full',
                                        i < paso(item)
                                            ? item.estado === 'rechazada' &&
                                              i === 2
                                                ? 'bg-destructive/70'
                                                : 'bg-[var(--brand-primary)]'
                                            : 'bg-muted',
                                    )
                                "
                            />
                            <span class="text-[11px] text-muted-foreground">{{
                                nombre
                            }}</span>
                        </li>
                    </ol>

                    <p class="text-sm text-muted-foreground">
                        {{ item.estado_descripcion }}
                    </p>

                    <div
                        v-if="item.comentario_rh"
                        class="flex items-start gap-2 rounded-lg bg-crema p-3 text-sm"
                    >
                        <MessageSquareWarning
                            class="mt-0.5 size-4 shrink-0 text-[var(--brand-primary)]"
                        />
                        <p class="min-w-0 break-words">
                            <span class="font-medium">Recursos Humanos:</span>
                            {{ item.comentario_rh }}
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
                    >
                        <span v-if="item.actualizada_en">
                            Actualizada
                            {{ formatearFechaHora(item.actualizada_en) }}
                            <template v-if="item.resuelta_por">
                                · por {{ item.resuelta_por }}</template
                            >
                        </span>
                        <span
                            class="inline-flex items-center gap-1 font-medium text-[var(--brand-primary)]"
                        >
                            {{ accion(item) }}
                            <ChevronRight class="size-3.5" />
                        </span>
                    </div>
                </Link>
            </li>
        </ul>
    </section>
</template>
