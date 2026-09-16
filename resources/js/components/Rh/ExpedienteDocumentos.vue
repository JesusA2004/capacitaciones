<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    CircleDashed,
    Clock3,
    Eye,
    FileEdit,
    FileX2,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import DocumentoDropzone from '@/components/Rh/DocumentoDropzone.vue';
import DocumentoPreviewDialog from '@/components/Rh/DocumentoPreviewDialog.vue';
import ExtraccionDocumentoDialog from '@/components/Rh/ExtraccionDocumentoDialog.vue';
import RevisarDocumentoDialog from '@/components/Rh/RevisarDocumentoDialog.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { aprobar } from '@/routes/rh/documentos';
import type { DocumentoExpedienteItem } from '@/types';

const props = defineProps<{
    colaboradorId: number;
    documentos: DocumentoExpedienteItem[];
    puedeSubir: boolean;
    puedeRevisar: boolean;
    puedeVerExtraccion: boolean;
    puedeAplicarExtraccion: boolean;
    puedeReprocesarExtraccion: boolean;
    puedeIgnorarExtraccion: boolean;
}>();

const { mostrarExito } = useAlertas();

const dialogoAbierto = ref(false);
const dialogoModo = ref<'rechazar' | 'corregir'>('rechazar');
const documentoActivo = ref<{ id: number; tipoNombre: string } | null>(null);

const ESTADOS_REEMPLAZABLES = ['rechazado', 'requiere_correccion'];

type ClaveColumna = 'sin_subir' | 'en_revision' | 'atencion' | 'aprobado';

const COLUMNAS: {
    clave: ClaveColumna;
    titulo: string;
    icono: typeof Clock3;
    tono: string;
    estados: string[];
}[] = [
    {
        clave: 'sin_subir',
        titulo: 'Sin subir',
        icono: CircleDashed,
        tono: 'border-t-muted-foreground/40',
        estados: [],
    },
    {
        clave: 'en_revision',
        titulo: 'En revisión',
        icono: Clock3,
        tono: 'border-t-warning',
        estados: [
            'pendiente',
            'cargado',
            'en_revision',
            'cambio_solicitado',
            'cambio_autorizado',
        ],
    },
    {
        clave: 'atencion',
        titulo: 'Necesita atención',
        icono: AlertTriangle,
        tono: 'border-t-destructive',
        estados: ['rechazado', 'requiere_correccion', 'vencido'],
    },
    {
        clave: 'aprobado',
        titulo: 'Aprobado',
        icono: CheckCircle2,
        tono: 'border-t-success',
        estados: ['aprobado'],
    },
];

function columnaDe(item: DocumentoExpedienteItem): ClaveColumna {
    if (!item.documento) {
        return 'sin_subir';
    }

    for (const columna of COLUMNAS) {
        if (columna.estados.includes(item.documento.status)) {
            return columna.clave;
        }
    }

    return 'en_revision';
}

const tablero = computed(() =>
    COLUMNAS.map((columna) => ({
        ...columna,
        items: props.documentos.filter(
            (item) => columnaDe(item) === columna.clave,
        ),
    })),
);

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

const dialogoExtraccionAbierto = ref(false);
const documentoExtraccion = ref<{ id: number; tipoNombre: string } | null>(null);

function abrirExtraccion(documentoId: number, tipoNombre: string) {
    documentoExtraccion.value = { id: documentoId, tipoNombre };
    dialogoExtraccionAbierto.value = true;
}

const dialogoPreviewAbierto = ref(false);
const documentoPreview = ref<{
    id: number;
    nombre: string;
    mime: string | null;
} | null>(null);

function abrirPreview(item: DocumentoExpedienteItem) {
    if (!item.documento) {
        return;
    }

    documentoPreview.value = {
        id: item.documento.id,
        nombre: item.documento.original_name,
        mime: item.documento.mime,
    };
    dialogoPreviewAbierto.value = true;
}
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div
            v-for="columna in tablero"
            :key="columna.clave"
            class="flex flex-col gap-3"
        >
            <div
                class="flex items-center gap-2 rounded-xl border-t-2 bg-muted/40 px-3 py-2"
                :class="columna.tono"
            >
                <component :is="columna.icono" class="size-4 text-muted-foreground" />
                <p class="text-sm font-semibold">{{ columna.titulo }}</p>
                <span
                    class="ml-auto rounded-full bg-background px-2 py-0.5 text-xs font-medium tabular-nums text-muted-foreground"
                >
                    {{ columna.items.length }}
                </span>
            </div>

            <div class="flex flex-col gap-3">
                <div
                    v-for="item in columna.items"
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
                        <EstadoBadge
                            v-if="item.documento"
                            :estado="item.documento.status"
                        />
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
                            size="sm"
                            variant="outline"
                            @click="abrirPreview(item)"
                        >
                            <Eye class="size-3.5" />
                            Vista previa
                        </Button>

                        <Button
                            v-if="item.documento && puedeVerExtraccion"
                            size="sm"
                            variant="outline"
                            class="border-primary/40 text-primary hover:bg-primary/10"
                            @click="
                                abrirExtraccion(
                                    item.documento.id,
                                    item.tipo.nombre,
                                )
                            "
                        >
                            <Sparkles class="size-3.5" />
                            Datos detectados
                        </Button>

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
                                    abrirCorreccion(
                                        item.documento.id,
                                        item.tipo.nombre,
                                    )
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
                                    abrirRechazo(
                                        item.documento.id,
                                        item.tipo.nombre,
                                    )
                                "
                            >
                                <FileX2 class="size-3.5" />
                                Rechazar
                            </Button>
                        </template>
                    </div>

                    <DocumentoDropzone
                        v-if="
                            puedeSubir &&
                            (!item.documento ||
                                ESTADOS_REEMPLAZABLES.includes(
                                    item.documento.status,
                                ))
                        "
                        :colaborador-id="colaboradorId"
                        :tipo-id="item.tipo.id"
                        :etiqueta="
                            item.documento ? 'Subir nueva versión' : 'Subir'
                        "
                    />
                </div>

                <p
                    v-if="columna.items.length === 0"
                    class="rounded-xl border border-dashed border-border/60 p-4 text-center text-xs text-muted-foreground"
                >
                    Sin documentos aquí.
                </p>
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

    <ExtraccionDocumentoDialog
        v-if="dialogoExtraccionAbierto && documentoExtraccion"
        v-model:open="dialogoExtraccionAbierto"
        :documento-id="documentoExtraccion.id"
        :tipo-nombre="documentoExtraccion.tipoNombre"
        :puede-aplicar="puedeAplicarExtraccion"
        :puede-reprocesar="puedeReprocesarExtraccion"
        :puede-ignorar="puedeIgnorarExtraccion"
        :key="`extraccion-${documentoExtraccion.id}`"
    />

    <DocumentoPreviewDialog
        v-if="dialogoPreviewAbierto && documentoPreview"
        v-model:open="dialogoPreviewAbierto"
        :documento-id="documentoPreview.id"
        :nombre-archivo="documentoPreview.nombre"
        :mime="documentoPreview.mime"
        :key="`preview-${documentoPreview.id}`"
    />
</template>
