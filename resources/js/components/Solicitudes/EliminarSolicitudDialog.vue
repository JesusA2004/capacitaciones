<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Archive, Ban, BellOff, Trash2, Undo2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { destroy, eliminacion } from '@/routes/rh/solicitudes';
import type { RevisionEliminacionSolicitud } from '@/types';

/**
 * Eliminar una solicitud de prueba o capturada por error (cualquier tipo y
 * estado). Antes de confirmar consulta al backend qué se revierte, qué se
 * conserva y si algo la bloquea (EliminacionSolicitudService::revisar). No
 * se avisa al colaborador ni a los aprobadores.
 */
const abierto = defineModel<boolean>('open', { required: true });

const props = withDefaults(
    defineProps<{
        solicitud: { id: number; folio: string } | null;
        /** Desde el detalle: al eliminar vuelve al tablero. */
        desdeDetalle?: boolean;
    }>(),
    { desdeDetalle: false },
);

const emit = defineEmits<{ eliminada: [id: number] }>();

const revision = ref<RevisionEliminacionSolicitud | null>(null);
const errorCarga = ref<string | null>(null);
const form = useForm({ motivo: '', desde_detalle: props.desdeDetalle });

watch(
    () => [abierto.value, props.solicitud?.id] as const,
    async ([abiertoAhora, id]) => {
        revision.value = null;
        errorCarga.value = null;
        form.reset();
        form.clearErrors();

        if (!abiertoAhora || !id) {
            return;
        }

        try {
            const respuesta = await fetch(eliminacion.url(id), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const cuerpo = (await respuesta.json().catch(() => ({}))) as {
                data?: RevisionEliminacionSolicitud;
                message?: string;
            };

            if (!respuesta.ok || !cuerpo.data) {
                errorCarga.value =
                    cuerpo.message || 'No se pudo revisar la solicitud.';

                return;
            }

            // Si mientras tanto se abrió otra solicitud, esta respuesta ya no aplica.
            if (props.solicitud?.id === id) {
                revision.value = cuerpo.data;
            }
        } catch {
            errorCarga.value =
                'No se pudo revisar la solicitud. Revisa tu conexión.';
        }
    },
    { immediate: true },
);

// El backend responde con la clave `solicitud` cuando algo la bloquea.
const errorSolicitud = computed(
    () => (form.errors as Record<string, string | undefined>).solicitud,
);

function eliminar() {
    const solicitud = props.solicitud;

    if (!solicitud || !revision.value?.puede) {
        return;
    }

    form.desde_detalle = props.desdeDetalle;
    form.delete(destroy.url(solicitud.id), {
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = false;
            emit('eliminada', solicitud.id);
        },
    });
}
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Trash2 class="size-5 text-destructive" />
                    Eliminar solicitud {{ solicitud?.folio }}
                </DialogTitle>
                <DialogDescription>
                    Úsalo para solicitudes de prueba o capturadas por error.
                    <template v-if="revision">
                        {{ revision.tipo }} · {{ revision.estado
                        }}<template v-if="revision.persona">
                            · {{ revision.persona }}</template
                        >.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="!revision && !errorCarga" class="flex flex-col gap-2">
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-4 w-2/3" />
                <Skeleton class="h-4 w-1/2" />
            </div>

            <p
                v-else-if="errorCarga"
                class="rounded-xl bg-danger-soft p-3 text-sm text-destructive"
            >
                {{ errorCarga }}
            </p>

            <div v-else-if="revision" class="flex flex-col gap-4 text-sm">
                <div
                    v-if="!revision.puede"
                    class="flex flex-col gap-1.5 rounded-xl bg-danger-soft p-3"
                >
                    <p
                        class="flex items-center gap-1.5 font-medium text-destructive"
                    >
                        <Ban class="size-4" /> No se puede eliminar
                    </p>
                    <ul class="list-disc pl-5">
                        <li v-for="b in revision.bloqueos" :key="b">{{ b }}</li>
                    </ul>
                </div>

                <template v-else>
                    <p
                        class="flex items-start gap-2 rounded-xl bg-info-soft p-3 text-info"
                    >
                        <BellOff class="mt-0.5 size-4 shrink-0" />
                        <span>
                            <strong>No se avisará al colaborador</strong> ni a
                            quienes la aprobaban: la solicitud simplemente deja
                            de aparecer, en la web y en la app.
                        </span>
                    </p>

                    <div v-if="revision.revierte.length">
                        <p class="mb-1 flex items-center gap-1.5 font-medium">
                            <Undo2 class="size-4 text-success" /> Se deshace
                        </p>
                        <ul class="list-disc pl-5 text-muted-foreground">
                            <li v-for="r in revision.revierte" :key="r">
                                {{ r }}
                            </li>
                        </ul>
                    </div>

                    <div v-if="revision.conserva.length">
                        <p class="mb-1 flex items-center gap-1.5 font-medium">
                            <Archive class="size-4 text-bronce" /> Se conserva
                        </p>
                        <ul class="list-disc pl-5 text-muted-foreground">
                            <li v-for="c in revision.conserva" :key="c">
                                {{ c }}
                            </li>
                        </ul>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="eliminar-solicitud-motivo">
                            Motivo (opcional, queda en la bitácora)
                        </Label>
                        <Textarea
                            id="eliminar-solicitud-motivo"
                            v-model="form.motivo"
                            rows="2"
                            maxlength="500"
                            placeholder="Ej. Solicitud de prueba."
                        />
                        <InputError :message="form.errors.motivo" />
                    </div>
                </template>

                <InputError :message="errorSolicitud" />
            </div>

            <DialogFooter class="gap-2">
                <Button
                    variant="ghost"
                    :disabled="form.processing"
                    @click="abierto = false"
                >
                    Cancelar
                </Button>
                <Button
                    v-if="revision?.puede"
                    variant="destructive"
                    :disabled="form.processing"
                    @click="eliminar"
                >
                    <Spinner v-if="form.processing" class="size-4" />
                    <Trash2 v-else class="size-4" />
                    Eliminar sin avisar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
