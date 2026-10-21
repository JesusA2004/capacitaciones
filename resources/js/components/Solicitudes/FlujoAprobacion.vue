<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Check,
    Circle,
    Clock,
    Info,
    Lock,
    Minus,
    Route,
    ThumbsDown,
    ThumbsUp,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatearFechaHora } from '@/lib/fechas';
import { vistoBueno } from '@/routes/rh/solicitudes';
import type { FlujoAprobacion, PasoFlujoAprobacion } from '@/types';

/**
 * Línea de tiempo de aprobación (motor central del backend,
 * AprobacionOrganizacionalService::flujo): «✓ Solicitud creada →
 * ○ Jefe directo — Pendiente → ○ RH — Esperando paso anterior». Solo pinta
 * lo que manda el backend; si quien la ve puede dar su visto bueno, aquí
 * mismo aparecen los botones (el backend vuelve a validar).
 */
const props = withDefaults(
    defineProps<{
        flujo: FlujoAprobacion;
        solicitudId: number;
        /** Muestra los botones de visto bueno (bandeja de RH/jefes). */
        conAcciones?: boolean;
    }>(),
    { conAcciones: false },
);

const puedeVistoBueno = computed(
    () =>
        props.conAcciones &&
        props.flujo.accion_usuario_actual === 'visto_bueno',
);

/** Motivo general para quien mira, si ningún paso ya lo dice. */
const motivoGeneral = computed(() => {
    const motivo = props.flujo.motivo_no_puede;

    return motivo &&
        !props.flujo.pasos.some((p) => p.motivo_no_puede === motivo)
        ? motivo
        : null;
});

const estilos: Record<
    PasoFlujoAprobacion['estado'] | 'bloqueado',
    { circulo: string; titulo: string; etiqueta: string }
> = {
    aprobado: {
        circulo: 'bg-[var(--mrl-success)] text-white',
        titulo: 'text-[var(--mrl-texto)]',
        etiqueta: 'bg-success-soft text-[var(--mrl-success)]',
    },
    pendiente: {
        circulo:
            'bg-[var(--mrl-primary)] text-white ring-4 ring-[var(--mrl-primary)]/15',
        titulo: 'text-[var(--mrl-primary)] font-semibold',
        etiqueta: 'bg-[var(--mrl-primary)]/10 text-[var(--mrl-primary)]',
    },
    esperando: {
        circulo:
            'border-2 border-dashed border-[var(--mrl-borde)] bg-[var(--mrl-surface)] text-[var(--mrl-texto-suave)]',
        titulo: 'text-[var(--mrl-texto-suave)]',
        etiqueta: 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
    },
    no_aplica: {
        circulo:
            'border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
        titulo: 'text-[var(--mrl-texto-suave)] line-through decoration-1',
        etiqueta: 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
    },
    rechazado: {
        circulo: 'bg-[var(--mrl-danger)] text-white',
        titulo: 'text-[var(--mrl-danger)] font-semibold',
        etiqueta: 'bg-[var(--mrl-danger)]/10 text-[var(--mrl-danger)]',
    },
    bloqueado: {
        circulo:
            'bg-[var(--mrl-gold)] text-white ring-4 ring-[var(--mrl-gold)]/20',
        titulo: 'text-[var(--mrl-gold-dark)] font-semibold',
        etiqueta: 'bg-[var(--mrl-gold)]/15 text-[var(--mrl-gold-dark)]',
    },
};

function estiloDe(paso: PasoFlujoAprobacion) {
    return estilos[paso.bloqueado ? 'bloqueado' : paso.estado];
}

/** «Jefe directo — Alberto Carlos Bueno» */
function quien(paso: PasoFlujoAprobacion): string | null {
    if (paso.clave === 'creada') {
        return paso.responsable?.nombre ?? null;
    }

    if (paso.decidio) {
        return paso.decidio.nombre;
    }

    if (paso.responsables.length > 1) {
        return paso.responsables.map((r) => r.nombre).join(' o ');
    }

    return paso.responsable?.nombre ?? null;
}

/** «Aprobado 20/10/2026 14:30», «Pendiente», «Esperando paso anterior». */
function detalle(paso: PasoFlujoAprobacion): string {
    if (paso.fecha && ['aprobado', 'rechazado'].includes(paso.estado)) {
        const verbo =
            paso.clave === 'creada'
                ? 'Enviada'
                : paso.estado === 'aprobado'
                  ? 'Aprobado'
                  : 'Rechazado';

        return `${verbo} ${formatearFechaHora(paso.fecha)}`;
    }

    return paso.estado_etiqueta;
}

const decidiendo = ref<boolean | null>(null);
const form = useForm<{ aprobado: boolean; comentario: string }>({
    aprobado: true,
    comentario: '',
});

function enviar(aprobado: boolean) {
    form.aprobado = aprobado;
    form.post(vistoBueno.url(props.solicitudId), {
        preserveScroll: true,
        onSuccess: () => (decidiendo.value = null),
    });
}
</script>

