<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Award,
    CalendarClock,
    Check,
    ClipboardCheck,
    GraduationCap,
    Lock,
    MessageSquareText,
    MinusCircle,
    OctagonX,
    Presentation,
    Video,
    Volume2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { formatearFecha } from '@/lib/fechas';
import type { Tono } from '@/lib/tonos';
import {
    contenido as evaluacionInduccion,
    ver as verInduccion,
} from '@/routes/portal/induccion';
import type { MiInduccion, PasoMiInduccion } from '@/types';

/**
 * «Mi inducción»: la tarjeta con los pasos Presentación → Video → Evaluación
 * → Resultado, el avance y la acción que sigue. La MISMA tarjeta en Mi
 * inicio, Mis pendientes y en «Ver como colaborador» de RH (con
 * `vistaPrevia` la acción no navega). Los datos vienen de
 * ProgresoInduccionService — aquí no se calcula ninguna regla.
 */
const props = withDefaults(
    defineProps<{
        induccion: MiInduccion;
        /** RH: vista previa, sin navegar ni registrar nada. */
        vistaPrevia?: boolean;
        /** Sin la acción principal (p. ej. dentro del visor). */
        sinAccion?: boolean;
        compacto?: boolean;
    }>(),
    { vistaPrevia: false, sinAccion: false, compacto: false },
);

const emit = defineEmits<{ accion: [] }>();

const TONO: Record<MiInduccion['estado'], Tono> = {
    sin_asignar: 'neutro',
    disponible: 'primario',
    evaluacion_pendiente: 'alerta',
    en_revision: 'info',
    completada: 'exito',
};

function icono(paso: PasoMiInduccion): Component {
    if (paso.clave === 'presentacion') {
        return Presentation;
    }

    if (paso.clave === 'material') {
        return props.induccion.material?.tipo === 'audio' ? Volume2 : Video;
    }

    return paso.clave === 'evaluacion' ? ClipboardCheck : Award;
}

const accion = computed<{ etiqueta: string; href: string } | null>(() => {
    if (props.sinAccion) {
        return null;
    }

    if (props.induccion.puede_contestar_evaluacion) {
        return {
            etiqueta:
                (props.induccion.evaluacion?.intentos ?? 0) > 0
                    ? 'Volver a contestar mi evaluación'
                    : 'Contestar mi evaluación',
            href: evaluacionInduccion.url(),
        };
    }

    if (props.induccion.estado === 'disponible') {
        const empezada =
            props.induccion.porcentaje > 0 ||
            props.induccion.presentacion.paginas_vistas > 0;

        return {
            etiqueta: empezada
                ? 'Continuar mi inducción'
                : 'Empezar mi inducción',
            href: verInduccion.url(),
        };
    }

    return null;
});

