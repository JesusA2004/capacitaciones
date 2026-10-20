<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
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

const preparando = ref(false);
const errorLocal = ref<string | null>(null);

/**
 * Copia el APK a memoria al elegirlo: si el archivo cambia en disco antes
 * de terminar la subida (build que se sobrescribe, carpeta sincronizada de
 * OneDrive/Drive), Chrome aborta con ERR_UPLOAD_FILE_CHANGED. Con la copia
 * se sube exactamente lo que se eligió.
 */
async function elegirApk(archivos: File[]) {
    errorLocal.value = null;
    const archivo = archivos[0] ?? null;

    if (!archivo) {
        form.apk = null;

        return;
    }

    preparando.value = true;

    try {
        const contenido = await archivo.arrayBuffer();
        form.apk = new File([contenido], archivo.name, {
            type: archivo.type || 'application/vnd.android.package-archive',
            lastModified: archivo.lastModified,
        });
    } catch {
        form.apk = null;
        errorLocal.value =
            'No se pudo leer el APK: el archivo cambió o se está escribiendo todavía. Espera a que termine el build, cópialo a una carpeta local (no OneDrive/Drive) y vuelve a seleccionarlo.';
    } finally {
        preparando.value = false;
    }
}

function enviar() {
    errorLocal.value = null;
    let exito = false;

    form.post(store.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            exito = true;
            emit('update:open', false);
            form.reset();
        },
        onFinish: () => {
            if (!exito && Object.keys(form.errors).length === 0) {
                errorLocal.value =
                    'La subida se interrumpió (conexión o archivo cambiado). Revisa tu conexión y vuelve a intentarlo.';
            }
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
                <PeopleFileDropzone
                    :model-value="form.apk ? [form.apk] : []"
                    accept=".apk"
                    :max-size-mb="maxUploadMb"
                    label="Arrastra el APK aquí"
                    :loading="preparando"
                    @update:model-value="elegirApk"
                />
                <p v-if="form.errors.apk" class="text-xs text-destructive">
                    {{ form.errors.apk }}
                </p>
                <p
                    v-if="errorLocal"
                    class="text-xs text-destructive"
                    role="alert"
                >
                    {{ errorLocal }}
                </p>
                <p
                    v-if="form.progress"
                    class="text-xs text-muted-foreground"
                    role="status"
                >
                    Subiendo… {{ form.progress.percentage }}%
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
                    :disabled="
                        form.processing ||
                        preparando ||
                        !form.apk ||
                        !form.version
                    "
                    @click="enviar"
                >
                    <Spinner v-if="form.processing" />
                    Subir versión
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
