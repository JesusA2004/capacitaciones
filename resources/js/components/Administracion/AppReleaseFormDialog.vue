<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/administracion/app-releases';

defineProps<{
    open: boolean;
    maxUploadMb: number;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    apk: null as File | null,
    version: '',
    build_number: '',
    changelog: '',
    minimum_required: false as boolean,
});

function alSeleccionarArchivo(evento: Event) {
    const input = evento.target as HTMLInputElement;
    form.apk = input.files?.[0] ?? null;
}

function enviar() {
    form.post(store.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            emit('update:open', false);
            form.reset();
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Subir nueva versión (APK)</DialogTitle>
                <DialogDescription>
                    Solo Android por ahora. Tamaño máximo:
                    {{ maxUploadMb }} MB. La versión no se publica
                    automáticamente.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label>Archivo APK</Label>
                <Input type="file" accept=".apk" @change="alSeleccionarArchivo" />
                <p v-if="form.errors.apk" class="text-xs text-destructive">
                    {{ form.errors.apk }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label>Versión</Label>
                <Input v-model="form.version" placeholder="1.0.0" />
                <p v-if="form.errors.version" class="text-xs text-destructive">
                    {{ form.errors.version }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label>Build (opcional)</Label>
                <Input v-model="form.build_number" placeholder="1" />
            </div>

            <div class="grid gap-2">
                <Label>Notas de versión (opcional)</Label>
                <Textarea v-model="form.changelog" rows="3" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <Checkbox
                    :model-value="form.minimum_required"
                    @update:model-value="(v) => (form.minimum_required = !!v)"
                />
                Actualización obligatoria
            </label>

            <DialogFooter>
                <Button
                    :disabled="form.processing || !form.apk || !form.version"
                    @click="enviar"
                >
                    <Spinner v-if="form.processing" />
                    Subir versión
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