const claseAccion =
    'inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground transition-all hover:gap-3 hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none sm:w-auto';
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-3xl border border-border/60 bg-card p-5 shadow-sm"
        :class="compacto ? 'gap-3 p-4' : ''"
        aria-label="Mi inducción"
    >
        <header class="flex items-start gap-3">
            <span
                class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-salvia text-primary"
            >
                <GraduationCap class="size-6" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-semibold">
                        {{
                            induccion.es_reinduccion
                                ? 'Mi reinducción'
                                : 'Mi inducción'
                        }}
                        Mr. Lana
                    </h2>
                    <ChipEstado
                        :etiqueta="induccion.etiqueta"
                        :tono="TONO[induccion.estado]"
                    />
                </div>
                <p class="mt-0.5 text-sm text-pretty text-muted-foreground">
                    {{ induccion.mensaje }}
                </p>
            </div>
            <span
                v-if="induccion.estado !== 'sin_asignar'"
                class="shrink-0 text-2xl font-bold text-primary tabular-nums"
                >{{ induccion.porcentaje }}%</span
            >
        </header>

        <div
            v-if="induccion.estado !== 'sin_asignar'"
            class="h-2 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-valuenow="induccion.porcentaje"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="h-full rounded-full bg-primary transition-all duration-500"
                :style="{ width: `${induccion.porcentaje}%` }"
            />
        </div>

        <ol
            class="grid gap-2"
            :class="
                compacto
                    ? 'grid-cols-2 sm:grid-cols-4'
                    : 'sm:grid-cols-2 xl:grid-cols-4'
            "
            aria-label="Pasos de la inducción"
        >
            <li
                v-for="(paso, indice) in induccion.pasos"
                :key="paso.clave"
                class="flex items-start gap-2.5 rounded-2xl border p-3"
                :class="{
                    'border-success/40 bg-success-soft/50':
                        paso.estado === 'completado',
                    'border-primary/50 bg-salvia/60 ring-2 ring-primary/15':
                        paso.estado === 'actual',
                    'border-destructive/40 bg-danger-soft/50':
                        paso.estado === 'detenido',
                    'border-border/60 bg-muted/30': paso.estado === 'pendiente',
                    'border-dashed border-border/60 opacity-60':
                        paso.estado === 'omitido',
                }"
                :aria-current="paso.estado === 'actual' ? 'step' : undefined"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-full"
                    :class="{
                        'bg-success text-white': paso.estado === 'completado',
                        'bg-primary text-primary-foreground':
                            paso.estado === 'actual',
                        'bg-destructive text-white': paso.estado === 'detenido',
                        'bg-muted text-muted-foreground':
                            paso.estado === 'pendiente' ||
                            paso.estado === 'omitido',
                    }"
                >
                    <Check v-if="paso.estado === 'completado'" class="size-4" />
                    <OctagonX
                        v-else-if="paso.estado === 'detenido'"
                        class="size-4"
                    />
                    <MinusCircle
                        v-else-if="paso.estado === 'omitido'"
                        class="size-4"
                    />
                    <Lock
                        v-else-if="
                            paso.estado === 'pendiente' &&
                            paso.clave === 'evaluacion'
                        "
                        class="size-4"
                    />
                    <component :is="icono(paso)" v-else class="size-4" />
                </span>
                <span class="min-w-0">
                    <span
                        class="block text-xs font-medium text-muted-foreground"
                        >Paso {{ indice + 1 }}</span
                    >
                    <span class="block text-sm font-semibold">{{
                        paso.titulo
                    }}</span>
                    <span
                        v-if="!compacto"
                        class="block text-xs text-pretty text-muted-foreground"
                        >{{ paso.detalle }}</span
                    >
                </span>
            </li>
        </ol>

        <div
            v-if="
                induccion.asignacion &&
                !induccion.asignacion.automatica &&
                (induccion.asignacion.fecha_limite ||
                    induccion.asignacion.mensaje)
            "
            class="flex flex-col gap-2 rounded-2xl bg-crema/70 p-3 text-sm"
        >
            <p
                v-if="induccion.asignacion.fecha_limite"
                class="flex items-center gap-2"
                :class="
                    induccion.asignacion.vencida
                        ? 'font-medium text-destructive'
                        : ''
                "
            >
                <CalendarClock class="size-4 shrink-0 text-bronce" />
                {{
                    induccion.asignacion.vencida
                        ? 'Venció el'
                        : 'Termínala antes del'
                }}
                {{ formatearFecha(induccion.asignacion.fecha_limite) }}
            </p>
            <p
                v-if="induccion.asignacion.mensaje"
                class="flex items-start gap-2"
            >
                <MessageSquareText class="mt-0.5 size-4 shrink-0 text-bronce" />
                <span
                    >Recursos Humanos:
                    <span class="italic"
                        >«{{ induccion.asignacion.mensaje }}»</span
                    ></span
                >
            </p>
        </div>

        <template v-if="accion">
            <button
                v-if="vistaPrevia"
                type="button"
                :class="claseAccion"
                title="Vista previa: aquí el colaborador entraría a su inducción"
                @click="emit('accion')"
            >
                {{ accion.etiqueta }} <ArrowRight class="size-4" />
            </button>
            <Link
                v-else
                :href="accion.href"
                :class="[claseAccion, 'self-start']"
            >
                {{ accion.etiqueta }} <ArrowRight class="size-4" />
            </Link>
        </template>
    </section>
</template>
