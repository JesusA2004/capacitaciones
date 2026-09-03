<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/rh/formatos';

/**
 * Genera un DOCX precargado a partir de una plantilla, usando los datos de
 * la solicitud (fechas, motivo, folio) como placeholders — ver
 * App\Http\Controllers\Rh\FormatoController::store y
 * ::extraDesdeSolicitud()/::extraDesdeSolicitudVacaciones().
 */
const props = defineProps<{
    open: boolean;
    solicitudId?: number;
    solicitudVacacionesId?: number;
    /** Tipo de plantilla sugerido para esta solicitud (TipoPlantillaDocumento), para ordenar la lista. */
    tipoSugerido?: string | null;
    plantillas: { id: number; nombre: string; tipo: string }[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const plantillasOrdenadas = computed(() => {
    if (!props.tipoSugerido) {
        return props.plantillas;
    }

    return [...props.plantillas].sort((a, b) => {
        const aSugerida = a.tipo === props.tipoSugerido ? 0 : 1;
        const bSugerida = b.tipo === props.tipoSugerido ? 0 : 1;

        return aSugerida - bSugerida;
    });
});

const form = useForm({
    document_template_id: '',
    solicitud_id: props.solicitudId ? String(props.solicitudId) : '',
    solicitud_vacaciones_id: props.solicitudVacacionesId
        ? String(props.solicitudVacacionesId)
        : '',
});

function enviar() {
    form.transform((datos) => ({
        ...datos,
        document_template_id: Number(datos.document_template_id),
        solicitud_id: datos.solicitud_id ? Number(datos.solicitud_id) : null,
        solicitud_vacaciones_id: datos.solicitud_vacaciones_id
            ? Number(datos.solicitud_vacaciones_id)
            : null,
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:open', false);
            form.reset('document_template_id');
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Generar formato</DialogTitle>
                <DialogDescription>
                    Se precargará con los datos de la solicitud (fechas, motivo,
                    folio) y del colaborador.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label>Plantilla</Label>
                <Select v-model="form.document_template_id">
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="Selecciona una plantilla" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opcion in plantillasOrdenadas"
                            :key="opcion.id"
                            :value="String(opcion.id)"
                            >{{ opcion.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <p
                    v-if="form.errors.document_template_id"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.document_template_id }}
                </p>
            </div>

            <DialogFooter>
                <Button
                    :disabled="form.processing || !form.document_template_id"
                    @click="enviar"
                >
                    <Spinner v-if="form.processing" />
                    Generar documento
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