<template>
    <section
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-4 sm:p-5"
        aria-label="Flujo de aprobación"
    >
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <Route class="size-4 text-[var(--mrl-primary)]" />
                Flujo de aprobación
            </h2>
            <span
                v-if="flujo.pendiente_de"
                class="rounded-full px-3 py-1 text-xs font-medium"
                :class="
                    flujo.bloqueado
                        ? 'bg-[var(--mrl-gold)]/15 text-[var(--mrl-gold-dark)]'
                        : 'bg-[var(--mrl-primary)]/10 text-[var(--mrl-primary)]'
                "
            >
                {{ flujo.pendiente_de.texto }}
            </span>
        </div>

        <ol class="flex flex-col">
            <li
                v-for="(paso, i) in flujo.pasos"
                :key="paso.clave"
                class="relative flex gap-3 pb-5 last:pb-0"
            >
                <!-- Conector vertical -->
                <span
                    v-if="i < flujo.pasos.length - 1"
                    class="absolute top-9 bottom-0 left-[17px] w-0.5"
                    :class="
                        paso.estado === 'aprobado'
                            ? 'bg-[var(--mrl-success)]'
                            : 'bg-[var(--mrl-borde)]'
                    "
                    aria-hidden="true"
                />
                <span
                    class="relative z-10 flex size-9 shrink-0 items-center justify-center rounded-full transition-all"
                    :class="estiloDe(paso).circulo"
                >
                    <Lock v-if="paso.bloqueado" class="size-4" />
                    <Check
                        v-else-if="paso.estado === 'aprobado'"
                        class="size-4"
                    />
                    <X v-else-if="paso.estado === 'rechazado'" class="size-4" />
                    <Clock
                        v-else-if="paso.estado === 'pendiente'"
                        class="size-4"
                    />
                    <Minus
                        v-else-if="paso.estado === 'no_aplica'"
                        class="size-4"
                    />
                    <Circle v-else class="size-3.5" />
                </span>

                <div class="min-w-0 flex-1 pt-0.5">
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <p class="text-sm" :class="estiloDe(paso).titulo">
                            {{ paso.etiqueta }}
                            <span
                                v-if="quien(paso)"
                                class="font-normal text-[var(--mrl-texto)]"
                            >
                                — {{ quien(paso) }}</span
                            >
                        </p>
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="estiloDe(paso).etiqueta"
                        >
                            {{ detalle(paso) }}
                        </span>
                    </div>
                    <p
                        v-if="paso.comentario"
                        class="mt-1 rounded-lg bg-[var(--mrl-fondo)] px-2.5 py-1.5 text-xs text-[var(--mrl-texto)]"
                    >
                        «{{ paso.comentario }}»
                    </p>
                    <p
                        v-if="
                            paso.motivo_no_puede &&
                            (paso.estado === 'esperando' || paso.bloqueado)
                        "
                        class="mt-1 text-xs text-[var(--mrl-texto-suave)]"
                    >
                        {{ paso.motivo_no_puede }}
                    </p>
                </div>
            </li>
        </ol>

        <!-- Te toca: visto bueno aquí mismo -->
        <div
            v-if="puedeVistoBueno"
            class="mt-4 flex flex-col gap-3 rounded-xl border border-[var(--mrl-primary)]/30 bg-[var(--mrl-primary)]/5 p-4"
        >
            <p class="text-sm font-medium">Te toca dar el visto bueno.</p>
            <div v-if="decidiendo === false" class="grid gap-1.5">
                <Textarea
                    v-model="form.comentario"
                    rows="2"
                    placeholder="¿Por qué no das el visto bueno? (obligatorio)"
                />
                <InputError
                    :message="
                        form.errors.comentario ??
                        (form.errors as Record<string, string>).solicitud
                    "
                />
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <template v-if="decidiendo !== false">
                    <Button
                        size="lg"
                        :disabled="form.processing"
                        class="gap-2"
                        @click="enviar(true)"
                    >
                        <ThumbsUp class="size-4" /> Dar visto bueno
                    </Button>
                    <Button
                        size="lg"
                        variant="outline"
                        :disabled="form.processing"
                        class="gap-2"
                        @click="decidiendo = false"
                    >
                        <ThumbsDown class="size-4" /> No dar visto bueno
                    </Button>
                </template>
                <template v-else>
                    <Button
                        size="lg"
                        variant="destructive"
                        :disabled="form.processing || !form.comentario.trim()"
                        @click="enviar(false)"
                        >Rechazar con este motivo</Button
                    >
                    <Button variant="ghost" @click="decidiendo = null"
                        >Cancelar</Button
                    >
                </template>
            </div>
        </div>

        <p
            v-else-if="conAcciones && motivoGeneral"
            class="mt-4 flex items-start gap-2 rounded-xl bg-[var(--mrl-fondo)] p-3 text-xs text-[var(--mrl-texto)]"
        >
            <Info
                class="mt-0.5 size-3.5 shrink-0 text-[var(--mrl-texto-suave)]"
            />
            {{ motivoGeneral }}
        </p>
    </section>
</template>
