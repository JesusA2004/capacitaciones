<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Download,
    Eye,
    FileUp,
    Pencil,
    Plus,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
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
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { vistaPreviaPost } from '@/lib/http';
import rutasBajas from '@/routes/rh/bajas';
import rutasFiniquitos from '@/routes/rh/bajas/finiquitos';
import type { DetalleFiniquitoBaja } from '@/types';

/**
 * Finiquito de una baja, igual en el expediente y en la pestaña «Finiquitos»
 * (es el mismo registro). «Corregir importes» edita todo de una vez, regenera
 * el PDF con los datos nuevos sin guardar y, al confirmar, aplica los cambios
 * con un solo motivo. Montos y reglas los decide FiniquitoService.
 */
const props = withDefaults(
    defineProps<{
        finiquito: DetalleFiniquitoBaja;
        formasPago: { value: string; label: string }[];
        enlaceExpediente?: boolean;
    }>(),
    { enlaceExpediente: true },
);

const formateador = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
});
const moneda = (valor: number): string => formateador.format(valor);

const etapas: Record<string, string> = {
    calculado: 'Calculado',
    revisado: 'Revisado',
    firmado: 'Firmado',
    pagado: 'Pagado',
};
const tonos: Record<string, 'alerta' | 'info' | 'primario' | 'exito'> = {
    calculado: 'alerta',
    revisado: 'info',
    firmado: 'primario',
    pagado: 'exito',
};

// ── Acciones directas ──
const procesando = ref(false);

function accion(url: string) {
    procesando.value = true;
    router.post(
        url,
        {},
        { preserveScroll: true, onFinish: () => (procesando.value = false) },
    );
}

function subirFirmado(evento: Event) {
    const entrada = evento.target as HTMLInputElement;
    const archivo = entrada.files?.[0];
    entrada.value = '';

    if (!archivo) {
        return;
    }

    procesando.value = true;
    router.post(
        rutasFiniquitos.firmado.url(props.finiquito.id),
        { archivo },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => (procesando.value = false),
        },
    );
}

// ── Corregir importes con vista previa ──
type Renglon = DetalleFiniquitoBaja['desglose'][number] & { valor: string };
type Nuevo = { concepto: string; tipo: string; importe: string };

const corrigiendo = ref(false);
const renglones = ref<Renglon[]>([]);
const nuevos = ref<Nuevo[]>([]);
const eliminados = ref<number[]>([]);
const formCorreccion = useForm<{ motivo: string; cambios?: string }>({
    motivo: '',
});
const urlPrevia = ref<string | null>(null);
const cargandoPrevia = ref(false);
const errorPrevia = ref<string | null>(null);

const totalEditado = computed(() => {
    const signo = (tipo: string) => (tipo === 'deduccion' ? -1 : 1);
    const existentes = renglones.value.reduce(
        (suma, r) => suma + signo(r.tipo) * (Number(r.valor) || 0),
        0,
    );

    return nuevos.value.reduce(
        (suma, n) => suma + signo(n.tipo) * (Number(n.importe) || 0),
        existentes,
    );
});

function cuerpoCambios() {
    const cambiados = renglones.value.filter(
        (r) => Math.abs((Number(r.valor) || 0) - r.importe) >= 0.005,
    );

    return {
        conceptos: cambiados
            .filter((r) => r.concepto_clave)
            .map((r) => ({
                concepto_clave: r.concepto_clave,
                importe: Number(r.valor) || 0,
            })),
        manuales: cambiados
            .filter((r) => r.id !== null)
            .map((r) => ({ id: r.id, importe: Number(r.valor) || 0 })),
        nuevos: nuevos.value.map((n) => ({
            concepto: n.concepto,
            tipo: n.tipo,
            importe: Number(n.importe) || 0,
        })),
        eliminados: eliminados.value,
    };
}

function revocarPrevia() {
    if (urlPrevia.value) {
        URL.revokeObjectURL(urlPrevia.value);
        urlPrevia.value = null;
    }
}

async function regenerarPrevia() {
    cargandoPrevia.value = true;
    errorPrevia.value = null;

    try {
        const url = await vistaPreviaPost(
            rutasFiniquitos.vistaPrevia.url(props.finiquito.id),
            cuerpoCambios(),
        );
        revocarPrevia();
        urlPrevia.value = url;
    } catch (e) {
        errorPrevia.value =
            e instanceof Error
                ? e.message
                : 'No se pudo generar la vista previa.';
    } finally {
        cargandoPrevia.value = false;
    }
}

function abrirCorreccion() {
    renglones.value = props.finiquito.desglose.map((r) => ({
        ...r,
        valor: r.importe.toFixed(2),
    }));
    nuevos.value = [];
    eliminados.value = [];
    formCorreccion.reset();
    formCorreccion.clearErrors();
    corrigiendo.value = true;
    void regenerarPrevia();
}

