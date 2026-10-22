<script setup lang="ts">
import {
    BadgeCheck,
    CalendarDays,
    CircleCheck,
    CircleX,
    ClipboardCheck,
    Download,
    MessageSquareText,
    Printer,
    ShieldAlert,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';
import type { FilaEvaluacionPrueba } from '@/types';

/**
 * «Ver respuestas» de una evaluación de periodo de prueba (FO-RH-EPP-001):
 * lo que contestó el jefe inmediato TAL COMO SE GUARDÓ (criterios 1–4,
 * preguntas filtro Sí/No, total contra el máximo, resultado y sus motivos),
 * sus comentarios y la decisión de RH. Desde aquí se abre la vista previa
 * del PDF para firma (nunca se imprime sin verlo antes).
 */
const props = defineProps<{
    open: boolean;
    evaluacion: FilaEvaluacionPrueba | null;
    cargandoPdf?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
    'ver-pdf': [evaluacion: FilaEvaluacionPrueba];
}>();

const r = computed(() => props.evaluacion?.respuestas ?? null);

/** Escala oficial 4 Muy bueno · 3 Bueno · 2 Regular · 1 Deficiente. */
const NIVEL: Record<number, { etiqueta: string; clase: string }> = {
    4: { etiqueta: 'Muy bueno', clase: 'bg-success-soft text-success' },
    3: { etiqueta: 'Bueno', clase: 'bg-salvia text-primary' },
    2: { etiqueta: 'Regular', clase: 'bg-warning-soft text-warning' },
    1: { etiqueta: 'Deficiente', clase: 'bg-danger-soft text-destructive' },
};

const porcentaje = computed(() => {
    if (!r.value?.total || !r.value.total_maximo) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((r.value.total / r.value.total_maximo) * 100),
    );
});

const resultado = computed<{
    etiqueta: string;
    tono: Tono;
    icono: typeof CircleCheck;
}>(() =>
    r.value?.recomienda_renovar
        ? {
              etiqueta: 'Sí se renueva',
              tono: 'exito',
              icono: CircleCheck,
          }
        : r.value?.recomienda_renovar === false
          ? {
                etiqueta: 'No se renueva',
                tono: 'peligro',
                icono: CircleX,
            }
          : {
                etiqueta: 'Sin recomendación',
                tono: 'neutro',
                icono: ClipboardCheck,
            },
);

const filtrosEnSi = computed(
    () => r.value?.filtros.filter((f) => f.respuesta).length ?? 0,
);

function numero(valor: number | null | undefined): string {
    if (valor === null || valor === undefined) {
        return '—';
    }

    return Number.isInteger(valor) ? String(valor) : valor.toFixed(1);
}

