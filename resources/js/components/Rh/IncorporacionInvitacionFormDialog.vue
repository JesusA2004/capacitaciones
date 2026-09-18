<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
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
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/rh/incorporacion/invitaciones';
import type { OpcionesIncorporacionInvitacion } from '@/types/incorporacionInvitacion';

const props = defineProps<{
    open: boolean;
    opciones: OpcionesIncorporacionInvitacion;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

// El QR es de acceso temporal a un formulario de incorporación: rango
// deliberadamente corto (1-24 horas), nunca días ni fechas libres.
const DURACIONES = [
    { value: '1', etiqueta: '1 hora' },
    { value: '2', etiqueta: '2 horas' },
    { value: '4', etiqueta: '4 horas' },
    { value: '8', etiqueta: '8 horas' },
    { value: '12', etiqueta: '12 horas' },
    { value: '24', etiqueta: '24 horas' },
];

const form = useForm({
    candidato_id: '',
    duracion_horas: '24',
    observaciones: '',
});

// Alta Digital QR simplificado (sección 5 del encargo): el candidato ya
// trae puesto/sucursal/departamento/empresa desde su ficha — RH solo elige
// quién es y por cuánto tiempo vale el QR, nunca vuelve a capturarlos.
const opcionesCandidato = computed(() =>
    props.opciones.candidatosElegibles.map((candidato) => ({
        value: String(candidato.id),
        label: `${candidato.nombre} — ${candidato.puesto ?? 'Sin puesto'} — ${candidato.sucursal ?? 'Sin sucursal'}`,
    })),
);

const candidatoSeleccionado = computed(() =>
    props.opciones.candidatosElegibles.find(
        (candidato) => String(candidato.id) === form.candidato_id,
    ) ?? null,
);

function enviar() {
    const transformado = form.transform((datos) => ({
        candidato_id: Number(datos.candidato_id),
        duracion_horas: Number(datos.duracion_horas),
        observaciones: datos.observaciones || null,
    }));

    transformado.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Alta Digital QR</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label>Candidato listo para contratación</Label>
                    <Combobox
                        v-model="form.candidato_id"
                        :items="opcionesCandidato"
                        placeholder="Busca por nombre..."
                        empty-text="No hay candidatos listos para contratación disponibles."
                    />
                    <InputError :message="form.errors.candidato_id" />
                </div>

                <div
                    v-if="candidatoSeleccionado"
                    class="rounded-xl border border-border/60 bg-muted/30 p-3 text-sm"
                >
                    <p class="font-medium">
                        {{ candidatoSeleccionado.nombre }} —
                        {{ candidatoSeleccionado.puesto ?? 'Sin puesto' }} —
                        {{ candidatoSeleccionado.sucursal ?? 'Sin sucursal' }} —
                        {{ candidatoSeleccionado.departamento ?? 'Sin departamento' }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ candidatoSeleccionado.empresa ?? 'Sin empresa' }} ·
                        {{ candidatoSeleccionado.correo ?? 'Sin correo' }} ·
                        {{ candidatoSeleccionado.telefono ?? 'Sin teléfono' }}
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Estos datos se autocompletan desde la ficha del
                        candidato: ya no se capturan a mano.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>Vigencia del QR</Label>
                    <Select v-model="form.duracion_horas">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in DURACIONES"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        Pasado ese tiempo el QR deja de funcionar. Puedes
                        regenerarlo cuando quieras desde la invitación.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="observaciones">Observaciones internas</Label>
                    <Textarea
                        id="observaciones"
                        v-model="form.observaciones"
                        rows="2"
                        placeholder="Solo visible para RH, nunca para el colaborador."
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
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.candidato_id"
                    >
                        <Spinner v-if="form.processing" />
                        Generar invitación
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
