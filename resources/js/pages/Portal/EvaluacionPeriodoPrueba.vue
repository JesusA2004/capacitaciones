<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    Hourglass,
    Info,
    RotateCcw,
    Send,
} from '@lucide/vue';
import { computed } from 'vue';
import FormularioPeriodoPrueba from '@/components/formularios-evaluacion/FormularioPeriodoPrueba.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { miProceso } from '@/routes/portal';
import { contestar } from '@/routes/portal/evaluacion-periodo-prueba';
import type { MiEvaluacionPeriodoPrueba } from '@/types';

/**
 * «Mi evaluación de periodo de prueba» (Mi espacio): la AUTOEVALUACIÓN
 * FO-RH-EPP-001 que RH me asignó. Solo yo la contesto (Policy «contestar»);
 * una vez enviada queda en solo lectura, salvo que RH me la devuelva para
 * corregirla. Mismo componente que la vista previa del editor de
 * formularios (FormularioPeriodoPrueba).
 */
const props = defineProps<{ evaluacion: MiEvaluacionPeriodoPrueba | null }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis pendientes', href: miProceso() },
            { title: 'Mi evaluación de periodo de prueba', href: '' },
        ],
    },
});

const form = useForm<{
    criterios: { clave: string; calificacion: number }[];
    filtros: { clave: string; respuesta: boolean }[];
    observaciones: string;
}>({
    // Devuelta: se precarga lo que ya contestó para que solo corrija.
    criterios: props.evaluacion?.criterios ?? [],
    filtros: props.evaluacion?.filtros ?? [],
    observaciones: props.evaluacion?.observaciones ?? '',
});

const totalPreguntas = computed(
    () =>
        (props.evaluacion?.formulario.criterios.length ?? 0) +
        (props.evaluacion?.formulario.filtros.length ?? 0),
);
const contestadas = computed(() => form.criterios.length + form.filtros.length);
const completa = computed(
    () => totalPreguntas.value > 0 && contestadas.value >= totalPreguntas.value,
);
const errores = computed(() => form.errors as Record<string, string>);

function fecha(valor: string | null): string {
    return valor
        ? new Date(
              valor.length <= 10 ? `${valor}T12:00:00` : valor,
          ).toLocaleDateString('es-MX', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : '';
}

function enviar() {
    if (!props.evaluacion?.puede_contestar) {
        return;
    }

    form.post(contestar.url(props.evaluacion.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Mi evaluación de periodo de prueba" />

    <div class="pagina-ancha flex flex-col gap-4 pb-16">
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                Mi evaluación de periodo de prueba
            </h1>
            <p class="text-sm text-muted-foreground">
                Tu autoevaluación de capacitación inicial. Contéstala tú
                mismo(a), con honestidad: Recursos Humanos revisa tus respuestas
                y decide la renovación de tu contrato.
            </p>
        </header>

        <!-- Sin evaluación asignada -->
        <section
            v-if="!evaluacion"
            class="flex flex-col items-start gap-3 rounded-3xl border border-border/60 bg-card p-6"
        >
            <ClipboardCheck class="size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">
                Todavía no tienes una evaluación de periodo de prueba. Recursos
                Humanos te la asignará antes de que termine tu contrato de
                capacitación inicial y te avisaremos.
            </p>
            <Link
                :href="miProceso()"
                class="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
            >
                Ir a Mis pendientes <ArrowRight class="size-4" />
            </Link>
        </section>

        <template v-else>
            <!-- Estado -->
            <section
                role="status"
                class="flex flex-col gap-3 rounded-2xl border p-4 sm:flex-row sm:items-center"
                :class="
                    evaluacion.estado === 'devuelta'
                        ? 'border-warning/40 bg-warning-soft'
                        : evaluacion.puede_contestar
                          ? 'border-primary/30 bg-salvia/60'
                          : 'border-success/30 bg-success-soft'
                "
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-full bg-card"
                >
                    <RotateCcw
                        v-if="evaluacion.estado === 'devuelta'"
                        class="size-5 text-warning"
                    />
                    <Hourglass
                        v-else-if="evaluacion.puede_contestar"
                        class="size-5 text-primary"
                    />
                    <CheckCircle2 v-else class="size-5 text-success" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">
                        {{ evaluacion.estado_etiqueta }}
                    </p>
                    <p v-if="evaluacion.estado === 'devuelta'" class="text-sm">
                        Recursos Humanos te pide corregirla:
                        <strong>{{ evaluacion.motivo_devolucion }}</strong>
                    </p>
                    <p
                        v-else-if="evaluacion.puede_contestar"
                        class="text-sm text-muted-foreground"
                    >
                        Califica cada punto, contesta las preguntas filtro y
                        envíala. Al enviarla ya no podrás cambiarla.
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        La enviaste el {{ fecha(evaluacion.contestada_en) }}.
                        Aquí puedes consultar tus respuestas.
                    </p>
                </div>
                <p
                    v-if="evaluacion.puede_contestar && evaluacion.fecha_limite"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-card px-3 py-1.5 text-xs font-medium"
                >
                    <CalendarClock class="size-3.5" />
                    Contéstala antes del {{ fecha(evaluacion.fecha_limite) }}
                </p>
            </section>

            <form class="flex flex-col gap-4" @submit.prevent="enviar">
                <FormularioPeriodoPrueba
                    v-model:criterios="form.criterios"
                    v-model:filtros="form.filtros"
                    v-model:observaciones="form.observaciones"
                    :contenido="evaluacion.formulario"
                    :errores="errores"
                    :deshabilitado="!evaluacion.puede_contestar"
                />

                <div
                    v-if="evaluacion.puede_contestar"
                    class="sticky bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-10 flex flex-col gap-3 rounded-2xl border border-border/60 bg-card/95 p-3 shadow-md backdrop-blur sm:bottom-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex min-w-0 flex-col gap-1.5 sm:flex-1">
                        <p class="text-sm font-medium tabular-nums">
                            Contestaste {{ contestadas }} de
                            {{ totalPreguntas }}
                        </p>
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-muted sm:max-w-xs"
                        >
                            <div
                                class="h-full rounded-full bg-primary transition-all"
                                :style="{
                                    width: `${totalPreguntas ? Math.min(100, (contestadas / totalPreguntas) * 100) : 0}%`,
                                }"
                            />
                        </div>
                        <InputError :message="errores.estado" />
                    </div>
                    <Button
                        type="submit"
                        size="lg"
                        class="h-12 w-full sm:h-11 sm:w-auto"
                        :disabled="form.processing || !completa"
                        :title="
                            completa
                                ? undefined
                                : 'Contesta todos los puntos para poder enviarla'
                        "
                    >
                        <Send class="size-4" />
                        {{
                            form.processing
                                ? 'Enviando…'
                                : evaluacion.estado === 'devuelta'
                                  ? 'Enviar corrección'
                                  : 'Enviar mi evaluación'
                        }}
                    </Button>
                </div>

                <p
                    v-else
                    class="flex items-start gap-2 rounded-2xl bg-crema/60 p-3 text-sm text-muted-foreground"
                >
                    <Info class="mt-0.5 size-4 shrink-0" />
                    Si algo no es correcto, habla con Recursos Humanos: solo
                    ellos pueden devolverte la evaluación para corregirla.
                </p>
            </form>
        </template>
    </div>
</template>
