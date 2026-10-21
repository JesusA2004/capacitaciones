<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { MessageSquareWarning, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import DatosPersonalesFormulario from '@/components/Solicitudes/DatosPersonalesFormulario.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { formatearFechaHora } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { responder } from '@/routes/solicitudes';
import type {
    CorreccionItem,
    DatoPersonalCaptura,
    DatoPropuesto,
} from '@/types';

/**
 * Seguimiento de una corrección para el colaborador: estado en palabras
 * simples, qué pidió Recursos Humanos y, si pidió más información, el
 * formulario para responder (y corregir lo propuesto). Mismo
 * CorreccionesService::responder() que la app.
 */
const props = defineProps<{
    solicitudId: number;
    correccion: CorreccionItem;
    datosPropuestos: DatoPropuesto[] | null;
    datosPersonales: DatoPersonalCaptura[];
}>();

const { mostrarExito } = useAlertas();

/** Solo los datos que ya había propuesto: puede corregirlos al responder. */
const camposCorregibles = computed(() => {
    const propuestos = new Set(
        (props.datosPropuestos ?? []).map((d) => d.campo),
    );

    return props.datosPersonales.filter((c) => propuestos.has(c.campo));
});

const corregirDatos = ref(false);
const form = useForm({
    mensaje: '',
    datos: {} as Record<string, string>,
});

const errores = computed(() => form.errors as Partial<Record<string, string>>);

function enviar() {
    form.transform((d) => ({
        mensaje: d.mensaje,
        ...(corregirDatos.value && Object.keys(d.datos).length
            ? { datos: d.datos }
            : {}),
    })).post(responder.url(props.solicitudId), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            corregirDatos.value = false;
            mostrarExito('Respuesta enviada a Recursos Humanos.');
        },
    });
}

const TONO: Record<string, string> = {
    requiere_informacion: 'border-warning/50 bg-warning-soft/50',
    rechazada: 'border-destructive/30 bg-destructive/5',
    aprobada: 'border-success/40 bg-success-soft/60',
};
</script>

<template>
    <section
        :class="
            cn(
                'flex flex-col gap-4 rounded-2xl border p-4 sm:p-5',
                TONO[correccion.estado] ?? 'border-border/60 bg-card',
            )
        "
        aria-labelledby="correccion-estado"
    >
        <div>
            <p class="text-xs text-muted-foreground">Estado de tu corrección</p>
            <h2 id="correccion-estado" class="text-lg font-semibold">
                {{ correccion.estado_etiqueta }}
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ correccion.estado_descripcion }}
            </p>
            <p
                v-if="correccion.resuelta_por && correccion.resuelta_en"
                class="mt-1 text-xs text-muted-foreground"
            >
                Resolvió {{ correccion.resuelta_por }} el
                {{ formatearFechaHora(correccion.resuelta_en) }}
            </p>
        </div>

        <div
            v-if="correccion.comentario_rh"
            class="flex items-start gap-2 rounded-xl bg-card p-3 text-sm"
        >
            <MessageSquareWarning
                class="mt-0.5 size-4 shrink-0 text-[var(--brand-primary)]"
            />
            <p class="min-w-0 break-words">
                <span class="font-medium">Recursos Humanos:</span>
                {{ correccion.comentario_rh }}
            </p>
        </div>

        <!-- Línea de tiempo simple -->
        <ol class="flex flex-col gap-3 border-s border-border ps-4">
            <li
                v-for="(paso, i) in correccion.historial"
                :key="i"
                class="relative"
            >
                <span
                    :class="
                        cn(
                            'absolute -start-[21px] mt-1.5 size-2.5 rounded-full',
                            paso.es_rh
                                ? 'bg-[var(--brand-primary)]'
                                : 'bg-muted-foreground/60',
                        )
                    "
                />
                <p class="text-sm font-medium">
                    {{ paso.etiqueta }}
                    <span
                        v-if="paso.por"
                        class="font-normal text-muted-foreground"
                        >— {{ paso.por }}</span
                    >
                </p>
                <p
                    v-if="paso.comentario"
                    class="text-sm break-words text-muted-foreground"
                >
                    {{ paso.comentario }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ formatearFechaHora(paso.en) }}
                    <template v-if="paso.origen_etiqueta">
                        · {{ paso.origen_etiqueta }}</template
                    >
                </p>
            </li>
        </ol>

        <form
            v-if="correccion.puede_responder"
            class="flex flex-col gap-3 rounded-xl border border-border/60 bg-card p-4"
            @submit.prevent="enviar"
        >
            <div class="grid gap-1.5">
                <Label for="respuesta-rh"
                    >Tu respuesta para Recursos Humanos</Label
                >
                <Textarea
                    id="respuesta-rh"
                    v-model="form.mensaje"
                    rows="3"
                    maxlength="1000"
                    placeholder="Explica o aclara lo que te pidieron. Si hace falta un documento, adjúntalo abajo."
                />
                <p v-if="form.errors.mensaje" class="text-sm text-destructive">
                    {{ form.errors.mensaje }}
                </p>
            </div>

            <template v-if="camposCorregibles.length">
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="corregirDatos"
                        type="checkbox"
                        class="size-4 accent-[var(--brand-primary)]"
                    />
                    También quiero corregir los datos que propuse
                </label>
                <DatosPersonalesFormulario
                    v-if="corregirDatos"
                    v-model="form.datos"
                    :campos="camposCorregibles"
                    :errores="errores"
                />
                <p v-if="form.errors.datos" class="text-sm text-destructive">
                    {{ form.errors.datos }}
                </p>
            </template>

            <div>
                <Button
                    type="submit"
                    class="w-full sm:w-auto"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" />
                    <Send v-else class="size-4" />
                    Responder y reenviar a RH
                </Button>
            </div>
        </form>
    </section>
</template>
