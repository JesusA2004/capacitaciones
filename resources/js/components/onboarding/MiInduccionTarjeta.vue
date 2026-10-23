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
 *
 * Responsive por el ancho de la TARJETA (container query `induccion`), no
 * de la ventana: la misma tarjeta vive a todo el ancho en Mi inicio y en el
 * lateral angosto del visor. Angosta → línea de tiempo vertical; ≥ 36rem →
 * stepper horizontal de 4 columnas con conectores.
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

// Botón principal: ancho completo cuando la tarjeta es angosta (móvil o el
// lateral del visor) y de su tamaño cuando hay espacio.
const claseAccion =
    'inline-flex min-h-11 w-full items-center justify-center gap-2 self-start rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-all hover:gap-3 hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none @lg/induccion:w-auto';

const CIRCULO: Record<PasoMiInduccion['estado'], string> = {
    completado: 'bg-success text-white',
    actual: 'bg-primary text-primary-foreground ring-4 ring-primary/15',
    detenido: 'bg-destructive text-white ring-4 ring-destructive/15',
    pendiente: 'border border-border bg-card text-muted-foreground',
    omitido:
        'border border-dashed border-border bg-muted/40 text-muted-foreground',
};

const TITULO: Record<PasoMiInduccion['estado'], string> = {
    completado: 'text-foreground',
    actual: 'text-primary',
    detenido: 'text-destructive',
    pendiente: 'text-foreground/80',
    omitido: 'text-muted-foreground',
};
</script>

<template>
    <section
        class="@container/induccion flex flex-col rounded-3xl border border-border/60 bg-card shadow-sm"
        :class="compacto ? 'gap-4 p-4 sm:p-5' : 'gap-5 p-5 sm:p-6'"
        aria-label="Mi inducción"
    >
        <header class="flex items-start gap-3 @md/induccion:gap-4">
            <span
                class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-salvia text-primary @xl/induccion:size-12"
            >
                <GraduationCap class="size-6" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h2 class="text-base font-semibold @xl/induccion:text-lg">
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
                <p
                    class="mt-1 max-w-prose text-sm text-pretty text-muted-foreground"
                >
                    {{ induccion.mensaje }}
                </p>
            </div>
            <!-- Avance grande solo si la tarjeta tiene espacio; en angosto va
                 junto a la barra para no apretar el mensaje. -->
            <div
                v-if="induccion.estado !== 'sin_asignar'"
                class="hidden shrink-0 text-right @md/induccion:block"
            >
                <p
                    class="text-3xl leading-none font-bold text-primary tabular-nums"
                >
                    {{ induccion.porcentaje }}%
                </p>
                <p class="mt-1 text-xs text-muted-foreground">de avance</p>
            </div>
        </header>

        <div
            v-if="induccion.estado !== 'sin_asignar'"
            class="flex items-center gap-3"
        >
            <div
                class="h-2.5 flex-1 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="induccion.porcentaje"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="Avance de la inducción"
            >
                <div
                    class="h-full rounded-full bg-primary transition-all duration-500"
                    :style="{ width: `${induccion.porcentaje}%` }"
                />
            </div>
            <span
                class="shrink-0 text-sm font-bold text-primary tabular-nums @md/induccion:hidden"
                >{{ induccion.porcentaje }}%</span
            >
        </div>

        <!-- Pasos: línea de tiempo vertical cuando la tarjeta es angosta y
             stepper horizontal de 4 columnas cuando mide ≥ 36rem. -->
        <ol
            class="grid grid-cols-1 @xl/induccion:grid-cols-4"
            aria-label="Pasos de la inducción"
        >
            <li
                v-for="(paso, indice) in induccion.pasos"
                :key="paso.clave"
                class="relative flex gap-3 pb-5 last:pb-0 @xl/induccion:flex-col @xl/induccion:items-center @xl/induccion:gap-2.5 @xl/induccion:px-3 @xl/induccion:pb-0 @xl/induccion:text-center"
                :class="paso.estado === 'omitido' ? 'opacity-70' : ''"
                :aria-current="paso.estado === 'actual' ? 'step' : undefined"
            >
                <!-- Conector hacia el siguiente paso -->
                <span
                    v-if="indice < induccion.pasos.length - 1"
                    aria-hidden="true"
                    class="absolute top-11 bottom-1 left-[1.125rem] w-0.5 -translate-x-1/2 rounded-full @xl/induccion:top-[1.0625rem] @xl/induccion:right-[calc(-50%+1.75rem)] @xl/induccion:bottom-auto @xl/induccion:left-[calc(50%+1.75rem)] @xl/induccion:h-0.5 @xl/induccion:w-auto @xl/induccion:translate-x-0"
                    :class="
                        paso.estado === 'completado'
                            ? 'bg-success/60'
                            : 'bg-border'
                    "
                />
                <span
                    class="relative flex size-9 shrink-0 items-center justify-center rounded-full transition-colors"
                    :class="CIRCULO[paso.estado]"
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
                <div
                    class="min-w-0 flex-1 pt-0.5 @xl/induccion:max-w-[15rem] @xl/induccion:flex-none @xl/induccion:pt-0"
                >
                    <p
                        class="flex min-h-5 flex-wrap items-center gap-x-2 gap-y-0.5 text-[0.7rem] font-semibold tracking-wide text-muted-foreground uppercase @xl/induccion:justify-center"
                    >
                        Paso {{ indice + 1 }}
                        <span
                            v-if="paso.estado === 'actual'"
                            class="rounded-full bg-salvia px-2 py-px text-[0.65rem] leading-4 tracking-normal text-primary normal-case"
                            >Aquí vas</span
                        >
                    </p>
                    <p
                        class="text-sm leading-snug font-semibold"
                        :class="TITULO[paso.estado]"
                    >
                        {{ paso.titulo }}
                    </p>
                    <p
                        v-if="paso.detalle"
                        class="mt-0.5 text-xs text-pretty text-muted-foreground"
                    >
                        {{ paso.detalle }}
                    </p>
                </div>
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
            <Link v-else :href="accion.href" :class="claseAccion">
                {{ accion.etiqueta }} <ArrowRight class="size-4" />
            </Link>
        </template>
    </section>
</template>
