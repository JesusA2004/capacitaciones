<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CheckCircle2, Download, FileEdit, FileX2, Upload } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import RevisarDocumentoDialog from '@/components/Rh/RevisarDocumentoDialog.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { aprobar, descargar } from '@/routes/rh/documentos';
import { store as subirDocumento } from '@/routes/rh/expedientes/documentos';
import type { DocumentoExpedienteItem } from '@/types';

const props = defineProps<{
    colaboradorId: number;
    documentos: DocumentoExpedienteItem[];
    puedeSubir: boolean;
    puedeRevisar: boolean;
}>();

const { mostrarExito, mostrarError } = useAlertas();

const subiendoTipoId = ref<number | null>(null);
const dialogoAbierto = ref(false);
const dialogoModo = ref<'rechazar' | 'corregir'>('rechazar');
const documentoActivo = ref<{ id: number; tipoNombre: string } | null>(null);

const ESTADOS_REEMPLAZABLES = ['rechazado', 'requiere_correccion'];

function inputRef(tipoId: number): HTMLInputElement | null {
    return document.querySelector(`#subir-documento-${tipoId}`);
}

function abrirSelector(tipoId: number) {
    inputRef(tipoId)?.click();
}

function archivoSeleccionado(tipoId: number, evento: Event) {
    const input = evento.target as HTMLInputElement;
    const archivo = input.files?.[0];

    if (!archivo) {
        return;
    }

    subiendoTipoId.value = tipoId;

    router.post(
        subirDocumento.url(props.colaboradorId),
        { document_type_id: tipoId, archivo },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito('Documento cargado. Queda en revisión.'),
            onError: () => mostrarError('No fue posible cargar el documento.'),
            onFinish: () => {
                subiendoTipoId.value = null;
                input.value = '';
            },
        },
    );
}

async function aprobarDocumento(documentoId: number, tipoNombre: string) {
    router.post(
        aprobar.url(documentoId),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito(`«${tipoNombre}» aprobado.`),
        },
    );
}

function abrirRechazo(documentoId: number, tipoNombre: string) {
    documentoActivo.value = { id: documentoId, tipoNombre };
    dialogoModo.value = 'rechazar';
    dialogoAbierto.value = true;
}

function abrirCorreccion(documentoId: number, tipoNombre: string) {
    documentoActivo.value = { id: documentoId, tipoNombre };
    dialogoModo.value = 'corregir';
    dialogoAbierto.value = true;
}
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <div
            v-for="item in documentos"
            :key="item.tipo.id"
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
        >
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">
                        {{ item.tipo.nombre }}
                        <span
                            v-if="item.tipo.requerido"
                            class="text-destructive"
                            title="Requerido"
                            >*</span
                        >
                    </p>
                    <p
                        v-if="item.documento"
                        class="truncate text-xs text-muted-foreground"
                    >
                        {{ item.documento.original_name }} · v{{
                            item.documento.version
                        }}
                    </p>
                </div>
                <EstadoBadge :estado="item.documento?.status ?? 'pendiente'" />
            </div>

            <p
                v-if="item.documento?.rejection_reason"
                class="rounded-lg bg-destructive/10 px-2 py-1.5 text-xs text-destructive"
            >
                {{ item.documento.rejection_reason }}
            </p>
            <p
                v-else-if="item.documento?.comments"
                class="rounded-lg bg-muted px-2 py-1.5 text-xs text-muted-foreground"
            >
                {{ item.documento.comments }}
            </p>

            <div class="mt-auto flex flex-wrap gap-2">
                <Button
                    v-if="item.documento"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <a :href="descargar.url(item.documento.id)" target="_blank">
                        <Download class="size-3.5" />
                        Ver
                    </a>
                </Button>

                <template v-if="puedeSubir">
                    <input
                        :id="`subir-documento-${item.tipo.id}`"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png"
                        class="hidden"
                        @change="archivoSeleccionado(item.tipo.id, $event)"
                    />
                    <Button
                        v-if="
                            !item.documento ||
                            ESTADOS_REEMPLAZABLES.includes(
                                item.documento.status,
                            )
                        "
                        size="sm"
                        :disabled="subiendoTipoId === item.tipo.id"
                        @click="abrirSelector(item.tipo.id)"
                    >
                        <Upload class="size-3.5" />
                        {{ item.documento ? 'Subir nueva versión' : 'Subir' }}
                    </Button>
                </template>

                <template
                    v-if="
                        puedeRevisar &&
                        item.documento &&
                        ['pendiente', 'en_revision'].includes(
                            item.documento.status,
                        )
                    "
                >
                    <Button
                        size="sm"
                        variant="outline"
                        class="border-success/40 text-success hover:bg-success/10"
                        @click="
                            aprobarDocumento(
                                item.documento.id,
                                item.tipo.nombre,
                            )
                        "
                    >
                        <CheckCircle2 class="size-3.5" />
                        Aprobar
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        class="border-warning/40 text-warning hover:bg-warning/10"
                        @click="
                            abrirCorreccion(item.documento.id, item.tipo.nombre)
                        "
                    >
                        <FileEdit class="size-3.5" />
                        Corrección
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        class="border-destructive/40 text-destructive hover:bg-destructive/10"
                        @click="
                            abrirRechazo(item.documento.id, item.tipo.nombre)
                        "
                    >
                        <FileX2 class="size-3.5" />
                        Rechazar
                    </Button>
                </template>
            </div>
        </div>
    </div>

    <RevisarDocumentoDialog
        v-if="dialogoAbierto && documentoActivo"
        v-model:open="dialogoAbierto"
        :documento-id="documentoActivo.id"
        :modo="dialogoModo"
        :tipo-nombre="documentoActivo.tipoNombre"
        :key="documentoActivo.id"
    />
</template>
