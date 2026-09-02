<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
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
import { actualizar } from '@/routes/administracion/jerarquia-puestos';
import type { PuestoJerarquiaItem } from '@/types';

const props = defineProps<{
    open: boolean;
    puesto: PuestoJerarquiaItem;
    todosLosPuestos: PuestoJerarquiaItem[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const opcionesPuesto = computed(() =>
    props.todosLosPuestos.filter((p) => p.id !== props.puesto.id),
);

const form = useForm({
    tipo_puesto: props.puesto.tipo_puesto ?? '',
    nivel_jerarquico: props.puesto.nivel_jerarquico
        ? String(props.puesto.nivel_jerarquico)
        : '',
    puesto_superior_id: props.puesto.puesto_superior_id
        ? String(props.puesto.puesto_superior_id)
        : '',
    puesto_crecimiento_id: props.puesto.puesto_crecimiento_id
        ? String(props.puesto.puesto_crecimiento_id)
        : '',
    esquema_comisiones: props.puesto.esquema_comisiones ?? '',
    requiere_ruta: props.puesto.requiere_ruta,
    responsabilidades: props.puesto.responsabilidades ?? '',
    requisitos: props.puesto.requisitos ?? '',
    respaldos: props.puesto.respaldos.map((r) => r.id),
});

function alternarRespaldo(id: number, marcado: boolean) {
    if (marcado) {
        if (!form.respaldos.includes(id)) {
            form.respaldos.push(id);
        }
    } else {
        form.respaldos = form.respaldos.filter((r) => r !== id);
    }
}

function enviar() {
    const transformado = form.transform((datos) => ({
        ...datos,
        tipo_puesto: datos.tipo_puesto || null,
        nivel_jerarquico: datos.nivel_jerarquico || null,
        puesto_superior_id: datos.puesto_superior_id || null,
        puesto_crecimiento_id: datos.puesto_crecimiento_id || null,
    }));

    transformado.put(actualizar.url(props.puesto.id), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Jerarquía de «{{ puesto.nombre }}»</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Tipo de puesto</Label>
                        <Select v-model="form.tipo_puesto">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin clasificar" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="comercial"
                                    >Comercial</SelectItem
                                >
                                <SelectItem value="administrativo"
                                    >Administrativo</SelectItem
                                >
                                <SelectItem value="operativo"
                                    >Operativo</SelectItem
                                >
                                <SelectItem value="otro">Otro</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.tipo_puesto" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="nivel">Nivel jerárquico</Label>
                        <Input
                            id="nivel"
                            v-model="form.nivel_jerarquico"
                            type="number"
                            min="1"
                            placeholder="1 = más alto"
                        />
                        <InputError :message="form.errors.nivel_jerarquico" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Puesto superior</Label>
                    <Select v-model="form.puesto_superior_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Sin puesto superior" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opcionesPuesto"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                            >
                                {{ opcion.nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.puesto_superior_id" />
                </div>

                <div class="grid gap-2">
                    <Label>Ruta de crecimiento (puede crecer a)</Label>
                    <Select v-model="form.puesto_crecimiento_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Sin ruta definida" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opcionesPuesto"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                            >
                                {{ opcion.nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.puesto_crecimiento_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="comisiones">Esquema de comisiones</Label>
                    <Input
                        id="comisiones"
                        v-model="form.esquema_comisiones"
                        placeholder="Ej. Comisión por cartera/ruta"
                    />
                    <InputError :message="form.errors.esquema_comisiones" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.requiere_ruta"
                        @update:model-value="(v) => (form.requiere_ruta = !!v)"
                    />
                    Requiere ruta asignada
                </label>

                <div class="grid gap-2">
                    <Label for="responsabilidades">Responsabilidades</Label>
                    <Textarea
                        id="responsabilidades"
                        v-model="form.responsabilidades"
                        rows="3"
                    />
                    <InputError :message="form.errors.responsabilidades" />
                </div>

                <div class="grid gap-2">
                    <Label for="requisitos">Requisitos</Label>
                    <Textarea
                        id="requisitos"
                        v-model="form.requisitos"
                        rows="3"
                    />
                    <InputError :message="form.errors.requisitos" />
                </div>

                <div class="grid gap-2">
                    <Label
                        >Respaldos (puestos que pueden cubrir este
                        puesto)</Label
                    >
                    <div
                        class="grid max-h-40 gap-2 overflow-y-auto rounded-md border p-3"
                    >
                        <label
                            v-for="opcion in opcionesPuesto"
                            :key="opcion.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="
                                    form.respaldos.includes(opcion.id)
                                "
                                @update:model-value="
                                    (v) => alternarRespaldo(opcion.id, !!v)
                                "
                            />
                            {{ opcion.nombre }}
                        </label>
                        <p
                            v-if="!opcionesPuesto.length"
                            class="text-xs text-muted-foreground"
                        >
                            No hay otros puestos disponibles.
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
