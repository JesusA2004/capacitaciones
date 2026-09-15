<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
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
import { subirFirmado } from '@/routes/rh/formatos';

/**
 * Sube el escaneo del documento ya firmado en físico y lo archiva en el
 * expediente del colaborador — ver
 * App\Http\Controllers\Rh\FormatoController::subirFirmado().
 */
const props = defineProps<{
    open: boolean;
    documentoId: number;
    tiposDocumento: { id: number; nombre: string }[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    document_type_id: '',
    archivo: null as File | null,
});

const archivosSeleccionados = computed<File[]>({
    get: () => (form.archivo ? [form.archivo] : []),
    set: (archivos) => {
        form.archivo = archivos[0] ?? null;
    },
});

function enviar() {
    form.post(subirFirmado.url(props.documentoId), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            emit('update:open', false);
            form.reset();
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="w-[calc(100vw-2rem)] max-w-none sm:w-[min(90vw,800px)]">
            <DialogHeader>
                <DialogTitle>Subir documento firmado</DialogTitle>
                <DialogDescription>
                    Sube el escaneo del documento firmado en físico. Quedará
                    archivado en el expediente del colaborador, en revisión.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label>Tipo de documento en el expediente</Label>
                <Select v-model="form.document_type_id">
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="Selecciona un tipo" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opcion in tiposDocumento"
                            :key="opcion.id"
                            :value="String(opcion.id)"
                            >{{ opcion.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <p
                    v-if="form.errors.document_type_id"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.document_type_id }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label>Archivo firmado (PDF o imagen)</Label>
                <PeopleFileDropzone
                    v-model="archivosSeleccionados"
                    accept=".pdf,.jpg,.jpeg,.png"
                    :max-size-mb="20"
                    :disabled="form.processing"
                />
                <p v-if="form.errors.archivo" class="text-xs text-destructive">
                    {{ form.errors.archivo }}
                </p>
            </div>

            <DialogFooter>
                <Button
                    :disabled="
                        form.processing ||
                        !form.document_type_id ||
                        !form.archivo
                    "
                    @click="enviar"
                >
                    <Spinner v-if="form.processing" />
                    Subir y archivar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
