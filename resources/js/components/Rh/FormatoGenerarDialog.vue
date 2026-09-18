<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Download, Eye, FileWarning } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { getJson, postJson } from '@/lib/http';
import { preview, resolverPlantilla, store } from '@/routes/rh/formatos';
import type { FormatoCatalogoItem } from '@/types';

const props = defineProps<{
    open: boolean;
    plantilla: FormatoCatalogoItem;
    colaboradoresDisponibles: { id: number; name: string; apellidos: string | null }[];
    candidatosDisponibles: { id: number; nombre: string; apellidos: string | null }[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const { mostrarExito, mostrarError } = useAlertas();

const tipoSujeto = ref<'colaborador' | 'candidato'>('colaborador');
const sujetoId = ref('');
const valoresExtra = ref<Record<string, string>>({});

const cargandoPreview = ref(false);
const generando = ref(false);
const previewHtml = ref<string | null>(null);
const previewFaltantes = ref<string[]>([]);
const previewSolicitado = ref(false);

type PreviewRespuesta = {
    html: string | null;
    variables: Record<string, string>;
    faltantes: string[];
};

const puedeOperar = computed(() => sujetoId.value !== '');

// Sugerencia informativa (ver PlantillaResolverService): qué plantilla se
// usa normalmente para este colaborador según su puesto/departamento/
// sucursal/empresa — nunca reemplaza la que RH ya eligió desde el
// catálogo, solo avisa si hay otra más específica configurada.
type PlantillaSugerida = { id: number; nombre: string } | null;
const plantillaSugerida = ref<PlantillaSugerida>(null);

watch([sujetoId, tipoSujeto], async ([id, tipo]) => {
    plantillaSugerida.value = null;

    if (tipo !== 'colaborador' || !id) {
        return;
    }

    try {
        const respuesta = await getJson<{ data: PlantillaSugerida }>(
            resolverPlantilla.url({ query: { colaborador_id: id, tipo: props.plantilla.tipo } }),
        );
        plantillaSugerida.value = respuesta.data;
    } catch {
        plantillaSugerida.value = null;
    }
});

async function verVistaPrevia() {
    if (!puedeOperar.value) {
        return;
    }

    cargandoPreview.value = true;
    previewSolicitado.value = true;

    try {
        const respuesta = await postJson<PreviewRespuesta>(preview.url(), {
            document_template_id: props.plantilla.id,
            tipo_sujeto: tipoSujeto.value,
            sujeto_id: Number(sujetoId.value),
            extra: valoresExtra.value,
        });

        previewHtml.value = respuesta.html;
        previewFaltantes.value = respuesta.faltantes;
    } catch {
        mostrarError('No se pudo generar la vista previa.');
    } finally {
        cargandoPreview.value = false;
    }
}

function generar() {
    generando.value = true;
    router.post(
        store.url(),
        {
            document_template_id: props.plantilla.id,
            tipo_sujeto: tipoSujeto.value,
            sujeto_id: sujetoId.value,
            extra: valoresExtra.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito('Documento generado correctamente.');
                emit('update:open', false);
            },
            onError: () => mostrarError('No fue posible generar el documento.'),
            onFinish: () => (generando.value = false),
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Generar «{{ plantilla.nombre }}»</DialogTitle>
                <DialogDescription>
                    Elige para quién es el documento, revisa la vista previa
                    y genera. {{ plantilla.tipo_etiqueta }}.
                </DialogDescription>
            </DialogHeader>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label>Para</Label>
                    <Select v-model="tipoSujeto">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="colaborador">Colaborador</SelectItem>
                            <SelectItem value="candidato">Candidato</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>{{
                        tipoSujeto === 'colaborador' ? 'Colaborador' : 'Candidato'
                    }}</Label>
                    <Select v-model="sujetoId">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Selecciona..." />
                        </SelectTrigger>
                        <SelectContent>
                            <template v-if="tipoSujeto === 'colaborador'">
                                <SelectItem
                                    v-for="opcion in colaboradoresDisponibles"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.name }} {{ opcion.apellidos }}</SelectItem
                                >
                            </template>
                            <template v-else>
                                <SelectItem
                                    v-for="opcion in candidatosDisponibles"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }} {{ opcion.apellidos }}</SelectItem
                                >
                            </template>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <p
                v-if="plantillaSugerida && plantillaSugerida.id !== plantilla.id"
                class="rounded-lg border border-border/60 bg-muted/40 p-2.5 text-xs text-muted-foreground"
            >
                Para este colaborador normalmente se usa
                <span class="font-medium text-foreground">{{
                    plantillaSugerida.nombre
                }}</span>
                — puedes cerrar y generar esa desde el catálogo, o
                continuar con «{{ plantilla.nombre }}».
            </p>
            <p
                v-else-if="plantillaSugerida && plantillaSugerida.id === plantilla.id"
                class="rounded-lg border border-success/30 bg-success/10 p-2.5 text-xs text-success"
            >
                Plantilla aplicada automáticamente para este colaborador.
            </p>

            <Button
                variant="outline"
                :disabled="!puedeOperar || cargandoPreview"
                @click="verVistaPrevia"
            >
                <Spinner v-if="cargandoPreview" />
                <Eye v-else class="size-4" />
                Vista previa
            </Button>

            <div
                v-if="previewSolicitado && previewFaltantes.length > 0"
                class="flex flex-col gap-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3"
            >
                <p class="flex items-center gap-2 text-sm font-medium text-amber-700 dark:text-amber-400">
                    <AlertTriangle class="size-4" />
                    Datos faltantes para generar este formato
                </p>
                <p class="text-xs text-muted-foreground">
                    Estas variables no tienen valor capturado. Puedes llenarlas aquí solo para este documento (no se guardan en el expediente).
                </p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <div v-for="clave in previewFaltantes" :key="clave" class="grid gap-1">
                        <Label class="text-xs">{{ clave.replaceAll('_', ' ') }}</Label>
                        <Input v-model="valoresExtra[clave]" :placeholder="clave" />
                    </div>
                </div>
                <Button size="sm" variant="outline" class="w-fit" @click="verVistaPrevia">
                    Actualizar vista previa
                </Button>
            </div>

            <div
                v-if="previewSolicitado"
                class="overflow-hidden rounded-xl border bg-white"
            >
                <iframe
                    v-if="previewHtml"
                    :srcdoc="previewHtml"
                    title="Vista previa del formato"
                    class="h-[420px] w-full"
                />
                <div
                    v-else-if="!cargandoPreview"
                    class="flex flex-col items-center gap-2 p-8 text-center text-sm text-muted-foreground"
                >
                    <FileWarning class="size-6" />
                    Esta plantilla no se pudo convertir a vista previa (estructura no soportada). Puedes generarla y descargarla directamente para revisarla.
                </div>
            </div>

            <DialogFooter>
                <Button type="button" variant="secondary" @click="emit('update:open', false)">
                    Cancelar
                </Button>
                <Button :disabled="!puedeOperar || generando" @click="generar">
                    <Spinner v-if="generando" />
                    <Download v-else class="size-4" />
                    Generar documento
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
