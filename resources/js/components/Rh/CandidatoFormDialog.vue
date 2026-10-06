<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
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
import { store, update } from '@/routes/rh/candidatos';
import type { CandidatoItem, OpcionesReclutamiento } from '@/types';

const props = defineProps<{
    open: boolean;
    candidato?: CandidatoItem | null;
    opciones: OpcionesReclutamiento;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

function idComo(valor: { id: number } | null | undefined): string {
    return valor?.id ? String(valor.id) : '';
}

const form = useForm({
    empresa_id: idComo(props.candidato?.empresa),
    sucursal_id: props.candidato?.sucursal_id
        ? String(props.candidato.sucursal_id)
        : '',
    puesto_objetivo_id: idComo(props.candidato?.puesto_objetivo),
    vacante_id: idComo(props.candidato?.vacante),
    espontaneo: props.candidato?.espontaneo ?? false,
    nombre: props.candidato?.nombre ?? '',
    apellidos: props.candidato?.apellidos ?? '',
    telefono: props.candidato?.telefono ?? '',
    correo: props.candidato?.correo ?? '',
    fuente: props.candidato?.fuente ?? '',
    observaciones: props.candidato?.observaciones ?? '',
});

// El puesto/sucursal/empresa se derivan SIEMPRE de la vacante elegida (la
// vacante ya nació de un puesto y sucursal concretos: baja, headcount
// nuevo, etc.) — ver Candidato::booted(). Los selects manuales de puesto y
// sucursal solo aparecen para un candidato EXPLÍCITAMENTE espontáneo (sin
// vacante todavía): nunca se vuelve a preguntar lo que la vacante ya trae.
const vacanteSeleccionada = computed(() =>
    props.opciones.vacantes?.find((v) => String(v.id) === form.vacante_id),
);

watch(
    () => form.vacante_id,
    () => {
        if (vacanteSeleccionada.value?.puesto_id) {
            form.puesto_objetivo_id = String(
                vacanteSeleccionada.value.puesto_id,
            );
        }
    },
);

watch(
    () => form.espontaneo,
    (esEspontaneo) => {
        if (esEspontaneo) {
            form.vacante_id = '';
        } else {
            form.puesto_objetivo_id = '';
            form.sucursal_id = '';
        }
    },
);

function etiquetaVacante(vacante: {
    id: number;
    puesto?: { nombre: string } | null;
    sucursal?: { nombre: string } | null;
}): string {
    const puesto = vacante.puesto?.nombre ?? `Vacante #${vacante.id}`;

    return vacante.sucursal ? `${puesto} — ${vacante.sucursal.nombre}` : puesto;
}

function enviar() {
    const transformado = form.transform((datos) => ({
        ...datos,
        empresa_id: datos.empresa_id || null,
        sucursal_id: datos.sucursal_id || null,
        puesto_objetivo_id: datos.puesto_objetivo_id || null,
        vacante_id: datos.vacante_id || null,
        fuente: datos.fuente || null,
    }));

    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.candidato) {
        transformado.put(update.url(props.candidato.id), opciones);
    } else {
        transformado.post(store.url(), opciones);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{
                    candidato ? 'Editar candidato' : 'Nuevo candidato'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="nombre">Nombre</Label>
                        <Input id="nombre" v-model="form.nombre" autofocus />
                        <InputError :message="form.errors.nombre" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="apellidos">Apellidos</Label>
                        <Input id="apellidos" v-model="form.apellidos" />
                        <InputError :message="form.errors.apellidos" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="telefono">Teléfono</Label>
                        <Input id="telefono" v-model="form.telefono" />
                        <InputError :message="form.errors.telefono" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="correo">Correo</Label>
                        <Input id="correo" v-model="form.correo" type="email" />
                        <InputError :message="form.errors.correo" />
                    </div>
                </div>

                <!-- Vacante: campo principal del alta. Puesto, sucursal y
                     empresa salen SIEMPRE de ella (Candidato::booted()); no
                     se vuelven a pedir aquí. -->
                <div v-if="!form.espontaneo" class="grid gap-2">
                    <Label>Vacante <span class="text-destructive">*</span></Label>
                    <Select v-model="form.vacante_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Elige una vacante disponible" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.vacantes ?? []"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ etiquetaVacante(opcion) }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.vacante_id" />
                    <p class="text-xs text-muted-foreground">
                        Solo se listan vacantes con plaza real disponible. Al
                        elegirla, puesto y sucursal se toman automáticamente
                        de ella.
                    </p>
                </div>

                <div
                    v-if="!form.espontaneo && form.vacante_id"
                    class="grid grid-cols-2 gap-4"
                >
                    <div
                        class="rounded-lg border border-border/60 bg-muted/40 px-3 py-2 text-sm"
                    >
                        <span class="text-muted-foreground">Puesto: </span>
                        <span class="font-medium">{{
                            vacanteSeleccionada?.puesto?.nombre ?? '—'
                        }}</span>
                    </div>
                    <div
                        class="rounded-lg border border-border/60 bg-muted/40 px-3 py-2 text-sm"
                    >
                        <span class="text-muted-foreground">Sucursal: </span>
                        <span class="font-medium">{{
                            vacanteSeleccionada?.sucursal?.nombre ?? '—'
                        }}</span>
                    </div>
                </div>

                <div class="flex items-start gap-2 rounded-lg border border-border/60 px-3 py-2">
                    <Checkbox
                        id="espontaneo"
                        class="mt-0.5"
                        :model-value="form.espontaneo"
                        @update:model-value="(v) => (form.espontaneo = !!v)"
                    />
                    <label for="espontaneo" class="text-sm leading-snug">
                        <span class="font-medium"
                            >Candidato espontáneo / sin vacante actual</span
                        >
                        <p class="text-xs text-muted-foreground">
                            Úsalo solo si todavía no hay una vacante abierta
                            para él. No podrá avanzar a contratación hasta
                            vincularlo a una vacante real disponible.
                        </p>
                    </label>
                </div>

                <!-- Candidato espontáneo: puesto de interés y sucursal son
                     preferencia, no una vacante — se piden a mano porque
                     todavía no existe una apertura concreta. -->
                <div v-if="form.espontaneo" class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Puesto de interés</Label>
                        <Select v-model="form.puesto_objetivo_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin puesto" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.puestos"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label>Sucursal preferida</Label>
                        <Select v-model="form.sucursal_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin sucursal" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.sucursales"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Fuente / canal</Label>
                    <Select v-model="form.fuente">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Sin especificar" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.fuentes ?? []"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.fuente" />
                </div>

                <div class="grid gap-2">
                    <Label for="observaciones">Observaciones</Label>
                    <Textarea
                        id="observaciones"
                        v-model="form.observaciones"
                        rows="3"
                    />
                    <InputError :message="form.errors.observaciones" />
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
