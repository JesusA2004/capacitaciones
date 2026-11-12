<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Eye, FileSignature, Info, Loader2, Send } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatearFecha } from '@/lib/fechas';
import { vistaPreviaPost } from '@/lib/http';
import { store, vistaPrevia } from '@/routes/portal/carta-compromiso';
import type { SolicitudCartaCompromisoEstado } from '@/types';

/**
 * «Mi expediente» → «Pedir carta compromiso»: la propia persona elige cuáles
 * de sus documentos requeridos pendientes se compromete a entregar (hasta
 * el máximo de una carta), ve la fecha límite y la vista previa, y la envía.
 * Queda pendiente de aprobación de RH (SolicitudCartaCompromisoService);
 * el plazo corre desde la aprobación.
 */
const props = defineProps<{ solicitud: SolicitudCartaCompromisoEstado }>();

const abierto = ref(false);
const seleccion = ref<number[]>([]);
const vistaPreviaUrl = ref<string | null>(null);
const visorAbierto = ref(false);
const previsualizando = ref(false);
const enviando = ref(false);
const error = ref<string | null>(null);

function limpiarVistaPrevia() {
    if (vistaPreviaUrl.value !== null) {
        URL.revokeObjectURL(vistaPreviaUrl.value);
        vistaPreviaUrl.value = null;
    }
}

onBeforeUnmount(limpiarVistaPrevia);
// Lo que se envía es exactamente lo que se vio.
watch(seleccion, limpiarVistaPrevia, { deep: true });

const excede = computed(
    () => seleccion.value.length > props.solicitud.max_documentos,
);
const listo = computed(() => seleccion.value.length > 0 && !excede.value);
const textoMaximo = computed(() =>
    props.solicitud.max_documentos === 1
        ? '1 documento'
        : `${props.solicitud.max_documentos} documentos`,
);

function abrir() {
    error.value = null;
    // Se proponen todos sus pendientes (caben, si puede pedirla).
    seleccion.value = props.solicitud.pendientes
        .slice(0, props.solicitud.max_documentos)
        .map((p) => p.document_type_id);
    abierto.value = true;
}

function alternar(id: number, marcado: boolean) {
    seleccion.value = marcado
        ? [...new Set([...seleccion.value, id])]
        : seleccion.value.filter((x) => x !== id);
}

async function verVistaPrevia() {
    if (!listo.value || previsualizando.value) {
        return;
    }

    visorAbierto.value = true;

    if (vistaPreviaUrl.value !== null) {
        return;
    }

    previsualizando.value = true;
    error.value = null;

    try {
        vistaPreviaUrl.value = await vistaPreviaPost(vistaPrevia.url(), {
            documentos: seleccion.value,
        });
    } catch (e) {
        visorAbierto.value = false;
        toast.error(
            e instanceof Error
                ? e.message
                : 'No se pudo preparar la vista previa.',
        );
    } finally {
        previsualizando.value = false;
    }
}

async function regenerar() {
    limpiarVistaPrevia();
    await verVistaPrevia();
}

function enviar() {
    if (!listo.value || vistaPreviaUrl.value === null) {
        return;
    }

    router.post(
        store.url(),
        { documentos: seleccion.value },
        {
            preserveScroll: true,
            onStart: () => {
                enviando.value = true;
            },
            onFinish: () => {
                enviando.value = false;
            },
            onSuccess: () => {
                visorAbierto.value = false;
                abierto.value = false;
                limpiarVistaPrevia();
            },
            onError: (errores) => {
                error.value =
                    Object.values(errores)[0] ??
                    'No se pudo enviar la carta compromiso.';
                visorAbierto.value = false;
            },
        },
    );
}
</script>

