<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Eye, PackageCheck, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { entregarFiniquito } from '@/routes/rh/cierres';
import { ajustar, vistaPrevia } from '@/routes/rh/cierres/finiquito';
import {
    destroy as eliminarConceptoRuta,
    store as agregarConceptoRuta,
    update as actualizarConceptoRuta,
} from '@/routes/rh/cierres/finiquito/conceptos';
import type { FiniquitoCierre, FiniquitoDesgloseItem } from '@/types';

/**
 * Vista previa y edición del finiquito dentro del Cierre laboral. RH ve
 * cada concepto (sueldo pendiente, aguinaldo, vacaciones, prima, ISR…),
 * ajusta montos con motivo, agrega o quita renglones y registra la ENTREGA,
 * que congela el finiquito y cierra el cierre. El total SIEMPRE lo
 * recalcula el servidor (FiniquitoService::recalcularTotales()).
 */
const props = defineProps<{
    open: boolean;
    cierreId: number;
    colaborador: string;
    finiquito: FiniquitoCierre | null;
    puedeEditar: boolean;
    puedeEntregar: boolean;
    /** Abrir directo en «Registrar entrega de finiquito». */
    entregaInicial?: boolean;
}>();

const emit = defineEmits<{ 'update:open': [abierto: boolean] }>();

const hoy = new Date().toISOString().slice(0, 10);
const MONTO = /^\d{1,7}(\.\d{1,2})?$/;

