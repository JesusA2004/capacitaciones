<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    RefreshCw,
    Sparkles,
    XCircle,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { getJson } from '@/lib/http';
import {
    aplicar,
    ignorar,
    reprocesar,
    show,
} from '@/routes/rh/documentos/extraccion';
import type { DocumentExtractionItem } from '@/types';

const props = defineProps<{
    open: boolean;
    documentoId: number;
    tipoNombre: string;
    puedeAplicar: boolean;
    puedeReprocesar: boolean;
    puedeIgnorar: boolean;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const ETIQUETAS_CAMPO: Record<string, string> = {
    curp: 'CURP',
    rfc: 'RFC',
    nss: 'Número de Seguridad Social',
    fecha_nacimiento: 'Fecha de nacimiento',
    codigo_postal: 'Código postal',
    sexo: 'Sexo',
};

const { mostrarExito, mostrarError } = useAlertas();
const cargando = ref(true);
const elegible = ref(true);
const extraccion = ref<DocumentExtractionItem | null>(null);
const seleccionados = ref<Record<string, boolean>>({});
const valores = ref<Record<string, string>>({});
const enviando = ref(false);
const reprocesando = ref(false);

async function cargar() {
    cargando.value = true;

    try {
        const respuesta = await getJson<{
            elegible: boolean;
            extraccion: DocumentExtractionItem | null;
        }>(show.url(props.documentoId));

        elegible.value = respuesta.elegible;
        extraccion.value = respuesta.extraccion;

        seleccionados.value = {};
        valores.value = {};

        for (const [campo, dato] of Object.entries(
            respuesta.extraccion?.differences ?? {},
        )) {
            seleccionados.value[campo] = !dato.coincide;
            valores.value[campo] = dato.detectado;
        }
    } catch {
        mostrarError('No se pudo cargar la extracción de este documento.');
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);

const campos = computed(() => Object.entries(extraccion.value?.differences ?? {}));

function aplicarSeleccionados() {
    const seleccion = Object.fromEntries(
        Object.entries(valores.value).filter(([campo]) => seleccionados.value[campo]),
    );

    if (Object.keys(seleccion).length === 0) {
        mostrarError('Selecciona al menos un campo para aplicar.');

        return;
    }

    enviando.value = true;
    router.post(
        aplicar.url(props.documentoId),
        { valores: seleccion },
        {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito('Datos aplicados al colaborador.');
                emit('update:open', false);
            },
            onError: () => mostrarError('No se pudieron aplicar los datos.'),
            onFinish: () => (enviando.value = false),
        },
    );
}

function ignorarTodo() {
    enviando.value = true;
    router.post(
        ignorar.url(props.documentoId),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito('Sugerencias descartadas.');
                emit('update:open', false);
            },
            onFinish: () => (enviando.value = false),
        },
    );
}

function reprocesarDocumento() {
    reprocesando.value = true;
    router.post(
        reprocesar.url(props.documentoId),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('El documento se enviará a re-procesar en un momento.'),
            onFinish: () => (reprocesando.value = false),
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Sparkles class="size-4 text-primary" />
                    Datos detectados — {{ tipoNombre }}
                </DialogTitle>
                <DialogDescription>
                    Sugerencias automáticas, nunca se aplican solas. Revisa y decide.
                </DialogDescription>
            </DialogHeader>

            <div v-if="cargando" class="flex justify-center py-8">
                <Spinner />
            </div>

            <template v-else>
                <div v-if="!elegible" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    Este tipo de documento no tiene extracción automática configurada.
                </div>

                <div v-else-if="!extraccion" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    Todavía no se ha procesado este documento. Puede tardar unos segundos después de subirlo.
                </div>

                <div v-else-if="extraccion.status === 'processing' || extraccion.status === 'pending'" class="flex items-center gap-2 rounded-lg border p-4 text-sm text-muted-foreground">
                    <Spinner /> Procesando el documento...
                </div>

                <div
                    v-else-if="extraccion.status === 'failed'"
                    class="flex flex-col gap-2 rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 text-sm"
                >
                    <p class="flex items-center gap-2 font-medium text-amber-700 dark:text-amber-400">
                        <AlertTriangle class="size-4" />
                        No se pudieron leer datos automáticamente
                    </p>
                    <p class="text-muted-foreground">{{ extraccion.error_message }}</p>
                </div>

                <template v-else>
                    <div v-if="campos.length === 0" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        No se detectaron datos reconocibles en este documento.
                    </div>

                    <div v-else class="flex flex-col gap-3">
                        <div
                            v-for="[campo, dato] in campos"
                            :key="campo"
                            class="flex flex-col gap-2 rounded-lg border p-3"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <label class="flex items-center gap-2 text-sm font-medium">
                                    <Checkbox
                                        v-if="puedeAplicar"
                                        :model-value="seleccionados[campo]"
                                        @update:model-value="(v) => (seleccionados[campo] = !!v)"
                                    />
                                    {{ ETIQUETAS_CAMPO[campo] ?? campo }}
                                </label>
                                <Badge v-if="dato.coincide" variant="outline" class="gap-1 text-[var(--success)]">
                                    <CheckCircle2 class="size-3" /> Coincide
                                </Badge>
                                <Badge v-else-if="dato.actual" variant="outline" class="gap-1 text-destructive">
                                    <XCircle class="size-3" /> Distinto
                                </Badge>
                                <Badge v-else variant="outline">Sin capturar</Badge>
                            </div>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <div class="grid gap-1">
                                    <Label class="text-xs text-muted-foreground">Detectado en el documento (puedes corregirlo)</Label>
                                    <Input v-model="valores[campo]" :disabled="!puedeAplicar" />
                                </div>
                                <div class="grid gap-1">
                                    <Label class="text-xs text-muted-foreground">Actual en el sistema</Label>
                                    <Input :model-value="dato.actual ?? '—'" disabled />
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </template>

            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <Button
                    v-if="puedeReprocesar"
                    variant="ghost"
                    size="sm"
                    :disabled="reprocesando"
                    @click="reprocesarDocumento"
                >
                    <Spinner v-if="reprocesando" />
                    <RefreshCw v-else class="size-4" />
                    Reprocesar
                </Button>
                <Button
                    v-if="puedeIgnorar && extraccion && campos.length > 0"
                    variant="outline"
                    size="sm"
                    :disabled="enviando"
                    @click="ignorarTodo"
                >
                    Ignorar
                </Button>
                <Button
                    v-if="puedeAplicar && extraccion && campos.length > 0"
                    size="sm"
                    :disabled="enviando"
                    @click="aplicarSeleccionados"
                >
                    <Spinner v-if="enviando" />
                    Aplicar seleccionados
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
