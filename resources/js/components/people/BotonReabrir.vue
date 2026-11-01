<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import { ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/Rh/ReaperturaController';
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
import { Textarea } from '@/components/ui/textarea';

export type ReaperturaDisponible = {
    tipo: string;
    id: number;
    etiqueta: string;
};

/**
 * «Reabrir»: el backend solo manda las decisiones que esta cuenta puede reabrir
 * (ReaperturaService::disponibles) y vuelve a validarlo al recibir el motivo.
 */
defineProps<{ reaperturas: ReaperturaDisponible[] }>();

const elegida = ref<ReaperturaDisponible | null>(null);
const form = useForm({ motivo: '' });

function abrir(reapertura: ReaperturaDisponible) {
    form.reset();
    form.clearErrors();
    elegida.value = reapertura;
}

function enviar() {
    if (!elegida.value) {
        return;
    }

    form.post(store.url({ tipo: elegida.value.tipo, id: elegida.value.id }), {
        preserveScroll: true,
        onSuccess: () => (elegida.value = null),
    });
}
</script>

<template>
    <div v-if="reaperturas.length" class="flex flex-wrap gap-2">
        <Button
            v-for="reapertura in reaperturas"
            :key="reapertura.tipo"
            variant="outline"
            size="sm"
            @click="abrir(reapertura)"
        >
            <RotateCcw class="size-4" />
            Reabrir: {{ reapertura.etiqueta.toLowerCase() }}
        </Button>

        <Dialog
            :open="elegida !== null"
            @update:open="(abierto) => !abierto && (elegida = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        Reabrir: {{ elegida?.etiqueta.toLowerCase() }}
                    </DialogTitle>
                    <DialogDescription>
                        La decisión anterior deja de contar y el trámite vuelve
                        a ese paso. Tu nombre y tu motivo quedan en el
                        historial.
                    </DialogDescription>
                </DialogHeader>
                <div class="flex flex-col gap-2">
                    <Label for="motivo-reapertura">Motivo</Label>
                    <Textarea
                        id="motivo-reapertura"
                        v-model="form.motivo"
                        rows="3"
                        maxlength="1000"
                        placeholder="¿Por qué se reabre?"
                    />
                    <InputError :message="form.errors.motivo" />
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="elegida = null">
                        Cancelar
                    </Button>
                    <Button
                        :disabled="form.processing || form.motivo.trim() === ''"
                        @click="enviar"
                    >
                        Reabrir
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