function quitarManual(r: Renglon) {
    if (r.id !== null) {
        eliminados.value.push(r.id);
    }

    renglones.value = renglones.value.filter((x) => x !== r);
}

function guardarCorreccion() {
    formCorreccion
        .transform((d) => ({ motivo: d.motivo, ...cuerpoCambios() }))
        .post(rutasFiniquitos.corregir.url(props.finiquito.id), {
            preserveScroll: true,
            onSuccess: () => (corrigiendo.value = false),
        });
}

function alCerrar(abierto: boolean) {
    corrigiendo.value = abierto;

    if (!abierto) {
        revocarPrevia();
    }
}

onBeforeUnmount(revocarPrevia);

const erroresCorreccion = computed(() =>
    Object.values(formCorreccion.errors as Record<string, string>),
);

// ── Cambio de tipo ──
const tipoAbierto = ref(false);
const formTipo = useForm({ tipo: '', motivo: '' });

function abrirTipo() {
    formTipo.reset();
    formTipo.clearErrors();
    formTipo.tipo =
        props.finiquito.tipo === 'liquidacion' ? 'finiquito' : 'liquidacion';
    tipoAbierto.value = true;
}

function guardarTipo() {
    formTipo.post(rutasFiniquitos.tipo.url(props.finiquito.id), {
        preserveScroll: true,
        onSuccess: () => (tipoAbierto.value = false),
    });
}

// ── Registrar pago ──
const pagoAbierto = ref(false);
const formPago = useForm({ fecha: '', forma_pago: '', comentario: '' });

function guardarPago() {
    formPago
        .transform((d) => ({ ...d, forma_pago: d.forma_pago || null }))
        .post(rutasFiniquitos.pago.url(props.finiquito.id), {
            preserveScroll: true,
            onSuccess: () => (pagoAbierto.value = false),
        });
}
</script>

