<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlarmClock, LoaderCircle, Send, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import AvatarPersona from '@/components/evaluaciones-tecnicas/AvatarPersona.vue';
import FormularioTecnico from '@/components/formularios-evaluacion/FormularioTecnico.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { textoPlazo } from '@/lib/evaluacionesTecnicas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { responder } from '@/routes/evaluaciones-tecnicas';
import type {
    ContenidoFormularioTecnico,
    DetalleEvaluacionTecnica,
    RespuestasTecnicas,
} from '@/types';

/**
 * Contestar una evaluación técnica: el colaborador su examen («Mi
 * espacio») o el jefe directo la de su equipo. Mismo formulario que la
 * vista previa del editor (FormularioTecnico).
 */
const props = defineProps<{
    evaluacion: DetalleEvaluacionTecnica;
    contenido: ContenidoFormularioTecnico;
    esPropia: boolean;
}>();

defineOptions({
    layout: (p: { evaluacion?: DetalleEvaluacionTecnica }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: p.evaluacion?.nombre ?? 'Evaluación técnica', href: '' },
        ],
    }),
});

const respuestas = ref<RespuestasTecnicas>({});
const comentarios = ref('');
const enviando = ref(false);
const confirmar = ref(false);
const intentoEnviar = ref(false);
const error = ref<string | null>(null);

const faltantes = computed(() =>
    props.contenido.secciones
        .flatMap((s) => s.preguntas)
        .filter((p) => {
            const v = respuestas.value[p.clave];

            return (
                p.obligatoria &&
                (v === null ||
                    v === undefined ||
                    (Array.isArray(v) ? v.length === 0 : String(v).trim() === ''))
            );
        }).length,
);

function intentar(): void {
    intentoEnviar.value = true;

    if (faltantes.value === 0) {
        confirmar.value = true;
    }
}

function enviar(): void {
    enviando.value = true;
    error.value = null;
    router.post(
        responder.url(props.evaluacion.id as number),
        { respuestas: respuestas.value, comentarios: comentarios.value || null },
        {
            preserveScroll: true,
            onError: (e) => {
                error.value = e.respuestas ?? e.evaluacion ?? 'No se pudo enviar. Revisa tus respuestas.';
                confirmar.value = false;
            },
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <Head :title="evaluacion.nombre" />

    <div class="pagina-ancha flex flex-col gap-5 pb-16">
        <!-- A quién se evalúa -->
        <div
            class="flex entrada-suave flex-wrap items-center gap-4 rounded-2xl border border-border bg-card px-5 py-4 shadow-xs"
        >
            <AvatarPersona :nombre="evaluacion.colaborador?.nombre ?? ''" :foto-url="evaluacion.colaborador?.foto_url" />
            <div class="min-w-0 flex-1">
                <p class="text-xs text-muted-foreground">
                    {{ esPropia ? 'Tu evaluación técnica' : 'Evalúas a' }}
                </p>
                <p class="font-semibold">
                    {{ esPropia ? evaluacion.nombre : evaluacion.colaborador?.nombre }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ esPropia ? `Día ${evaluacion.dias} desde tu ingreso` : `${evaluacion.nombre} · día ${evaluacion.dias} · ${evaluacion.puesto ?? ''}` }}
                </p>
            </div>
            <span
                :class="
                    cn(
                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium',
                        evaluacion.estado === 'vencida' ? 'bg-danger-soft text-destructive' : 'bg-warning-soft text-warning',
                    )
                "
            >
                <AlarmClock class="size-3.5" /> {{ textoPlazo(evaluacion.dias_restantes) }}
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-salvia px-3 py-1 text-xs font-medium text-primary">
                <ShieldCheck class="size-3.5" /> Aprueba con {{ evaluacion.calificacion_minima }}
            </span>
        </div>

        <div class="w-full">
            <FormularioTecnico
                v-model:respuestas="respuestas"
                :contenido="contenido"
                :deshabilitado="enviando"
                :resaltar-faltantes="intentoEnviar"
            >
                <template #acciones>
                    <section class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 shadow-xs">
                        <label class="flex flex-col gap-1.5 text-sm font-medium">
                            Comentarios (opcional)
                            <Textarea
                                v-model="comentarios"
                                rows="3"
                                maxlength="3000"
                                :placeholder="esPropia ? '¿Algo que quieras comentar?' : 'Observaciones para Recursos Humanos'"
                            />
                        </label>
                        <p v-if="error" class="entrada-suave rounded-xl bg-danger-soft px-3 py-2 text-sm text-destructive">
                            {{ error }}
                        </p>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-muted-foreground">
                                <template v-if="intentoEnviar && faltantes">
                                    Te faltan {{ faltantes }} {{ faltantes === 1 ? 'pregunta obligatoria' : 'preguntas obligatorias' }}.
                                </template>
                                <template v-else>Una vez enviada ya no se puede cambiar.</template>
                            </p>
                            <Button size="lg" :disabled="enviando" @click="intentar">
                                <LoaderCircle v-if="enviando" class="size-4 animate-spin" />
                                <Send v-else class="size-4" />
                                Enviar evaluación
                            </Button>
                        </div>
                    </section>
                </template>
            </FormularioTecnico>
        </div>
    </div>

    <Dialog v-model:open="confirmar">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>¿Enviar «{{ evaluacion.nombre }}»?</DialogTitle>
                <DialogDescription>
                    Se califica al instante contra la mínima de {{ evaluacion.calificacion_minima }}. Después de enviarla ya no se puede modificar.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="confirmar = false">Revisar otra vez</Button>
                <Button :disabled="enviando" @click="enviar">
                    <LoaderCircle v-if="enviando" class="size-4 animate-spin" /> Enviar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