<template>
    <Card
        v-if="solicitud.mostrar"
        class="rounded-2xl border-border/60 bg-crema"
    >
        <CardContent
            class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex min-w-0 items-start gap-3">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                >
                    <FileSignature class="size-4" />
                </span>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold">
                        ¿Todavía no tienes algún documento?
                    </h3>
                    <p
                        v-if="solicitud.puede_solicitar"
                        class="text-sm text-muted-foreground"
                    >
                        Pide una carta compromiso: eliges hasta
                        {{ textoMaximo }} que te faltan y tienes
                        {{ solicitud.dias_plazo }} días para entregarlos desde
                        que Recursos Humanos la apruebe.
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ solicitud.motivo }}
                    </p>
                </div>
            </div>
            <Button v-if="solicitud.puede_solicitar" @click="abrir">
                <FileSignature class="size-4" /> Pedir carta compromiso
            </Button>
        </CardContent>
    </Card>

    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Pedir carta compromiso</DialogTitle>
                <DialogDescription>
                    Marca los documentos que te comprometes a entregar (máximo
                    {{ textoMaximo }}). Recursos Humanos aprobará tu carta.
                </DialogDescription>
            </DialogHeader>

            <ul class="flex flex-col gap-2">
                <li v-for="p in solicitud.pendientes" :key="p.document_type_id">
                    <label
                        class="flex cursor-pointer items-start gap-3 rounded-xl border border-border/60 bg-card px-3 py-2.5 text-sm"
                    >
                        <Checkbox
                            class="mt-0.5"
                            :model-value="
                                seleccion.includes(p.document_type_id)
                            "
                            @update:model-value="
                                (v) => alternar(p.document_type_id, !!v)
                            "
                        />
                        <span class="flex-1">
                            <span class="block font-medium">{{
                                p.nombre
                            }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{ p.estado_etiqueta }}
                            </span>
                        </span>
                    </label>
                </li>
            </ul>

            <p v-if="excede" class="text-sm text-destructive">
                Una carta cubre como máximo {{ textoMaximo }}: desmarca alguno.
            </p>

            <div
                class="flex items-start gap-2 rounded-xl bg-salvia/40 px-3 py-2 text-sm"
            >
                <Info class="mt-0.5 size-4 shrink-0 text-primary" />
                <span>
                    Plazo: {{ solicitud.dias_plazo }} días naturales. Si
                    Recursos Humanos la aprueba hoy, tendrías hasta el
                    <strong>{{ formatearFecha(solicitud.fecha_limite) }}</strong
                    >. Mientras no la apruebe, la app te sigue pidiendo subir
                    tus documentos.
                </span>
            </div>

            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="abierto = false">
                    Cancelar
                </Button>
                <Button
                    :disabled="!listo || previsualizando"
                    @click="verVistaPrevia"
                >
                    <Loader2
                        v-if="previsualizando"
                        class="size-4 animate-spin"
                    />
                    <Eye v-else class="size-4" /> Ver vista previa
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <DocumentPreviewDialog
        v-model:open="visorAbierto"
        :preview-url="vistaPreviaUrl"
        :download-url="vistaPreviaUrl"
        nombre="Vista previa — mi carta compromiso.pdf"
        mime="application/pdf"
        tipo="pdf"
        :cargando="previsualizando"
        titulo-carga="Vista previa de tu carta compromiso"
        recargable
        @recargar="regenerar"
    >
        <template #pie>
            <section
                class="flex flex-col gap-3 border-t border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-3 sm:px-5"
                aria-label="Enviar la carta"
            >
                <div
                    class="flex items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
                >
                    <Info class="mt-0.5 size-4 shrink-0" />
                    <span>
                        <strong>Vista previa — aún no se ha enviado.</strong>
                        Al enviarla queda pendiente de aprobación de Recursos
                        Humanos.
                    </span>
                </div>
                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end"
                >
                    <Button
                        variant="ghost"
                        :disabled="enviando"
                        @click="visorAbierto = false"
                    >
                        Cambiar documentos
                    </Button>
                    <Button
                        :disabled="
                            enviando || previsualizando || !vistaPreviaUrl
                        "
                        @click="enviar"
                    >
                        <Loader2 v-if="enviando" class="size-4 animate-spin" />
                        <Send v-else class="size-4" />
                        Enviar a Recursos Humanos
                    </Button>
                </div>
            </section>
        </template>
    </DocumentPreviewDialog>
</template>
