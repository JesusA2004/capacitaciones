<script setup lang="ts">
import { AlertTriangle, Download, Eye, ListChecks, Sparkles } from '@lucide/vue';
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
import { postJson } from '@/lib/http';
import { generar, vistaPrevia } from '@/routes/rh/formatos-oficiales';
import type { FormatoOficialItem, PersonaDisponible } from '@/types';

const props = defineProps<{
    open: boolean;
    formato: FormatoOficialItem;
    colaboradoresDisponibles: PersonaDisponible[];
    candidatosDisponibles: PersonaDisponible[];
    puedeDescargar: boolean;
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
const previewSolicitado = ref(false);
const previewPdfBase64 = ref<string | null>(null);
const previewDatos = ref<Record<string, string>>({});
const previewFaltantes = ref<string[]>([]);
const generacion = ref<{ id: number; nombre: string; descargarUrl: string } | null>(null);

type VistaPreviaRespuesta = {
    pdf_base64: string;
    datos: Record<string, string>;
    faltantes: string[];
};

type GenerarRespuesta = {
    generacion: { id: number; nombre: string; descargar_url: string };
};

const puedeOperar = computed(() => sujetoId.value !== '');
const previewUrl = computed(() =>
    previewPdfBase64.value
        ? `data:application/pdf;base64,${previewPdfBase64.value}`
        : null,
);

watch([tipoSujeto, sujetoId], () => {
    previewSolicitado.value = false;
    previewPdfBase64.value = null;
    generacion.value = null;
});

async function verVistaPrevia() {
    if (!puedeOperar.value) {
        return;
    }

    cargandoPreview.value = true;
    previewSolicitado.value = true;
    generacion.value = null;

    try {
        const respuesta = await postJson<VistaPreviaRespuesta>(
            vistaPrevia.url(props.formato.id),
            {
                tipo_sujeto: tipoSujeto.value,
                sujeto_id: Number(sujetoId.value),
                extra: valoresExtra.value,
            },
        );

        previewPdfBase64.value = respuesta.pdf_base64;
        previewDatos.value = respuesta.datos;
        previewFaltantes.value = respuesta.faltantes;
    } catch {
        mostrarError('No se pudo generar la vista previa.');
    } finally {
        cargandoPreview.value = false;
    }
}

async function generarDocumento() {
    if (!puedeOperar.value) {
        return;
    }

    generando.value = true;

    try {
        const respuesta = await postJson<GenerarRespuesta>(
            generar.url(props.formato.id),
            {
                tipo_sujeto: tipoSujeto.value,
                sujeto_id: Number(sujetoId.value),
                extra: valoresExtra.value,
            },
        );

        generacion.value = {
            id: respuesta.generacion.id,
            nombre: respuesta.generacion.nombre,
            descargarUrl: respuesta.generacion.descargar_url,
        };
        mostrarExito('Documento generado correctamente.');
    } catch {
        mostrarError('No fue posible generar el documento.');
    } finally {
        generando.value = false;
    }
}

function etiquetaCampo(clave: string): string {
    return clave.replaceAll('_', ' ');
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="flex max-h-[90vh] w-[calc(100vw-2rem)] flex-col overflow-hidden sm:max-w-2xl lg:max-w-4xl">
            <DialogHeader>
                <DialogTitle>Generar «{{ formato.nombre }}»</DialogTitle>
                <DialogDescription>
                    Elige para quién es el documento. Sus datos se precargan automáticamente sobre el formato oficial.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-1 flex-col gap-4 overflow-y-auto pr-1">
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

                <Button
                    variant="outline"
                    class="w-fit"
                    :disabled="!puedeOperar || cargandoPreview"
                    @click="verVistaPrevia"
                >
                    <Spinner v-if="cargandoPreview" />
                    <Eye v-else class="size-4" />
                    Vista previa
                </Button>

                <div
                    v-if="previewSolicitado && !cargandoPreview && Object.keys(previewDatos).length > 0"
                    class="rounded-xl border p-3"
                >
                    <p class="mb-2 flex items-center gap-2 text-sm font-medium">
                        <ListChecks class="size-4 text-muted-foreground" />
                        Datos detectados del colaborador
                    </p>
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                        <template v-for="(valor, clave) in previewDatos" :key="clave">
                            <div v-if="valor" class="flex justify-between gap-2 border-b border-dashed py-1 sm:justify-start">
                                <dt class="shrink-0 capitalize text-muted-foreground">{{ etiquetaCampo(clave) }}:</dt>
                                <dd class="truncate font-medium">{{ valor }}</dd>
                            </div>
                        </template>
                    </dl>
                </div>

                <div
                    v-if="previewSolicitado && previewFaltantes.length > 0"
                    class="flex flex-col gap-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3"
                >
                    <p class="flex items-center gap-2 text-sm font-medium text-amber-700 dark:text-amber-400">
                        <AlertTriangle class="size-4" />
                        Datos faltantes para este documento
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Puedes escribirlos aquí solo para este documento. No se guardan en el expediente salvo que los captures ahí manualmente.
                    </p>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div v-for="clave in previewFaltantes" :key="clave" class="grid gap-1">
                            <Label class="text-xs capitalize">{{ etiquetaCampo(clave) }}</Label>
                            <Input v-model="valoresExtra[clave]" :placeholder="etiquetaCampo(clave)" />
                        </div>
                    </div>
                    <Button size="sm" variant="outline" class="w-fit" @click="verVistaPrevia">
                        Actualizar vista previa
                    </Button>
                </div>

                <div
                    v-if="previewSolicitado"
                    class="overflow-hidden rounded-xl border bg-muted/20"
                >
                    <iframe
                        v-if="previewUrl"
                        :src="previewUrl"
                        title="Vista previa del formato generado"
                        class="h-[50vh] w-full min-h-80"
                    />
                    <div
                        v-else-if="!cargandoPreview"
                        class="flex flex-col items-center gap-2 p-8 text-center text-sm text-muted-foreground"
                    >
                        No se pudo generar la vista previa.
                    </div>
                </div>

                <div
                    v-if="generacion"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[var(--success)]/40 bg-[var(--success)]/5 p-3"
                >
                    <p class="text-sm text-[var(--success)]">Documento generado: {{ generacion.nombre }}</p>
                    <Button v-if="puedeDescargar" as-child size="sm">
                        <a :href="generacion.descargarUrl">
                            <Download class="size-4" />
                            Descargar PDF
                        </a>
                    </Button>
                </div>
            </div>

            <DialogFooter>
                <Button type="button" variant="secondary" @click="emit('update:open', false)">
                    Cerrar
                </Button>
                <Button :disabled="!puedeOperar || generando" @click="generarDocumento">
                    <Spinner v-if="generando" />
                    <Sparkles v-else class="size-4" />
                    Generar documento
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
