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
import { Spinner } from '@/components/ui/spinner';
import { subirFirmado } from '@/routes/rh/formatos-oficiales';

/**
 * Sube el escaneo firmado del documento oficial automático de una solicitud
 * (App\Http\Controllers\Rh\FormatoOficialController::subirFirmado()). A
 * diferencia de SubirFormatoFirmadoDialog.vue (plantillas DOCX manuales,
 * donde RH elige el tipo de documento del expediente), aquí el tipo se
 * resuelve solo por config/solicitudes.php — no se pide nada más que el
 * archivo.
 */
const props = defineProps<{
    open: boolean;
    generacionId: number;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({ archivo: null as File | null });

const archivosSeleccionados = computed<File[]>({
    get: () => (form.archivo ? [form.archivo] : []),
    set: (archivos) => {
        form.archivo = archivos[0] ?? null;
    },
});

function enviar() {
    form.post(subirFirmado.url(props.generacionId), {
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
                    Sube el escaneo del documento oficial ya firmado en
                    físico. Quedará archivado en el expediente del
                    colaborador.
                </DialogDescription>
            </DialogHeader>

            <PeopleFileDropzone
                v-model="archivosSeleccionados"
                accept=".pdf,.jpg,.jpeg,.png"
                :max-size-mb="20"
                :disabled="form.processing"
            />
            <p v-if="form.errors.archivo" class="text-xs text-destructive">
                {{ form.errors.archivo }}
            </p>

            <DialogFooter>
                <Button :disabled="form.processing || !form.archivo" @click="enviar">
                    <Spinner v-if="form.processing" />
                    Subir y archivar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
