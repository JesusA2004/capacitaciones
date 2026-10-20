<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { HandCoins, Loader2 } from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import VistaPreviaFlujo from '@/components/documentos/VistaPreviaFlujo.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { autorizar } from '@/routes/rh/solicitudes/prestamo';

/**
 * «Autorizar préstamo» (solo RH): monto y plazo autorizados más los datos
 * del «Contrato de crédito para trabajadores» que se capturan A MANO —
 * cargo (sin I.V.A.), I.V.A., monto total (con I.V.A.), No. de cheque y
 * primer/último pago parcial—. Nada se calcula: lo capturado es lo que se
 * imprime. Antes de confirmar, «Vista previa de los documentos» arma el
 * contrato, el pagaré y la carta de retención en memoria (no guarda nada).
 * El backend (PrestamoAutorizacionService, el mismo de la app) es quien
 * autoriza y genera los documentos.
 */
const props = defineProps<{
    open: boolean;
    solicitudId: number;
    montoSolicitado: string | null;
}>();

const emit = defineEmits<{ 'update:open': [valor: boolean] }>();

// Los <Input type="number"> devuelven string o número según el navegador.
type Numero = string | number;

const form = useForm({
    monto_autorizado: (props.montoSolicitado ?? '') as Numero,
    plazo_autorizado: '' as Numero,
    periodicidad: 'quincenal',
    pago_programado: '' as Numero,
    cargo_sin_iva: '' as Numero,
    iva: '' as Numero,
    monto_total_con_iva: '' as Numero,
    numero_cheque: '' as Numero,
    fecha_primer_descuento: '',
    fecha_ultimo_pago: '',
    observaciones: '',
});

const periodicidades = [
    { valor: 'semanal', etiqueta: 'Semanal' },
    { valor: 'quincenal', etiqueta: 'Quincenal' },
    { valor: 'mensual', etiqueta: 'Mensual' },
];

// Lo mismo que se va a autorizar, para la vista previa (sin observaciones).
const datosVistaPrevia = computed(() => {
    const datos: Record<string, string> = {};

    for (const [clave, valor] of Object.entries(form.data())) {
        if (clave !== 'observaciones' && String(valor).trim() !== '') {
            datos[clave] = String(valor).trim();
        }
    }

    return datos;
});

const completo = computed(
    () =>
        String(form.monto_autorizado).trim() !== '' &&
        String(form.plazo_autorizado).trim() !== '' &&
        String(form.cargo_sin_iva).trim() !== '' &&
        String(form.iva).trim() !== '' &&
        String(form.monto_total_con_iva).trim() !== '' &&
        form.fecha_primer_descuento !== '' &&
        form.fecha_ultimo_pago !== '',
);

// Errores de la regla de negocio que no son de un campo del formulario
// (falta visto bueno, la solicitud ya no está en trámite…).
const erroresGenerales = computed(() => {
    const errores = form.errors as Record<string, string | undefined>;

    return ['visto_bueno', 'solicitud', 'estado']
        .map((clave) => errores[clave])
        .filter((m): m is string => !!m);
});

function confirmar() {
    form.post(autorizar.url(props.solicitudId), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <HandCoins class="size-5" />
                    Autorizar préstamo
                </DialogTitle>
                <DialogDescription>
                    Captura los datos tal como van en el contrato de crédito. No
                    se calcula nada: lo que escribas es lo que se imprime.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="confirmar">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label for="pr-monto">Monto autorizado</Label>
                        <Input
                            id="pr-monto"
                            v-model="form.monto_autorizado"
                            type="number"
                            min="1"
                            step="0.01"
                            inputmode="decimal"
                            required
                        />
                        <InputError :message="form.errors.monto_autorizado" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="pr-plazo">Plazo (número de pagos)</Label>
                        <Input
                            id="pr-plazo"
                            v-model="form.plazo_autorizado"
                            type="number"
                            min="1"
                            step="1"
                            inputmode="numeric"
                            required
                        />
                        <InputError :message="form.errors.plazo_autorizado" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Periodicidad</Label>
                        <Select v-model="form.periodicidad">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="p in periodicidades"
                                    :key="p.valor"
                                    :value="p.valor"
                                >
                                    {{ p.etiqueta }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.periodicidad" />
                    </div>
                </div>

                <div
                    class="grid gap-4 rounded-xl border border-[var(--mrl-borde)] bg-crema p-4"
                >
                    <p class="text-sm font-semibold">
                        Datos del contrato de crédito
                    </p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label for="pr-cargo">Cargo (sin I.V.A.)</Label>
                            <Input
                                id="pr-cargo"
                                v-model="form.cargo_sin_iva"
                                type="number"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                required
                            />
                            <InputError :message="form.errors.cargo_sin_iva" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="pr-iva">I.V.A.</Label>
                            <Input
                                id="pr-iva"
                                v-model="form.iva"
                                type="number"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                required
                            />
                            <InputError :message="form.errors.iva" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="pr-total"
                                >Monto total (con I.V.A.)</Label
                            >
                            <Input
                                id="pr-total"
                                v-model="form.monto_total_con_iva"
                                type="number"
                                min="0"
                                step="0.01"
                                inputmode="decimal"
                                required
                            />
                            <InputError
                                :message="form.errors.monto_total_con_iva"
                            />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label for="pr-cheque">No. de cheque</Label>
                            <Input
                                id="pr-cheque"
                                v-model="form.numero_cheque"
                                maxlength="40"
                                placeholder="Opcional"
                            />
                            <InputError :message="form.errors.numero_cheque" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="pr-primer">Primer pago parcial</Label>
                            <DatePicker
                                id="pr-primer"
                                v-model="form.fecha_primer_descuento"
                            />
                            <InputError
                                :message="form.errors.fecha_primer_descuento"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="pr-ultimo">Último pago parcial</Label>
                            <DatePicker
                                id="pr-ultimo"
                                v-model="form.fecha_ultimo_pago"
                                :min-value="
                                    form.fecha_primer_descuento || undefined
                                "
                            />
                            <InputError
                                :message="form.errors.fecha_ultimo_pago"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1.5 sm:max-w-xs">
                        <Label for="pr-pago">Pago por periodo</Label>
                        <Input
                            id="pr-pago"
                            v-model="form.pago_programado"
                            type="number"
                            min="0"
                            step="0.01"
                            inputmode="decimal"
                            placeholder="Opcional"
                        />
                        <InputError :message="form.errors.pago_programado" />
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label for="pr-obs">Observaciones</Label>
                    <Textarea
                        id="pr-obs"
                        v-model="form.observaciones"
                        rows="2"
                        placeholder="Opcional"
                    />
                    <InputError :message="form.errors.observaciones" />
                </div>

                <!-- Contrato de crédito, pagaré y carta de retención con estos
                     datos, ANTES de autorizar (no se guarda nada). -->
                <VistaPreviaFlujo
                    flujo="prestamo"
                    :id="solicitudId"
                    :datos="datosVistaPrevia"
                    :disabled="form.processing"
                    etiqueta="Vista previa de los documentos"
                />
                <InputError
                    v-for="(mensaje, i) in erroresGenerales"
                    :key="i"
                    :message="mensaje"
                />

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        variant="success"
                        :disabled="form.processing || !completo"
                    >
                        <Loader2
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />
                        Autorizar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