<template>
    <div class="grid gap-4 xl:grid-cols-[3fr_2fr]">
        <section
            class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-card p-5 shadow-sm"
            aria-label="Finiquito"
        >
            <header class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold">
                        {{ finiquito.tipo_etiqueta }} ·
                        {{ finiquito.colaborador }}
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        {{ finiquito.forma_etiqueta ?? '—' }} · ingreso
                        {{ formatearFecha(finiquito.fecha_ingreso) }} · último
                        día {{ formatearFecha(finiquito.fecha_baja) }} · salario
                        diario {{ moneda(finiquito.sueldo_diario) }}
                    </p>
                </div>
                <ChipEstado
                    :etiqueta="etapas[finiquito.estado_flujo] ?? ''"
                    :tono="tonos[finiquito.estado_flujo] ?? 'alerta'"
                />
            </header>

            <ul class="divide-y divide-border/60">
                <li
                    v-for="r in finiquito.desglose"
                    :key="r.concepto_clave ?? `${r.clave}-${r.concepto}`"
                    class="flex items-center justify-between gap-3 py-2 text-sm"
                >
                    <div class="min-w-0">
                        <p>{{ r.concepto }}</p>
                        <p
                            v-if="r.ajustado"
                            class="text-xs text-muted-foreground"
                        >
                            Calculado: {{ moneda(r.valor_calculado ?? 0) }}
                        </p>
                    </div>
                    <span class="font-medium tabular-nums">
                        {{ r.tipo === 'deduccion' ? '−' : ''
                        }}{{ moneda(r.importe) }}
                    </span>
                </li>
            </ul>

            <p class="flex items-center justify-end gap-3 text-sm">
                <span class="font-medium">Total</span>
                <span class="text-lg font-bold tabular-nums">{{
                    moneda(finiquito.monto)
                }}</span>
            </p>

            <div class="flex flex-wrap justify-end gap-2">
                <Button
                    v-if="finiquito.puede.editar"
                    variant="outline"
                    @click="abrirCorreccion"
                    ><Pencil class="size-4" />Corregir importes</Button
                >
                <Button
                    v-if="finiquito.puede.recalcular"
                    variant="outline"
                    :disabled="procesando"
                    @click="
                        accion(rutasFiniquitos.recalcular.url(finiquito.id))
                    "
                    ><RotateCcw class="size-4" />Recalcular</Button
                >
                <Button
                    v-if="finiquito.puede.revisar"
                    variant="outline"
                    :disabled="procesando"
                    @click="accion(rutasFiniquitos.revisar.url(finiquito.id))"
                    ><CheckCircle2 class="size-4" />Marcar como revisado</Button
                >
                <Button v-if="finiquito.puede.recibo" as-child>
                    <a
                        :href="rutasFiniquitos.recibo.url(finiquito.id)"
                        target="_blank"
                        ><Download class="size-4" />Recibo de
                        {{ finiquito.tipo_etiqueta.toLowerCase() }} (PDF)</a
                    >
                </Button>
                <label
                    v-if="finiquito.puede.subir_firmado"
                    class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-xl bg-[var(--mrl-dorado)] px-3 text-sm font-medium text-white hover:opacity-90"
                >
                    <FileUp class="size-4" />Subir firmado
                    <input
                        type="file"
                        class="sr-only"
                        accept=".pdf,.jpg,.jpeg,.png"
                        :disabled="procesando"
                        @change="subirFirmado"
                    />
                </label>
                <Button
                    v-if="finiquito.puede.registrar_pago"
                    class="bg-success text-white hover:bg-success/90"
                    @click="pagoAbierto = true"
                    >Registrar pago</Button
                >
            </div>
        </section>

        <div class="flex flex-col gap-4">
            <section
                class="flex flex-col gap-2 rounded-2xl border border-[var(--mrl-dorado)]/40 bg-crema/40 p-4 text-sm"
            >
                <h3 class="text-base font-semibold">
                    ¿Finiquito o liquidación?
                </h3>
                <p>
                    Se propone con la causal: las marcadas como
                    <strong>«Implica indemnización»</strong> generan una
                    <strong>liquidación</strong>.
                </p>
                <p
                    v-if="finiquito.advertencia_liquidacion"
                    class="rounded-xl bg-warning-soft px-3 py-2 text-xs text-warning"
                >
                    {{ finiquito.advertencia_liquidacion }}
                </p>
                <p v-if="finiquito.causal" class="text-xs">
                    Causal: {{ finiquito.causal }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="finiquito.puede.cambiar_tipo"
                        size="sm"
                        variant="outline"
                        @click="abrirTipo"
                        >Cambiar a
                        {{
                            finiquito.tipo === 'liquidacion'
                                ? 'finiquito'
                                : 'liquidación'
                        }}</Button
                    >
                    <Button
                        v-if="enlaceExpediente && finiquito.baja_id"
                        size="sm"
                        variant="ghost"
                        as-child
                    >
                        <Link :href="rutasBajas.show.url(finiquito.baja_id)"
                            >Ver expediente de la baja</Link
                        >
                    </Button>
                </div>
            </section>

            <section
                class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 text-sm shadow-sm"
            >
                <h3 class="text-base font-semibold">Bitácora de ajustes</h3>
                <p
                    v-if="!finiquito.historial_ajustes.length"
                    class="text-xs text-muted-foreground"
                >
                    Sin ajustes: los importes son los calculados.
                </p>
                <ol v-else class="grid gap-2">
                    <li
                        v-for="(a, i) in finiquito.historial_ajustes"
                        :key="i"
                        class="border-l-2 border-border pl-3"
                    >
                        <p class="font-medium">{{ a.concepto }}</p>
                        <p class="text-xs tabular-nums">
                            {{ moneda(a.anterior) }} →
                            {{ moneda(a.actualizado) }}
                        </p>
                        <p class="text-xs italic">«{{ a.motivo }}»</p>
                        <p class="text-xs text-muted-foreground">
                            {{ a.usuario ?? 'Sistema' }} ·
                            {{ formatearFechaHora(a.fecha) }}
                        </p>
                    </li>
                </ol>
            </section>
        </div>
    </div>

    <Dialog :open="corrigiendo" @update:open="alCerrar">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-6xl">
            <DialogHeader>
                <DialogTitle
                    >Corregir {{ finiquito.tipo_etiqueta.toLowerCase() }} ·
                    {{ finiquito.colaborador }}</DialogTitle
                >
                <DialogDescription
                    >Edita los importes y actualiza la vista previa. Nada se
                    guarda hasta confirmar; el valor calculado se conserva y el
                    finiquito regresa a «Calculado» para
                    revisarse.</DialogDescription
                >
            </DialogHeader>

            <div class="grid gap-5 lg:grid-cols-2">
                <form
                    class="flex flex-col gap-3"
                    @submit.prevent="guardarCorreccion"
                >
                    <ul class="divide-y divide-border/60">
                        <li
                            v-for="r in renglones"
                            :key="
                                r.concepto_clave ?? `${r.clave}-${r.concepto}`
                            "
                            class="flex items-center justify-between gap-3 py-2 text-sm"
                        >
                            <div class="min-w-0">
                                <p>{{ r.concepto }}</p>
                                <p
                                    v-if="r.valor_calculado !== null"
                                    class="text-xs text-muted-foreground"
                                >
                                    Calculado: {{ moneda(r.valor_calculado) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-1">
                                <Input
                                    v-model="r.valor"
                                    class="w-32 text-right tabular-nums"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :disabled="!r.editable"
                                    :aria-label="`Importe de ${r.concepto}`"
                                />
                                <Button
                                    v-if="r.id !== null"
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Quitar ${r.concepto}`"
                                    @click="quitarManual(r)"
                                    ><Trash2 class="size-4"
                                /></Button>
                            </div>
                        </li>
                    </ul>

                    <div
                        v-for="(n, i) in nuevos"
                        :key="i"
                        class="grid grid-cols-[1fr_8rem_7rem_auto] items-center gap-2"
                    >
                        <Input
                            v-model="n.concepto"
                            placeholder="Concepto"
                            :aria-label="`Nombre del concepto nuevo ${i + 1}`"
                        />
                        <SelectSimple
                            v-model="n.tipo"
                            :opciones="[
                                { value: 'percepcion', label: 'Percepción' },
                                { value: 'deduccion', label: 'Deducción' },
                            ]"
                        />
                        <Input
                            v-model="n.importe"
                            class="text-right tabular-nums"
                            type="number"
                            step="0.01"
                            min="0"
                            :aria-label="`Importe del concepto nuevo ${i + 1}`"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Quitar concepto nuevo"
                            @click="nuevos.splice(i, 1)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="w-fit"
                        @click="
                            nuevos.push({
                                concepto: '',
                                tipo: 'percepcion',
                                importe: '',
                            })
                        "
                        ><Plus class="size-4" />Agregar concepto</Button
                    >

                    <p class="flex items-center justify-end gap-3 text-sm">
                        <span class="font-medium">Total</span>
                        <span class="text-lg font-bold tabular-nums">{{
                            moneda(totalEditado)
                        }}</span>
                    </p>

                    <div class="grid gap-1.5">
                        <Label for="correccion-motivo"
                            >Motivo de la corrección</Label
                        >
                        <Textarea
                            id="correccion-motivo"
                            v-model="formCorreccion.motivo"
                            rows="2"
                        />
                    </div>
                    <div
                        v-if="erroresCorreccion.length"
                        class="rounded-xl bg-danger-soft px-3 py-2 text-xs text-destructive"
                        role="alert"
                    >
                        <p v-for="(e, i) in erroresCorreccion" :key="i">
                            {{ e }}
                        </p>
                    </div>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="cargandoPrevia"
                            @click="regenerarPrevia"
                            ><Eye class="size-4" />Actualizar vista
                            previa</Button
                        >
                        <Button
                            type="submit"
                            :disabled="formCorreccion.processing"
                            >Guardar correcciones</Button
                        >
                    </DialogFooter>
                </form>

                <div class="flex min-h-[28rem] flex-col gap-2">
                    <p
                        v-if="errorPrevia"
                        class="rounded-xl bg-danger-soft px-3 py-2 text-xs text-destructive"
                        role="alert"
                    >
                        {{ errorPrevia }}
                    </p>
                    <iframe
                        v-if="urlPrevia"
                        :src="urlPrevia"
                        title="Vista previa del finiquito corregido"
                        class="h-[70vh] w-full flex-1 rounded-xl border border-border/60"
                    />
                    <p
                        v-else
                        class="flex flex-1 items-center justify-center rounded-xl bg-muted text-sm text-muted-foreground"
                    >
                        {{
                            cargandoPrevia
                                ? 'Generando vista previa…'
                                : 'Sin vista previa'
                        }}
                    </p>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="tipoAbierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Cambiar el tipo del documento</DialogTitle>
                <DialogDescription
                    >Es una decisión de RH/Jurídico para este caso. No cambia
                    los montos: la indemnización se corrige por concepto y el
                    finiquito regresa a «Calculado».</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="guardarTipo">
                <Label for="tipo-motivo">Motivo</Label>
                <Textarea id="tipo-motivo" v-model="formTipo.motivo" rows="3" />
                <InputError :message="formTipo.errors.motivo" />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="tipoAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="formTipo.processing"
                        >Cambiar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="pagoAbierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Registrar pago</DialogTitle>
                <DialogDescription
                    >El sistema no dispersa pagos: deja constancia de que se
                    realizó. Después el finiquito es de solo lectura y, si la
                    baja laboral sigue pendiente, se ejecuta.</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="guardarPago">
                <div class="grid gap-1.5">
                    <Label for="pago-fecha">Fecha de pago</Label>
                    <DatePicker id="pago-fecha" v-model="formPago.fecha" />
                    <InputError :message="formPago.errors.fecha" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="pago-forma">Forma de pago</Label>
                    <SelectSimple
                        id="pago-forma"
                        v-model="formPago.forma_pago"
                        :opciones="formasPago"
                        opcion-vacia="Sin especificar"
                        placeholder="Sin especificar"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="pago-comentario">Comentario (opcional)</Label>
                    <Textarea
                        id="pago-comentario"
                        v-model="formPago.comentario"
                        rows="2"
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="pagoAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="formPago.processing"
                        >Registrar pago</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
