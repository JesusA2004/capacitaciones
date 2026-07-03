<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import CargaVideoResumible from '@/components/Multimedia/CargaVideoResumible.vue';
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
const tipoSeleccionado = ref('video');

const form = useForm({
    tipo: 'documento',
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

function cargaDeVideoCompletada() {
    // La carga por bloques no pasa por Inertia (usa fetch/XHR propios), así
    // que el listado de la biblioteca se refresca manualmente al terminar.
    router.reload({ only: ['recursos'] });
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Cargar archivo</DialogTitle>
                <DialogDescription>
                    Los videos se cargan por bloques y se pueden pausar o
                    reanudar; se procesan en segundo plano después de
                    completarse.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label>Tipo de recurso</Label>
                    <Select v-model="tipoSeleccionado">
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
                </div>

                <CargaVideoResumible
                    v-if="tipoSeleccionado === 'video'"
                    @completado="cargaDeVideoCompletada"
                />

                <form
                    v-else
                    class="grid gap-4"
                    @submit.prevent="
                        () => {
                            form.tipo = tipoSeleccionado;
                            enviar();
                        }
                    "
                >
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
                    <p
                        v-if="form.progress"
                        class="text-xs text-muted-foreground"
                    >
                        Subiendo… {{ progreso }}%
                    </p>

                    <Button
                        type="submit"
                        class="self-start"
                        :disabled="form.processing || !form.archivo"
                    >
                        <Spinner v-if="form.processing" />
                        Cargar
                    </Button>
                </form>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="secondary"
                    @click="emit('update:open', false)"
                    >Cerrar</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