function fecha(valor: string | null | undefined): string {
    if (!valor) {
        return '—';
    }

    const fechaReal = valor.length === 10 ? `${valor}T12:00:00` : valor;

    return new Date(fechaReal).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function nivel(calificacion: number | null) {
    return (
        NIVEL[Number(calificacion)] ?? {
            etiqueta: '—',
            clase: 'bg-muted text-muted-foreground',
        }
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent
            class="max-h-[92svh] gap-0 overflow-y-auto p-0 sm:max-w-4xl"
        >
            <template v-if="evaluacion && r">
                <!-- Encabezado -->
                <header
                    class="flex flex-col gap-4 border-b border-border/60 bg-crema/60 p-5 pr-12 sm:flex-row sm:items-center"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <ColaboradorAvatar
                            :nombre="evaluacion.colaborador.nombre"
                            tamano="lg"
                        />
                        <div class="min-w-0">
                            <DialogTitle
                                class="truncate text-lg font-semibold"
                                >{{
                                    evaluacion.colaborador.nombre
                                }}</DialogTitle
                            >
                            <DialogDescription
                                class="truncate text-sm text-muted-foreground"
                            >
                                {{ evaluacion.puesto ?? 'Sin puesto' }} ·
                                {{ evaluacion.sucursal ?? 'Sin sucursal' }}
                                · FO-RH-EPP-001
                            </DialogDescription>
                        </div>
                    </div>
                    <div
                        class="flex items-center gap-4 rounded-2xl border border-border/60 bg-card px-4 py-3"
                    >
                        <div class="text-center">
                            <p
                                class="text-3xl leading-none font-bold text-primary tabular-nums"
                            >
                                {{ numero(r.total) }}
                                <span
                                    v-if="r.total_maximo"
                                    class="text-base font-medium text-muted-foreground"
                                    >/ {{ r.total_maximo }}</span
                                >
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                puntos
                            </p>
                        </div>
                        <ChipEstado
                            :etiqueta="resultado.etiqueta"
                            :tono="resultado.tono"
                            :icono="resultado.icono"
                            tamano="md"
                        />
                    </div>
                </header>

                <div class="flex flex-col gap-6 p-5">
                    <!-- Resumen del resultado -->
                    <section
                        v-if="r.es_oficial"
                        class="flex flex-col gap-2"
                        aria-label="Total contra el mínimo"
                    >
                        <div
                            class="relative h-3 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-valuenow="r.total ?? 0"
                            aria-valuemin="0"
                            :aria-valuemax="r.total_maximo ?? 0"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :class="
                                    r.recomienda_renovar
                                        ? 'bg-success'
                                        : 'bg-destructive/70'
                                "
                                :style="{ width: `${porcentaje}%` }"
                            />
                            <span
                                v-if="r.total_minimo_renovar && r.total_maximo"
                                class="absolute inset-y-0 w-0.5 bg-foreground/50"
                                :style="{
                                    left: `${(r.total_minimo_renovar / r.total_maximo) * 100}%`,
                                }"
                                :title="`Mínimo para renovar: ${numero(r.total_minimo_renovar)}`"
                            />
                        </div>
                        <p
                            class="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground"
                        >
                            <span
                                >Mínimo para renovar:
                                <strong class="text-foreground">{{
                                    numero(r.total_minimo_renovar)
                                }}</strong>
                                puntos, sin ningún 1 y sin ningún «Sí» en las
                                preguntas filtro.</span
                            >
                        </p>
                        <ul
                            v-if="r.motivos_no_renueva.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <li
                                v-for="motivo in r.motivos_no_renueva"
                                :key="motivo"
                            >
                                <ChipEstado
                                    :etiqueta="motivo"
                                    tono="peligro"
                                    :icono="ShieldAlert"
                                />
                            </li>
                        </ul>
                    </section>

                    <!-- Paso 1: criterios -->
                    <section class="flex flex-col gap-3">
                        <h3
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <span
                                class="rounded-lg bg-primary px-2 py-0.5 text-xs text-primary-foreground"
                                >Paso 1</span
                            >
                            {{
                                r.es_oficial
                                    ? 'Desempeño (4 Muy bueno · 3 Bueno · 2 Regular · 1 Deficiente)'
                                    : 'Criterios (formato anterior, 0 a 10)'
                            }}
                        </h3>
                        <ul class="grid gap-2 md:grid-cols-2">
                            <li
                                v-for="(criterio, i) in r.criterios"
                                :key="criterio.clave ?? i"
                                class="flex flex-col gap-2 rounded-xl border border-border/60 bg-card p-3"
                                :class="
                                    r.es_oficial &&
                                    Number(criterio.calificacion) === 1 &&
                                    'border-destructive/40 bg-danger-soft/40'
                                "
                            >
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <p class="text-sm font-medium">
                                        <span class="text-muted-foreground"
                                            >{{ i + 1 }}.</span
                                        >
                                        {{ criterio.etiqueta }}
                                    </p>
                                    <span
                                        v-if="r.es_oficial"
                                        class="shrink-0 rounded-lg px-2 py-0.5 text-xs font-semibold"
                                        :class="
                                            nivel(criterio.calificacion).clase
                                        "
                                        >{{
                                            nivel(criterio.calificacion)
                                                .etiqueta
                                        }}</span
                                    >
                                    <span
                                        v-else
                                        class="shrink-0 text-lg font-bold text-primary tabular-nums"
                                        >{{
                                            numero(criterio.calificacion)
                                        }}</span
                                    >
                                </div>
                                <div
                                    v-if="r.es_oficial"
                                    class="flex gap-1"
                                    :aria-label="`Calificación ${criterio.calificacion} de ${r.escala_max}`"
                                    role="img"
                                >
                                    <span
                                        v-for="punto in r.escala_max"
                                        :key="punto"
                                        :class="
                                            cn(
                                                'flex h-7 flex-1 items-center justify-center rounded-md text-xs font-semibold tabular-nums',
                                                punto <=
                                                    Number(
                                                        criterio.calificacion,
                                                    )
                                                    ? Number(
                                                          criterio.calificacion,
                                                      ) === 1
                                                        ? 'bg-destructive text-primary-foreground'
                                                        : Number(
                                                                criterio.calificacion,
                                                            ) === 2
                                                          ? 'bg-warning text-primary-foreground'
                                                          : 'bg-primary text-primary-foreground'
                                                    : 'bg-muted text-muted-foreground/60',
                                            )
                                        "
                                        >{{ punto }}</span
                                    >
                                </div>
                                <p
                                    v-if="criterio.comentario"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ criterio.comentario }}
                                </p>
                            </li>
                        </ul>
                    </section>

                    <!-- Paso 2: preguntas filtro -->
                    <section
                        v-if="r.filtros.length"
                        class="flex flex-col gap-3"
                    >
                        <h3
                            class="flex flex-wrap items-center gap-2 text-sm font-semibold"
                        >
                            <span
                                class="rounded-lg bg-primary px-2 py-0.5 text-xs text-primary-foreground"
                                >Paso 2</span
                            >
                            Preguntas filtro
                            <span
                                class="text-xs font-normal text-muted-foreground"
                                >(un solo «Sí» = no se renueva)</span
                            >
                        </h3>
                        <ul
                            class="divide-y divide-border/60 overflow-hidden rounded-xl border border-border/60"
                        >
                            <li
                                v-for="(filtro, i) in r.filtros"
                                :key="filtro.clave ?? i"
                                class="flex items-center justify-between gap-3 px-3 py-2.5 text-sm"
                                :class="filtro.respuesta && 'bg-danger-soft/50'"
                            >
                                <span>{{ filtro.etiqueta }}</span>
                                <ChipEstado
                                    :etiqueta="filtro.respuesta ? 'Sí' : 'No'"
                                    :tono="
                                        filtro.respuesta ? 'peligro' : 'exito'
                                    "
                                    :icono="
                                        filtro.respuesta ? CircleX : CircleCheck
                                    "
                                />
                            </li>
                        </ul>
                        <p
                            v-if="filtrosEnSi === 0"
                            class="text-xs text-muted-foreground"
                        >
                            Ninguna pregunta filtro en «Sí».
                        </p>
                    </section>

                    <!-- Comentarios del jefe -->
                    <section class="flex flex-col gap-2">
                        <h3
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <MessageSquareText class="size-4 text-primary" />
                            Comentarios del jefe inmediato
                        </h3>
                        <p
                            class="rounded-xl border border-border/60 bg-[var(--mrl-fondo)] p-3 text-sm whitespace-pre-line"
                            :class="
                                !r.observaciones &&
                                'text-muted-foreground italic'
                            "
                        >
                            {{ r.observaciones || 'Sin comentarios.' }}
                        </p>
                    </section>

                    <!-- Quién y cuándo + decisión RH -->
                    <section class="grid gap-3 sm:grid-cols-2">
                        <div
                            class="flex flex-col gap-2 rounded-xl border border-border/60 p-3 text-sm"
                        >
                            <p class="flex items-center gap-2">
                                <UserRound
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="text-muted-foreground"
                                    >Evaluó:</span
                                >
                                <strong class="truncate">{{
                                    r.evaluador ?? r.capturada_por ?? '—'
                                }}</strong>
                            </p>
                            <p class="flex items-center gap-2">
                                <CalendarDays
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="text-muted-foreground"
                                    >Fecha de evaluación:</span
                                >
                                <strong>{{ fecha(r.fecha_evaluacion) }}</strong>
                            </p>
                            <p class="flex items-center gap-2">
                                <CalendarDays
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="text-muted-foreground"
                                    >Fin del contrato:</span
                                >
                                <strong>{{ fecha(r.fecha_limite) }}</strong>
                            </p>
                            <p
                                v-if="
                                    r.capturada_por &&
                                    r.capturada_por !== r.evaluador
                                "
                                class="text-xs text-muted-foreground"
                            >
                                Capturó en PEOPLE: {{ r.capturada_por }}
                            </p>
                        </div>
                        <div
                            class="flex flex-col gap-2 rounded-xl border p-3 text-sm"
                            :class="
                                r.decision.renovar === null
                                    ? 'border-dashed border-border bg-crema/40'
                                    : r.decision.renovar
                                      ? 'border-success/40 bg-success-soft/50'
                                      : 'border-destructive/40 bg-danger-soft/50'
                            "
                        >
                            <p class="flex items-center gap-2 font-semibold">
                                <BadgeCheck class="size-4 shrink-0" />
                                Decisión de Recursos Humanos
                            </p>
                            <template v-if="r.decision.renovar !== null">
                                <p>
                                    {{
                                        r.decision.renovar
                                            ? 'Se renueva: contrato por tiempo indeterminado.'
                                            : 'No se renueva: termina al vencer el contrato.'
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ r.decision.autorizada_por ?? 'RH' }} ·
                                    {{ fecha(r.decision.autorizada_en) }}
                                </p>
                                <p
                                    v-if="r.decision.comentario"
                                    class="text-xs whitespace-pre-line"
                                >
                                    {{ r.decision.comentario }}
                                </p>
                            </template>
                            <p v-else class="text-muted-foreground">
                                Pendiente: RH aún no decide.
                            </p>
                        </div>
                    </section>
                </div>

                <footer
                    class="sticky bottom-0 flex flex-col-reverse gap-2 border-t border-border/60 bg-card p-4 sm:flex-row sm:justify-end"
                >
                    <Button
                        variant="outline"
                        @click="emit('update:open', false)"
                        >Cerrar</Button
                    >
                    <Button
                        v-if="evaluacion.pdf_url"
                        variant="outline"
                        as-child
                    >
                        <a :href="`${evaluacion.pdf_url}?descargar=1`">
                            <Download class="size-4" />
                            Descargar PDF
                        </a>
                    </Button>
                    <Button
                        v-if="evaluacion.pdf_url"
                        :disabled="cargandoPdf"
                        @click="emit('ver-pdf', evaluacion)"
                    >
                        <Printer class="size-4" />
                        Imprimir PDF para firma
                    </Button>
                </footer>
            </template>
        </DialogContent>
    </Dialog>
</template>
