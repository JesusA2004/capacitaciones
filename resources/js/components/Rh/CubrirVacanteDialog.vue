<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cubrir } from '@/routes/rh/vacantes';
import type { OpcionesReclutamiento, VacanteItem } from '@/types';

const props = defineProps<{
    open: boolean;
    vacante: VacanteItem;
    opciones: OpcionesReclutamiento;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const MODOS = [
    {
        value: 'colaborador_interno',
        etiqueta: 'Colaborador interno (promoción / cambio de puesto)',
    },
    { value: 'cobertura_temporal', etiqueta: 'Cobertura temporal' },
    {
        value: 'candidato_externo',
        etiqueta: 'Candidato externo (Alta Digital)',
    },
] as const;

const form = useForm({
    modo: 'colaborador_interno' as
        'colaborador_interno' | 'cobertura_temporal' | 'candidato_externo',
    user_id: '',
    motivo: '',
    fecha_inicio: new Date().toISOString().slice(0, 10),
    fecha_fin: '',
});

const colaboradores = computed(() => props.opciones.colaboradores ?? []);

function enviar() {
    const transformado = form.transform((datos) => ({
        ...datos,
        user_id: datos.user_id || null,
        fecha_fin: datos.fecha_fin || null,
    }));

    transformado.post(cubrir.url(props.vacante.id), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle
                    >Cubrir vacante de «{{
                        vacante.puesto?.nombre ?? 'este puesto'
                    }}»</DialogTitle
                >
                <DialogDescription>
                    Elige cómo se cubre esta vacante. Cada modo registra el
                    movimiento laboral correspondiente en el histórico del
                    colaborador.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label>Modo de cobertura</Label>
                    <Select v-model="form.modo">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in MODOS"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.modo" />
                </div>

                <p
                    v-if="form.modo === 'candidato_externo'"
                    class="rounded-xl border border-dashed border-border/60 p-3 text-xs text-muted-foreground"
                >
                    No se mueve nada aquí: registra o continúa el Alta Digital
                    del candidato enlazándola a esta vacante desde
                    Candidatos/Altas digitales. La vacante se cerrará sola
                    cuando esa alta se apruebe.
                </p>

                <template v-else>
                    <div class="grid gap-2">
                        <Label>Colaborador</Label>
                        <Select v-model="form.user_id">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    placeholder="Selecciona un colaborador"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in colaboradores"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.name }}
                                    {{ opcion.apellidos }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.user_id" />
                    </div>

                    <div
                        v-if="form.modo === 'cobertura_temporal'"
                        class="grid grid-cols-2 gap-4"
                    >
                        <div class="grid gap-2">
                            <Label for="fecha_inicio">Fecha de inicio</Label>
                            <Input
                                id="fecha_inicio"
                                v-model="form.fecha_inicio"
                                type="date"
                            />
                            <InputError :message="form.errors.fecha_inicio" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="fecha_fin"
                                >Fecha de fin (opcional)</Label
                            >
                            <Input
                                id="fecha_fin"
                                v-model="form.fecha_fin"
                                type="date"
                            />
                            <InputError :message="form.errors.fecha_fin" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="motivo">Motivo / observaciones</Label>
                        <Textarea id="motivo" v-model="form.motivo" rows="3" />
                        <InputError :message="form.errors.motivo" />
                    </div>
                </template>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Confirmar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
