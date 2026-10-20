<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    CalendarRange,
    CheckCircle2,
    FileSignature,
    HandCoins,
    Loader2,
} from '@lucide/vue';
import { computed } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
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

// Campos obligatorios para autorizar (los mismos que valida el backend).
const faltantes = computed(
    () =>
        [
            form.monto_autorizado,
            form.plazo_autorizado,
            form.cargo_sin_iva,
            form.iva,
            form.monto_total_con_iva,
            form.fecha_primer_descuento,
            form.fecha_ultimo_pago,
        ].filter((v) => String(v).trim() === '').length,
);

const completo = computed(() => faltantes.value === 0);

const montoSolicitadoTexto = computed(() => {
    const n = Number(props.montoSolicitado);

    return props.montoSolicitado && !Number.isNaN(n)
        ? n.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })
        : null;
});

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
        <DialogContent
            class="flex max-h-[100svh] flex-col gap-0 overflow-hidden p-0 max-sm:h-[100svh] max-sm:max-w-none max-sm:rounded-none sm:max-h-[92vh] sm:max-w-2xl"
        >
            <DialogHeader
                class="border-b border-[var(--mrl-borde)] px-4 pt-4 pb-3 text-left sm:px-6 sm:pt-5"
            >
                <div class="flex items-center gap-3 pr-8">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-success-soft text-success"
                        aria-hidden="true"
                    >
                        <HandCoins class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <DialogTitle class="flex items-center gap-1.5 text-lg">
                            Autorizar préstamo
                            <AyudaBoton
                                titulo="Autorizar préstamo"
                                texto="Captura los datos tal como van en el contrato de crédito. No se calcula nada: lo que escribas es lo que se imprime. Antes de autorizar puedes ver el contrato, el pagaré y la carta de retención."
                            />
                        </DialogTitle>
                        <DialogDescription class="text-sm">
                            Lo que escribas es lo que se imprime.
                        </DialogDescription>
                    </div>
                    <div
                        v-if="montoSolicitadoTexto"
                        class="hidden shrink-0 flex-col items-end leading-tight min-[420px]:flex"
                    >
                        <span class="text-[11px] text-muted-foreground"
                            >Solicitado</span
                        >
                        <span
                            class="text-lg font-bold text-primary tabular-nums"
                            >{{ montoSolicitadoTexto }}</span
                        >
                    </div>
                </div>
            </DialogHeader>

            <form
                id="form-autorizar-prestamo"
                class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 py-4 sm:px-6"
                @submit.prevent="confirmar"
            >
                <!-- 1. Préstamo -->
                <section class="flex flex-col gap-3">
                    <h3
                        class="flex items-center gap-2 text-sm font-semibold text-[var(--mrl-texto)]"
                    >
                        <HandCoins class="size-4 text-bronce" />
                        Préstamo
                    </h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="pr-monto">Monto autorizado</Label>
                            <div class="relative">
                                <span
                                    class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                    >$</span
                                >
                                <Input
                                    id="pr-monto"
                                    v-model="form.monto_autorizado"
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="h-12 pl-7 text-lg font-semibold tabular-nums"
                                    required
                                />
                            </div>
                            <InputError
                                :message="form.errors.monto_autorizado"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="pr-plazo">Número de pagos</Label>
                            <Input
                                id="pr-plazo"
                                v-model="form.plazo_autorizado"
                                type="number"
                                min="1"
                                step="1"
                                inputmode="numeric"
                                class="h-12 text-lg font-semibold tabular-nums"
                                required
                            />
                            <InputError
                                :message="form.errors.plazo_autorizado"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label id="pr-periodicidad">Periodicidad</Label>
                        <div
                            class="grid grid-cols-3 gap-1 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-1"
                            role="radiogroup"
                            aria-labelledby="pr-periodicidad"
                        >
                            <button
                                v-for="p in periodicidades"
                                :key="p.valor"
                                type="button"
                                role="radio"
                                :aria-checked="form.periodicidad === p.valor"
                                class="min-h-10 rounded-lg text-sm font-medium transition-colors"
                                :class="
                                    form.periodicidad === p.valor
                                        ? 'bg-[var(--mrl-superficie)] text-primary shadow-sm'
                                        : 'text-muted-foreground hover:text-[var(--mrl-texto)]'
                                "
                                @click="form.periodicidad = p.valor"
                            >
                                {{ p.etiqueta }}
                            </button>
                        </div>
                        <InputError :message="form.errors.periodicidad" />
                    </div>
                </section>

                <!-- 2. Contrato de crédito -->
                <section
                    class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-crema/50 p-3 sm:p-4"
                >
                    <h3
                        class="flex items-center gap-2 text-sm font-semibold text-[var(--mrl-texto)]"
                    >
                        <FileSignature class="size-4 text-bronce" />
                        Contrato de crédito
                    </h3>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label for="pr-cargo">Cargo (sin I.V.A.)</Label>
                            <div class="relative">
                                <span
                                    class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                    >$</span
                                >
                                <Input
                                    id="pr-cargo"
                                    v-model="form.cargo_sin_iva"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="h-11 pl-7 tabular-nums"
                                    required
                                />
                            </div>
                            <InputError :message="form.errors.cargo_sin_iva" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="pr-iva">I.V.A.</Label>
                            <div class="relative">
                                <span
                                    class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                    >$</span
                                >
                                <Input
                                    id="pr-iva"
                                    v-model="form.iva"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="h-11 pl-7 tabular-nums"
                                    required
                                />
                            </div>
                            <InputError :message="form.errors.iva" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="pr-total">Total (con I.V.A.)</Label>
                            <div class="relative">
                                <span
                                    class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                    >$</span
                                >
                                <Input
                                    id="pr-total"
                                    v-model="form.monto_total_con_iva"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="h-11 pl-7 tabular-nums"
                                    required
                                />
                            </div>
                            <InputError
                                :message="form.errors.monto_total_con_iva"
                            />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
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
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="pr-pago">Pago por periodo</Label>
                            <div class="relative">
                                <span
                                    class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                    >$</span
                                >
                                <Input
                                    id="pr-pago"
                                    v-model="form.pago_programado"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    placeholder="Opcional"
                                    class="h-11 pl-7 tabular-nums"
                                />
                            </div>
                            <InputError
                                :message="form.errors.pago_programado"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="pr-cheque">No. de cheque</Label>
                            <Input
                                id="pr-cheque"
                                v-model="form.numero_cheque"
                                maxlength="40"
                                placeholder="Opcional"
                                class="h-11"
                            />
                            <InputError :message="form.errors.numero_cheque" />
                        </div>
                    </div>
                </section>

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
                    etiqueta="Ver documentos"
                />
                <InputError
                    v-for="(mensaje, i) in erroresGenerales"
                    :key="i"
                    :message="mensaje"
                />
            </form>

            <!-- Pie fijo: siempre a la vista, también en móvil -->
            <DialogFooter
                class="grid grid-cols-2 gap-2 border-t border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-4 py-3 sm:flex sm:flex-row sm:items-center sm:px-6"
            >
                <p
                    class="col-span-2 flex items-center gap-1.5 text-sm sm:mr-auto"
                    :class="completo ? 'text-success' : 'text-muted-foreground'"
                    role="status"
                >
                    <CheckCircle2 v-if="completo" class="size-4" />
                    <CalendarRange v-else class="size-4" />
                    {{
                        completo
                            ? 'Listo para autorizar'
                            : `Faltan ${faltantes} ${faltantes === 1 ? 'dato' : 'datos'}`
                    }}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    class="h-11"
                    :disabled="form.processing"
                    @click="emit('update:open', false)"
                >
                    Cancelar
                </Button>
                <Button
                    type="submit"
                    form="form-autorizar-prestamo"
                    variant="success"
                    size="lg"
                    class="h-11"
                    :disabled="form.processing || !completo"
                >
                    <Loader2
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    <HandCoins v-else class="size-4" />
                    Autorizar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
