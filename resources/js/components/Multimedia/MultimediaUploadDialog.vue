<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { store } from '@/routes/multimedia';
import type { TipoRecursoOpcion } from '@/types';

defineProps<{
    open: boolean;
    tipos: TipoRecursoOpcion[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const inputArchivo = ref<HTMLInputElement | null>(null);

const form = useForm({
    tipo: 'video',
    archivo: null as File | null,
});

const progreso = ref(0);

function seleccionarArchivo(evento: Event) {
    const objetivo = evento.target as HTMLInputElement;
    form.archivo = objetivo.files?.[0] ?? null;
}

function enviar() {
    form.post(store.url(), {
        forceFormData: true,
        onProgress: (evento) => {
            progreso.value = evento?.percentage ?? 0;
        },
        onSuccess: () => {
            form.reset();
            progreso.value = 0;
            emit('update:open', false);
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Cargar archivo</DialogTitle>
                <DialogDescription
                    >Los videos se procesan en segundo plano después de
                    subirse.</DialogDescription
                >
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label>Tipo de recurso</Label>
                    <Select v-model="form.tipo">
                        <SelectTrigger class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in tipos"
                                :key="opcion.value"
                                :value="opcion.value"
                            >
                                {{ opcion.etiqueta }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.tipo" />
                </div>

                <div class="grid gap-2">
                    <Label for="archivo">Archivo</Label>
                    <input
                        id="archivo"
                        ref="inputArchivo"
                        type="file"
                        class="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-foreground"
                        @change="seleccionarArchivo"
                    />
                    <InputError :message="form.errors.archivo" />
                </div>

                <div
                    v-if="form.progress"
                    class="h-2 w-full overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full bg-[var(--brand-primary)] transition-all"
                        :style="{ width: `${progreso}%` }"
                    />
                </div>
                <p v-if="form.progress" class="text-xs text-muted-foreground">
                    Subiendo… {{ progreso }}%
                </p>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.archivo"
                    >
                        <Spinner v-if="form.processing" />
                        Cargar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
