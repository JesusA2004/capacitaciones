<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CircleCheck,
    Eye,
    FileText,
    MessageSquareText,
    Send,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import { enviar } from '@/routes/rh/colaboradores/descriptivo-puesto';
import type { ResumenDescriptivoPuestoRh } from '@/types';

/**
 * «Enviar descriptivo de puesto» (RH) una vez aprobada la evaluación de
 * inducción: el colaborador lo recibe en su app, lo lee y deja su «OK,
 * enterado» con un comentario, que RH ve aquí. Si todavía no se puede,
 * se explica por qué. Toda la regla vive en AcuseDescriptivoPuestoService.
 */
const props = withDefaults(
    defineProps<{
        colaboradorId: number;
        resumen: ResumenDescriptivoPuestoRh;
        compacto?: boolean;
    }>(),
    { compacto: false },
);

const enviando = ref(false);
const acuse = computed(() => props.resumen.acuse);
const pendiente = computed(
    () => acuse.value !== null && acuse.value.estado !== 'enterado',
);

const tonos = {
    enviado: 'bg-crema',
    leido: 'bg-salvia',
    enterado: 'bg-success-soft',
} as const;

function fecha(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('es-MX') : '';
}

// «Ver lo que recibirá»: el PDF oficial del descriptivo vigente en el visor.
// El primer envío pasa SIEMPRE por la vista previa y luego se confirma
// (regla: RH ve tal cual lo que se le presenta al colaborador).
const verVistaPrevia = ref(false);
const porConfirmar = ref(false);
const revisado = ref(false);

function abrirVistaPrevia() {
    revisado.value = true;
    verVistaPrevia.value = true;
}

function alPresionarEnviar() {
    if (!pendiente.value && props.resumen.vista_previa) {
        porConfirmar.value = true;

        if (!revisado.value) {
            abrirVistaPrevia();
        }

        return;
    }

    enviarDescriptivo();
}

function enviarDescriptivo() {
    porConfirmar.value = false;
    enviando.value = true;
    router.post(
        enviar.url(props.colaboradorId),
        {},
        {
            preserveScroll: true,
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <div
        class="flex flex-col gap-2"
        :class="compacto ? 'text-xs' : 'rounded-3xl border bg-card p-5 text-sm'"
    >
        <h2
            v-if="!compacto"
            class="flex items-center gap-2 text-base font-semibold"
        >
            <FileText class="size-5 text-primary" /> Descriptivo de puesto
        </h2>

        <div v-if="acuse" class="flex flex-wrap items-center gap-2">
            <span
                class="flex w-fit items-center gap-1 rounded-full px-2.5 py-0.5 font-medium"
                :class="tonos[acuse.estado]"
            >
                <CircleCheck
                    v-if="acuse.estado === 'enterado'"
                    class="size-3.5"
                />
                <FileText v-else class="size-3.5" />
                Descriptivo: {{ acuse.estado_etiqueta }}
            </span>
            <span class="text-muted-foreground">
                {{ acuse.descriptivo.nombre_puesto }} · v{{
                    acuse.descriptivo.version
                }}
                · enviado {{ fecha(acuse.enviado_en) }} por
                {{ acuse.enviado_por }}
                <template v-if="acuse.leido_en">
                    · leído {{ fecha(acuse.leido_en) }}</template
                >
                <template v-if="acuse.acusado_en">
                    · enterado {{ fecha(acuse.acusado_en) }}</template
                >
            </span>
        </div>

        <p
            v-if="acuse?.comentario"
            class="flex items-start gap-2 rounded-xl border bg-crema px-3 py-2"
        >
            <MessageSquareText
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
            />
            <span>«{{ acuse.comentario }}»</span>
        </p>

        <p
            v-if="!acuse && !resumen.puede_enviar && resumen.motivo"
            class="text-muted-foreground"
        >
            {{ resumen.motivo }}
        </p>
        <p
            v-else-if="!acuse && resumen.puede_enviar && !compacto"
            class="text-muted-foreground"
        >
            Aprobó su inducción. Envíale el descriptivo oficial de su puesto: lo
            leerá en su app y confirmará que está enterado de sus actividades y
            responsabilidades.
        </p>

        <div
            v-if="porConfirmar && resumen.puede_enviar"
            class="flex flex-col gap-2 rounded-xl border bg-crema px-3 py-2 sm:flex-row sm:items-center"
        >
            <span class="flex-1">
                ¿Enviar <strong>{{ resumen.vista_previa?.nombre }}</strong
                >? Es exactamente lo que acabas de revisar.
            </span>
            <div class="flex gap-2">
                <Button size="sm" variant="ghost" @click="porConfirmar = false"
                    >Cancelar</Button
                >
                <Button
                    size="sm"
                    :disabled="enviando"
                    @click="enviarDescriptivo"
                >
                    <Send class="size-4" /> Confirmar envío
                </Button>
            </div>
        </div>

        <div v-else class="flex flex-wrap gap-2">
            <Button
                v-if="resumen.vista_previa"
                :size="compacto ? 'sm' : 'default'"
                variant="outline"
                class="w-fit"
                @click="abrirVistaPrevia"
            >
                <Eye class="size-4" /> Ver lo que recibirá
            </Button>
            <Button
                v-if="resumen.puede_enviar"
                :size="compacto ? 'sm' : 'default'"
                :variant="pendiente ? 'outline' : 'default'"
                class="w-fit"
                :disabled="enviando"
                @click="alPresionarEnviar"
            >
                <Send class="size-4" />
                {{
                    pendiente
                        ? 'Recordarle que lo lea'
                        : 'Enviar descriptivo de puesto'
                }}
            </Button>
        </div>

        <DocumentPreviewDialog
            v-if="resumen.vista_previa"
            v-model:open="verVistaPrevia"
            :preview-url="resumen.vista_previa.url"
            :nombre="resumen.vista_previa.nombre"
            tipo="pdf"
            mime="application/pdf"
            estado="Vista previa — así lo recibirá el colaborador"
        />
    </div>
</template>
