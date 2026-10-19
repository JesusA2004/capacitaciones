<script setup lang="ts">
import { AlertTriangle, Sparkles } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import ExtraccionDocumentoDialog from '@/components/Rh/ExtraccionDocumentoDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { getJson } from '@/lib/http';
import { extraccionesPendientes } from '@/routes/rh/expedientes';

const ETIQUETAS_CAMPO: Record<string, string> = {
    curp: 'CURP',
    rfc: 'RFC',
    nss: 'Número de Seguridad Social',
    fecha_nacimiento: 'Fecha de nacimiento',
    codigo_postal: 'Código postal',
    sexo: 'Sexo',
    clave_elector: 'Clave de elector',
    correo: 'Correo personal',
    telefono: 'Teléfono',
    nacionalidad: 'Nacionalidad',
    estado_civil: 'Estado civil',
    lugar_nacimiento: 'Lugar de nacimiento',
    profesion: 'Profesión',
    domicilio: 'Domicilio',
    colonia: 'Colonia',
    municipio: 'Municipio',
    estado_domicilio: 'Estado (domicilio)',
    beneficiario_nombre: 'Nombre del beneficiario',
    beneficiario_parentesco: 'Parentesco del beneficiario',
    contacto_emergencia_nombre: 'Nombre del contacto de emergencia',
    contacto_emergencia_telefono: 'Teléfono del contacto de emergencia',
    contacto_emergencia_parentesco: 'Parentesco del contacto de emergencia',
};

type PendienteItem = {
    id: number;
    fuente_tipo: 'documento' | 'historico';
    fuente_nombre: string;
    employee_document_id: number | null;
    expediente_historico_id: number | null;
    campos: string[];
};

type ConflictoEntrada = {
    valor: string;
    fuente: string;
    extraccion_id: number;
};

const props = defineProps<{
    open: boolean;
    colaboradorId: number;
    puedeAplicar: boolean;
    puedeReprocesar: boolean;
    puedeIgnorar: boolean;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const { mostrarError } = useAlertas();
const cargando = ref(true);
const pendientes = ref<PendienteItem[]>([]);
const conflictos = ref<Record<string, ConflictoEntrada[]>>({});
const itemActivo = ref<PendienteItem | null>(null);

async function cargar() {
    cargando.value = true;

    try {
        const respuesta = await getJson<{
            pendientes: PendienteItem[];
            conflictos: Record<string, ConflictoEntrada[]>;
        }>(extraccionesPendientes.url(props.colaboradorId));

        pendientes.value = respuesta.pendientes;
        conflictos.value = respuesta.conflictos;
    } catch {
        mostrarError('No se pudo cargar el resumen de datos detectados.');
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);

function abrir(item: PendienteItem) {
    itemActivo.value = item;
}

function cerrarDialogoDocumento(abierto: boolean) {
    if (!abierto) {
        itemActivo.value = null;
        cargar();
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Sparkles class="size-4 text-primary" />
                    Datos encontrados en documentos existentes
                </DialogTitle>
                <DialogDescription>
                    Sugerencias de todos los documentos del expediente (normales
                    e histórico). Nada se aplica solo — ábrelas una por una para
                    revisar y decidir.
                </DialogDescription>
            </DialogHeader>

            <div v-if="cargando" class="flex justify-center py-8">
                <Spinner />
            </div>

            <template v-else>
                <div
                    v-if="Object.keys(conflictos).length > 0"
                    class="flex flex-col gap-2 rounded-lg border border-warning/40 bg-warning/5 p-3 text-sm"
                >
                    <p class="flex items-center gap-2 font-medium text-warning">
                        <AlertTriangle class="size-4" />
                        Documentos en conflicto
                    </p>
                    <div
                        v-for="(entradas, campo) in conflictos"
                        :key="campo"
                        class="text-xs text-muted-foreground"
                    >
                        <span class="font-medium text-foreground">{{
                            ETIQUETAS_CAMPO[campo] ?? campo
                        }}</span>
                        :
                        <template
                            v-for="(e, i) in entradas"
                            :key="e.extraccion_id"
                        >
                            <span v-if="i > 0"> vs. </span>
                            «{{ e.valor }}» ({{ e.fuente }})
                        </template>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        RH decide manualmente cuál es correcto — el sistema
                        nunca elige solo.
                    </p>
                </div>

                <div
                    v-if="pendientes.length === 0"
                    class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                >
                    No hay sugerencias pendientes de revisión.
                </div>

                <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="item in pendientes"
                        :key="item.id"
                        class="flex items-center justify-between gap-2 rounded-lg border p-3"
                    >
                        <div class="flex flex-col gap-1">
                            <span class="text-sm font-medium">{{
                                item.fuente_nombre
                            }}</span>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="campo in item.campos"
                                    :key="campo"
                                    variant="outline"
                                    class="text-xs"
                                >
                                    {{ ETIQUETAS_CAMPO[campo] ?? campo }}
                                </Badge>
                            </div>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="abrir(item)"
                        >
                            Revisar
                        </Button>
                    </div>
                </div>
            </template>
        </DialogContent>
    </Dialog>

    <ExtraccionDocumentoDialog
        v-if="itemActivo"
        :open="!!itemActivo"
        :documento-id="
            (itemActivo.fuente_tipo === 'historico'
                ? itemActivo.expediente_historico_id
                : itemActivo.employee_document_id) ?? 0
        "
        :fuente="itemActivo.fuente_tipo"
        :tipo-nombre="itemActivo.fuente_nombre"
        :puede-aplicar="puedeAplicar"
        :puede-reprocesar="puedeReprocesar"
        :puede-ignorar="puedeIgnorar"
        @update:open="cerrarDialogoDocumento"
    />
</template>