function moneda(valor: string | number | null | undefined): string {
    return `$${Number(valor ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const editable = computed(
    () => props.puedeEditar && (props.finiquito?.editable ?? false),
);
const percepciones = computed(() =>
    (props.finiquito?.desglose ?? []).filter((f) => f.tipo !== 'deduccion'),
);
const deducciones = computed(() =>
    (props.finiquito?.desglose ?? []).filter((f) => f.tipo === 'deduccion'),
);

// ───── Editar el monto de un renglón ─────
// Automático (001 sueldo pendiente, 002 aguinaldo…): ajuste con motivo, que
// conserva el valor calculado. Renglón propio de RH: se edita directo.
const editando = ref<string | null>(null);
const formMonto = useForm({ importe: '', motivo: '' });

function claveFila(fila: FiniquitoDesgloseItem): string {
    return fila.id !== null ? `m-${fila.id}` : `a-${fila.concepto_clave}`;
}

function editar(fila: FiniquitoDesgloseItem) {
    editando.value = claveFila(fila);
    formMonto.clearErrors();
    formMonto.importe = Number(fila.importe).toFixed(2);
    formMonto.motivo = fila.id !== null ? (fila.observaciones ?? '') : '';
}

const montoValido = computed(
    () =>
        MONTO.test(formMonto.importe.trim()) &&
        formMonto.motivo.trim().length >= 3,
);

function guardarMonto(fila: FiniquitoDesgloseItem) {
    if (!montoValido.value) {
        return;
    }

    const opciones = {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    };

    if (fila.id !== null) {
        formMonto
            .transform((d) => ({
                importe: d.importe.trim(),
                observaciones: d.motivo.trim(),
            }))
            .patch(
                actualizarConceptoRuta.url([props.cierreId, fila.id]),
                opciones,
            );

        return;
    }

    formMonto
        .transform((d) => ({
            concepto_clave: fila.concepto_clave,
            importe: d.importe.trim(),
            motivo: d.motivo.trim(),
        }))
        .post(ajustar.url(props.cierreId), opciones);
}

function eliminar(fila: FiniquitoDesgloseItem) {
    if (fila.id === null) {
        return;
    }

    router.delete(eliminarConceptoRuta.url([props.cierreId, fila.id]), {
        preserveScroll: true,
    });
}

// ───── Agregar un renglón (percepción o deducción) ─────
const agregando = ref(false);
const formNuevo = useForm({
    tipo: 'percepcion',
    concepto: '',
    importe: '',
    observaciones: '',
});
const tipos = [
    { value: 'percepcion', label: 'Percepción' },
    { value: 'deduccion', label: 'Deducción' },
];
const nuevoValido = computed(
    () =>
        formNuevo.concepto.trim() !== '' &&
        MONTO.test(formNuevo.importe.trim()) &&
        formNuevo.observaciones.trim().length >= 3,
);

function abrirNuevo() {
    formNuevo.reset();
    formNuevo.clearErrors();
    agregando.value = true;
}

function guardarNuevo() {
    if (!nuevoValido.value) {
        return;
    }

    formNuevo.post(agregarConceptoRuta.url(props.cierreId), {
        preserveScroll: true,
        onSuccess: () => (agregando.value = false),
    });
}

// ───── Registrar entrega (cierra el cierre laboral) ─────
const entregando = ref(false);
const formEntrega = useForm({
    fecha: hoy,
    forma_pago: 'transferencia',
    comentario: '',
});
const formasPago = [
    { value: 'transferencia', label: 'Transferencia' },
    { value: 'efectivo', label: 'Efectivo' },
    { value: 'cheque', label: 'Cheque' },
];

function registrarEntrega() {
    formEntrega.post(entregarFiniquito.url(props.cierreId), {
        preserveScroll: true,
        onSuccess: () => {
            entregando.value = false;
        },
    });
}

watch(
    () => props.open,
    (abierto) => {
        if (abierto) {
            editando.value = null;
            agregando.value = false;
            entregando.value = Boolean(props.entregaInicial) && editable.value;
            formEntrega.reset();
            formEntrega.clearErrors();
        }
    },
    { immediate: true },
);

const urlVistaPrevia = computed(() => vistaPrevia.url(props.cierreId));

// El servidor también responde errores de reglas de negocio (estado,
// checklist, finiquito…) que no son campos del formulario.
type Errores = Record<string, string | undefined>;
const erroresMonto = computed(() => formMonto.errors as Errores);
const erroresNuevo = computed(() => formNuevo.errors as Errores);
const erroresEntrega = computed(() => formEntrega.errors as Errores);
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(abierto: boolean) => emit('update:open', abierto)"
    >
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Finiquito · {{ colaborador }}</DialogTitle>
                <DialogDescription v-if="finiquito">
                    {{ finiquito.estado_etiqueta }} · neto
                    <strong class="text-foreground">{{
                        moneda(finiquito.neto)
                    }}</strong>
                </DialogDescription>
                <DialogDescription v-else>
                    Todavía no se ha calculado el finiquito.
                </DialogDescription>
            </DialogHeader>

            <template v-if="finiquito">
                <div
                    v-if="finiquito.entrega"
                    class="rounded-xl bg-success-soft p-3 text-sm"
                    data-testid="finiquito-entregado"
                >
                    <p class="font-semibold">
                        Finiquito entregado el
                        {{ formatearFecha(finiquito.entrega.fecha) }}
                        <template v-if="finiquito.entrega.forma_pago">
                            · {{ finiquito.entrega.forma_pago }}</template
                        >
                    </p>
                    <p v-if="finiquito.entrega.comentario" class="text-xs">
                        {{ finiquito.entrega.comentario }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Quedó congelado tal como se entregó: solo lectura.
                    </p>
                </div>

                <dl
                    class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-xl bg-crema p-3 text-sm sm:grid-cols-4"
                >
                    <div>
                        <dt class="text-xs text-muted-foreground">Ingreso</dt>
                        <dd>{{ formatearFecha(finiquito.fecha_ingreso) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Baja efectiva
                        </dt>
                        <dd>{{ formatearFecha(finiquito.fecha_baja) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Antigüedad
                        </dt>
                        <dd>
                            {{ finiquito.antiguedad_anios }} año(s),
                            {{ finiquito.antiguedad_meses }} mes(es)
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Salario diario
                        </dt>
                        <dd>{{ moneda(finiquito.sueldo_diario) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Sueldo mensual
                        </dt>
                        <dd>{{ moneda(finiquito.sueldo_mensual) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Días trabajados en el año (aguinaldo)
                        </dt>
                        <dd>
                            {{
                                finiquito.dias_trabajados_anio ??
                                finiquito.dias_trabajados_periodo
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Vacaciones
                        </dt>
                        <dd>
                            {{ finiquito.vacaciones_pendientes }} pendiente(s)
                            <span
                                v-if="finiquito.vacaciones_proporcionales > 0"
                                class="block text-xs text-muted-foreground"
                                >+
                                {{
                                    finiquito.vacaciones_proporcionales.toFixed(
                                        2,
                                    )
                                }}
                                proporcionales del año en curso</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Calculado</dt>
                        <dd>
                            {{ formatearFecha(finiquito.fecha_calculo) }}
                            <span
                                v-if="finiquito.calculado_por"
                                class="block text-xs text-muted-foreground"
                                >{{ finiquito.calculado_por }}</span
                            >
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="finiquito.ultima_edicion"
                    class="text-xs text-muted-foreground"
                    data-testid="finiquito-ultima-edicion"
                >
                    Última edición: {{ finiquito.ultima_edicion.accion }}
                    <template v-if="finiquito.ultima_edicion.usuario">
                        · {{ finiquito.ultima_edicion.usuario }}</template
                    >
                    · {{ formatearFechaHora(finiquito.ultima_edicion.fecha) }}
                </p>

                <div
                    v-for="grupo in [
                        {
                            titulo: 'Percepciones',
                            filas: percepciones,
                            total: finiquito.total_percepciones,
                        },
                        {
                            titulo: 'Deducciones',
                            filas: deducciones,
                            total: finiquito.total_deducciones,
                        },
                    ]"
                    :key="grupo.titulo"
                    class="overflow-x-auto"
                >
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <th class="w-12 py-1.5 font-medium">Clave</th>
                                <th class="py-1.5 font-medium">
                                    {{ grupo.titulo }}
                                </th>
                                <th class="w-16 py-1.5 text-right font-medium">
                                    Días
                                </th>
                                <th class="w-32 py-1.5 text-right font-medium">
                                    Importe
                                </th>
                                <th v-if="editable" class="w-20" />
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="fila in grupo.filas"
                                :key="claveFila(fila)"
                            >
                                <tr class="border-b border-border/50 align-top">
                                    <td
                                        class="py-2 text-xs text-muted-foreground"
                                    >
                                        {{ fila.clave }}
                                    </td>
                                    <td class="py-2">
                                        {{ fila.concepto }}
                                        <span
                                            v-if="fila.ajuste"
                                            class="block text-xs text-[var(--mrl-texto-suave)]"
                                            >Ajustado: calculado
                                            {{
                                                moneda(
                                                    fila.ajuste.valor_calculado,
                                                )
                                            }}
                                            →
                                            {{
                                                moneda(fila.ajuste.valor_final)
                                            }}
                                            · {{ fila.ajuste.motivo
                                            }}<template
                                                v-if="fila.ajuste.usuario"
                                            >
                                                ·
                                                {{
                                                    fila.ajuste.usuario
                                                }}</template
                                            ><template v-if="fila.ajuste.fecha">
                                                ·
                                                {{
                                                    formatearFechaHora(
                                                        fila.ajuste.fecha,
                                                    )
                                                }}</template
                                            ></span
                                        >
                                        <span
                                            v-else-if="fila.observaciones"
                                            class="block text-xs text-[var(--mrl-texto-suave)]"
                                            >{{ fila.observaciones }}</span
                                        >
                                    </td>
                                    <td class="py-2 text-right tabular-nums">
                                        {{ fila.dias ?? '' }}
                                    </td>
                                    <td
                                        class="py-2 text-right font-medium tabular-nums"
                                    >
                                        {{ moneda(fila.importe) }}
                                    </td>
                                    <td v-if="editable" class="py-1 text-right">
                                        <Button
                                            v-if="fila.editable !== false"
                                            size="icon"
                                            variant="ghost"
                                            :aria-label="`Editar ${fila.concepto}`"
                                            @click="editar(fila)"
                                            ><Pencil class="size-4"
                                        /></Button>
                                        <Button
                                            v-if="fila.id !== null"
                                            size="icon"
                                            variant="ghost"
                                            :aria-label="`Quitar ${fila.concepto}`"
                                            @click="eliminar(fila)"
                                            ><Trash2 class="size-4"
                                        /></Button>
                                    </td>
                                </tr>
                                <tr v-if="editando === claveFila(fila)">
                                    <td colspan="5" class="pb-3">
                                        <form
                                            class="grid gap-2 rounded-xl bg-crema p-3 sm:grid-cols-[10rem_1fr_auto]"
                                            @submit.prevent="guardarMonto(fila)"
                                        >
                                            <div class="grid gap-1">
                                                <Label
                                                    :for="`monto-${claveFila(fila)}`"
                                                    >Importe</Label
                                                >
                                                <Input
                                                    :id="`monto-${claveFila(fila)}`"
                                                    v-model="formMonto.importe"
                                                    inputmode="decimal"
                                                    placeholder="0.00"
                                                />
                                                <InputError
                                                    :message="
                                                        formMonto.errors.importe
                                                    "
                                                />
                                            </div>
                                            <div class="grid gap-1">
                                                <Label
                                                    :for="`motivo-${claveFila(fila)}`"
                                                    >Motivo del cambio</Label
                                                >
                                                <Input
                                                    :id="`motivo-${claveFila(fila)}`"
                                                    v-model="formMonto.motivo"
                                                    placeholder="Ej. días pagados en la última nómina"
                                                />
                                                <InputError
                                                    :message="
                                                        formMonto.errors
                                                            .motivo ??
                                                        erroresMonto.observaciones ??
                                                        erroresMonto.estado
                                                    "
                                                />
                                            </div>
                                            <div class="flex items-end gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    @click="editando = null"
                                                    >Cancelar</Button
                                                >
                                                <Button
                                                    type="submit"
                                                    :disabled="
                                                        !montoValido ||
                                                        formMonto.processing
                                                    "
                                                    >Guardar</Button
                                                >
                                            </div>
                                            <p
                                                v-if="
                                                    formMonto.importe !== '' &&
                                                    !MONTO.test(
                                                        formMonto.importe.trim(),
                                                    )
                                                "
                                                class="text-xs text-destructive sm:col-span-3"
                                            >
                                                Escribe un importe con hasta dos
                                                decimales, por ejemplo 1250.50.
                                            </p>
                                        </form>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!grupo.filas.length">
                                <td
                                    colspan="5"
                                    class="py-2 text-xs text-muted-foreground"
                                >
                                    Sin {{ grupo.titulo.toLowerCase() }}.
                                </td>
                            </tr>
                            <tr class="text-sm">
                                <td />
                                <td class="py-2 font-medium">
                                    Total {{ grupo.titulo.toLowerCase() }}
                                </td>
                                <td />
                                <td
                                    class="py-2 text-right font-semibold tabular-nums"
                                >
                                    {{ moneda(grupo.total) }}
                                </td>
                                <td v-if="editable" />
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="flex items-center justify-between rounded-xl bg-salvia px-4 py-3"
                >
                    <span class="text-sm font-semibold">Neto a entregar</span>
                    <span
                        class="text-lg font-bold tabular-nums"
                        data-testid="finiquito-neto"
                        >{{ moneda(finiquito.neto) }}</span
                    >
                </div>

                <form
                    v-if="editable && agregando"
                    class="grid gap-2 rounded-xl border border-border/60 p-3 sm:grid-cols-2"
                    @submit.prevent="guardarNuevo"
                >
                    <div class="grid gap-1">
                        <Label for="nuevo-tipo">Tipo</Label>
                        <SelectSimple
                            id="nuevo-tipo"
                            v-model="formNuevo.tipo"
                            :opciones="tipos"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="nuevo-concepto">Concepto</Label>
                        <Input
                            id="nuevo-concepto"
                            v-model="formNuevo.concepto"
                            placeholder="Ej. Bono de productividad"
                        />
                        <InputError :message="formNuevo.errors.concepto" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="nuevo-importe">Importe</Label>
                        <Input
                            id="nuevo-importe"
                            v-model="formNuevo.importe"
                            inputmode="decimal"
                            placeholder="0.00"
                        />
                        <InputError :message="formNuevo.errors.importe" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="nuevo-motivo">Motivo</Label>
                        <Input
                            id="nuevo-motivo"
                            v-model="formNuevo.observaciones"
                            placeholder="Por qué se agrega"
                        />
                        <InputError
                            :message="
                                formNuevo.errors.observaciones ??
                                erroresNuevo.estado
                            "
                        />
                    </div>
                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="agregando = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            :disabled="!nuevoValido || formNuevo.processing"
                            >Agregar renglón</Button
                        >
                    </div>
                </form>

                <form
                    v-if="editable && puedeEntregar && entregando"
                    class="grid gap-3 rounded-xl border border-[var(--mrl-petroleo)] p-4"
                    data-testid="form-entrega"
                    @submit.prevent="registrarEntrega"
                >
                    <p class="text-sm font-semibold">
                        Registrar entrega de finiquito ·
                        {{ moneda(finiquito.neto) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Al registrarla el finiquito se congela tal como está y
                        el cierre laboral queda completo (baja y expediente
                        cerrados; nada se borra).
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <Label for="entrega-fecha">Fecha de entrega</Label>
                            <DatePicker
                                id="entrega-fecha"
                                v-model="formEntrega.fecha"
                                :max-value="hoy"
                            />
                            <InputError :message="formEntrega.errors.fecha" />
                        </div>
                        <div class="grid gap-1">
                            <Label for="entrega-forma">Forma de pago</Label>
                            <SelectSimple
                                id="entrega-forma"
                                v-model="formEntrega.forma_pago"
                                :opciones="formasPago"
                            />
                            <InputError
                                :message="formEntrega.errors.forma_pago"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1">
                        <Label for="entrega-comentario"
                            >Comentario (opcional)</Label
                        >
                        <Textarea
                            id="entrega-comentario"
                            v-model="formEntrega.comentario"
                            rows="2"
                        />
                        <InputError
                            :message="
                                formEntrega.errors.comentario ??
                                erroresEntrega.checklist ??
                                erroresEntrega.estado ??
                                erroresEntrega.finiquito ??
                                erroresEntrega.fecha_efectiva
                            "
                        />
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="entregando = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                !formEntrega.fecha || formEntrega.processing
                            "
                            ><PackageCheck class="size-4" /> Confirmar entrega y
                            cerrar</Button
                        >
                    </div>
                </form>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Button as-child variant="outline">
                        <a :href="urlVistaPrevia" target="_blank" rel="noopener"
                            ><Eye class="size-4" />
                            {{
                                editable
                                    ? 'Vista previa PDF'
                                    : 'Ver / imprimir PDF'
                            }}</a
                        >
                    </Button>
                    <Button
                        v-if="editable && !agregando"
                        variant="outline"
                        @click="abrirNuevo"
                        ><Plus class="size-4" /> Agregar renglón</Button
                    >
                    <Button
                        v-if="editable && puedeEntregar && !entregando"
                        @click="entregando = true"
                        ><PackageCheck class="size-4" /> Registrar entrega de
                        finiquito</Button
                    >
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
