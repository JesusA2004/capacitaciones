<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
    nombre: props.candidato?.nombre ?? '',
    apellidos: props.candidato?.apellidos ?? '',
    telefono: props.candidato?.telefono ?? '',
    correo: props.candidato?.correo ?? '',
    fuente: props.candidato?.fuente ?? '',
    observaciones: props.candidato?.observaciones ?? '',
});

// El puesto objetivo se deriva de la vacante seleccionada (la vacante ya
// nació de un puesto concreto: baja, headcount nuevo, etc.) — ver
// Candidato::booted(). El select de "Puesto objetivo" solo aparece cuando
// todavía no hay vacante (candidato en pipeline general, sin apertura
// concreta), para no pedirle dos veces el mismo dato ni permitir que queden
// inconsistentes.
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

                <!-- La sucursal solo se pide a mano cuando el candidato
                     todavía no tiene una vacante concreta (pipeline
                     general); si ya eligió vacante, se deriva de ella (ver
                     Candidato::booted()) y se muestra de solo lectura. -->
                <div v-if="!form.vacante_id" class="grid gap-2">
                    <Label>Sucursal</Label>
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
                <div
                    v-else
                    class="rounded-lg border border-border/60 bg-muted/40 px-3 py-2 text-sm"
                >
                    <span class="text-muted-foreground">Sucursal: </span>
                    <span class="font-medium">{{
                        vacanteSeleccionada?.sucursal?.nombre ?? '—'
                    }}</span>
                </div>

                <div class="grid gap-2">
                    <Label>Vacante relacionada</Label>
                    <Select v-model="form.vacante_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Sin vacante asociada" />
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
                    <p class="text-xs text-muted-foreground">
                        Si el candidato aplica a una vacante abierta, elígela
                        aquí: el puesto objetivo se toma automáticamente de
                        ella.
                    </p>
                </div>

                <!-- El puesto objetivo solo se pide a mano cuando el
                     candidato todavía no tiene una vacante concreta
                     (pipeline general); si ya eligió vacante, se muestra
                     de solo lectura para que quede claro de dónde sale. -->
                <div v-if="!form.vacante_id" class="grid gap-2">
                    <Label>Puesto objetivo</Label>
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
                <div
                    v-else
                    class="rounded-lg border border-border/60 bg-muted/40 px-3 py-2 text-sm"
                >
                    <span class="text-muted-foreground">Puesto objetivo: </span>
                    <span class="font-medium">{{
                        vacanteSeleccionada?.puesto?.nombre ?? '—'
                    }}</span>
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
