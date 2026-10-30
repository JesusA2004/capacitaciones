<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Download,
    FileUp,
    Pencil,
    RotateCcw,
    Search,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import PestanasBajas from '@/components/bajas/PestanasBajas.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
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
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { claseForma } from '@/lib/bajas';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import rutasBajas from '@/routes/rh/bajas';
import rutasFiniquitos from '@/routes/rh/bajas/finiquitos';
import type {
    DetalleFiniquitoBaja,
    FilaFiniquitoBaja,
    PestanasBajas as Pestanas,
} from '@/types';

/**
 * Bajas → «Finiquitos» (PDF Etapa 1, F1–F4): localiza y procesa cada finiquito
 * sin entrar al expediente; es el mismo registro que muestra la baja. Montos,
 * estados y permisos los decide el backend (FiniquitoService, Policy).
 */
type Paginador = {
    data: FilaFiniquitoBaja[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    pestanas: Pestanas;
    finiquitos: Paginador;
    resumen: {
        calculados: number;
        revisados: number;
        firmados: number;
        pagados: number;
        monto_pendiente: number;
    };
    seleccionado: DetalleFiniquitoBaja | null;
    filtros: {
        busqueda: string;
        estado: string | null;
        sucursal_id: number | null;
        tipo: string | null;
    };
    sucursales: { value: number; label: string }[];
    formas_pago: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas', href: rutasBajas.index() },
            { title: 'Finiquitos', href: '' },
        ],
    },
});

// Importes de finiquito con centavos: son los que se firman y se pagan.
const formateador = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
});
const moneda = (valor: number): string => formateador.format(valor);

const page = usePage();
const errores = computed(() =>
    Object.values((page.props.errors ?? {}) as Record<string, string>),
);

const busqueda = ref(props.filtros.busqueda);
const estado = ref(props.filtros.estado ?? '');
const sucursal = ref<number | null>(props.filtros.sucursal_id);
const tipo = ref(props.filtros.tipo ?? '');

function parametros(extra: Record<string, unknown> = {}) {
    return {
        ...parametrosPorPaginaActuales(),
        busqueda: busqueda.value || undefined,
        estado: estado.value || undefined,
        sucursal_id: sucursal.value ?? undefined,
        tipo: tipo.value || undefined,
        ...extra,
    };
}

function filtrar() {
    router.get(rutasFiniquitos.index.url(), parametros(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(busqueda, useDebounceFn(filtrar, 300));
watch([estado, sucursal, tipo], filtrar);

function abrir(id: number) {
    router.get(rutasFiniquitos.index.url(), parametros({ finiquito: id }), {
        preserveState: true,
        preserveScroll: true,
        only: ['seleccionado'],
    });
}

const etapas = [
    { clave: 'calculado', etiqueta: 'Calculado' },
    { clave: 'revisado', etiqueta: 'Revisado' },
    { clave: 'firmado', etiqueta: 'Firmado' },
    { clave: 'pagado', etiqueta: 'Pagado' },
];

function etapaAlcanzada(fila: FilaFiniquitoBaja, clave: string): boolean {
    return (
        etapas.findIndex((e) => e.clave === clave) <=
        etapas.findIndex((e) => e.clave === fila.estado_flujo)
    );
}

// ── Acciones sobre el seleccionado ──
const procesando = ref(false);

function accion(url: string) {
    procesando.value = true;
    router.post(
        url,
        {},
        { preserveScroll: true, onFinish: () => (procesando.value = false) },
    );
}

// Ajuste de un concepto: importe nuevo + motivo (queda en la bitácora).
const editando = ref<string | null>(null);
const formAjuste = useForm({ concepto_clave: '', importe: '', motivo: '' });

function editar(conceptoClave: string, importe: number) {
    editando.value = conceptoClave;
    formAjuste.clearErrors();
    formAjuste.concepto_clave = conceptoClave;
    formAjuste.importe = String(importe);
    formAjuste.motivo = '';
}

function guardarAjuste() {
    if (!props.seleccionado) {
        return;
    }

    formAjuste.post(rutasFiniquitos.ajustar.url(props.seleccionado.id), {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    });
}

// Cambio de tipo con motivo.
const tipoAbierto = ref(false);
const formTipo = useForm({ tipo: '', motivo: '' });

function abrirTipo() {
    formTipo.reset();
    formTipo.clearErrors();
    formTipo.tipo =
        props.seleccionado?.tipo === 'liquidacion'
            ? 'finiquito'
            : 'liquidacion';
    tipoAbierto.value = true;
}

function guardarTipo() {
    if (!props.seleccionado) {
        return;
    }

    formTipo.post(rutasFiniquitos.tipo.url(props.seleccionado.id), {
        preserveScroll: true,
        onSuccess: () => (tipoAbierto.value = false),
    });
}

// Registrar pago.
const pagoAbierto = ref(false);
const formPago = useForm({ fecha: '', forma_pago: '', comentario: '' });

function guardarPago() {
    if (!props.seleccionado) {
        return;
    }

    formPago
        .transform((d) => ({ ...d, forma_pago: d.forma_pago || null }))
        .post(rutasFiniquitos.pago.url(props.seleccionado.id), {
            preserveScroll: true,
            onSuccess: () => (pagoAbierto.value = false),
        });
}

function subirFirmado(evento: Event) {
    const entrada = evento.target as HTMLInputElement;
    const archivo = entrada.files?.[0];
    entrada.value = '';

    if (!archivo || !props.seleccionado) {
        return;
    }

    procesando.value = true;
    router.post(
        rutasFiniquitos.firmado.url(props.seleccionado.id),
        { archivo },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => (procesando.value = false),
        },
    );
}
</script>

<template>
    <Head title="Finiquitos" />

    <div class="pagina-ancha flex flex-col gap-5">
        <PestanasBajas :pestanas="pestanas" actual="finiquitos" />

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <TarjetaMetrica
                etiqueta="Calculados"
                :valor="resumen.calculados"
                detalle="Por revisar"
                tono="alerta"
                clicable
                :activa="estado === 'calculado'"
                @click="estado = estado === 'calculado' ? '' : 'calculado'"
            />
            <TarjetaMetrica
                etiqueta="Revisados"
                :valor="resumen.revisados"
                detalle="Listos para firma"
                tono="info"
                clicable
                :activa="estado === 'revisado'"
                @click="estado = estado === 'revisado' ? '' : 'revisado'"
            />
            <TarjetaMetrica
                etiqueta="Firmados"
                :valor="resumen.firmados"
                detalle="Pendientes de pago"
                tono="primario"
                clicable
                :activa="estado === 'firmado'"
                @click="estado = estado === 'firmado' ? '' : 'firmado'"
            />
            <TarjetaMetrica
                etiqueta="Pagados"
                :valor="resumen.pagados"
                detalle="En el periodo"
                tono="exito"
                clicable
                :activa="estado === 'pagado'"
                @click="estado = estado === 'pagado' ? '' : 'pagado'"
            />
            <TarjetaMetrica
                etiqueta="Monto pendiente de pago"
                :valor="moneda(resumen.monto_pendiente)"
                detalle="Finiquitos no pagados"
                tono="oro"
            />
        </div>

        <section
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
        >
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="busqueda"
                        class="pl-9"
                        placeholder="Buscar colaborador"
                        aria-label="Buscar colaborador"
                    />
                </div>
                <SelectSimple
                    v-model="estado"
                    :opciones="
                        etapas.map((e) => ({
                            value: e.clave,
                            label: e.etiqueta,
                        }))
                    "
                    opcion-vacia="Todos los estatus"
                    placeholder="Todos los estatus"
                />
                <SelectSimple
                    v-model="sucursal"
                    :opciones="sucursales"
                    numerico
                    opcion-vacia="Todas las sucursales"
                    placeholder="Todas las sucursales"
                />
                <SelectSimple
                    v-model="tipo"
                    :opciones="[
                        { value: 'finiquito', label: 'Finiquito' },
                        { value: 'liquidacion', label: 'Liquidación' },
                    ]"
                    opcion-vacia="Finiquito y liquidación"
                    placeholder="Finiquito y liquidación"
                />
            </div>

            <p
                v-if="!finiquitos.data.length"
                class="px-4 py-10 text-center text-sm text-muted-foreground"
            >
                No hay finiquitos con estos filtros.
            </p>

            <div v-else class="tabla-scroll">
                <table class="w-full text-left text-sm">
                    <thead
                        class="bg-crema/40 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="min-w-56 px-3 py-2 font-medium">
                                Colaborador
                            </th>
                            <th class="px-3 py-2 font-medium">
                                Forma de salida
                            </th>
                            <th class="px-3 py-2 font-medium">Tipo</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Monto
                            </th>
                            <th class="px-3 py-2 font-medium">Estatus</th>
                            <th class="px-3 py-2 font-medium">Fecha de pago</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="f in finiquitos.data"
                            :key="f.id"
                            :class="
                                cn(
                                    'border-b border-border/60 last:border-0',
                                    seleccionado?.id === f.id && 'bg-menta/40',
                                )
                            "
                        >
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <ColaboradorAvatar
                                        :nombre="f.colaborador"
                                        :foto-url="f.foto_url"
                                    />
                                    <div class="min-w-0">
                                        <p class="font-medium">
                                            {{ f.colaborador }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ f.sucursal ?? '—' }} ·
                                            {{ f.puesto ?? '—' }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                <span
                                    class="inline-flex items-center gap-1.5 font-medium"
                                >
                                    <span
                                        :class="
                                            cn(
                                                'size-2.5 rounded-full',
                                                claseForma(f.forma_token).fondo,
                                            )
                                        "
                                    />{{ f.forma_etiqueta ?? '—' }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <ChipEstado
                                    :etiqueta="f.tipo_etiqueta"
                                    :tono="
                                        f.tipo === 'liquidacion'
                                            ? 'oro'
                                            : 'info'
                                    "
                                />
                            </td>
                            <td
                                class="px-3 py-2 text-right font-semibold tabular-nums"
                            >
                                {{ moneda(f.monto) }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="e in etapas"
                                        :key="e.clave"
                                        :class="
                                            cn(
                                                'rounded-full px-1.5 py-0.5 text-[0.7rem]',
                                                etapaAlcanzada(f, e.clave)
                                                    ? 'bg-info-soft font-semibold text-info'
                                                    : 'bg-muted text-muted-foreground',
                                            )
                                        "
                                        >{{ e.etiqueta }}</span
                                    >
                                </div>
                                <p
                                    v-if="f.subestado"
                                    class="mt-0.5 text-xs text-muted-foreground"
                                >
                                    Subestado: {{ f.subestado }}
                                </p>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                {{ formatearFecha(f.fecha_pago) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="abrir(f.id)"
                                    ><Pencil class="size-3.5" />Editar</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <PaginacionTabla :datos="finiquitos" clave="bajas-finiquitos" />
        </section>

        <div
            v-if="errores.length"
            class="rounded-2xl bg-danger-soft px-4 py-3 text-sm text-destructive"
            role="alert"
        >
            <p v-for="(e, i) in errores" :key="i">{{ e }}</p>
        </div>

        <div v-if="seleccionado" class="grid gap-4 xl:grid-cols-2">
            <section
                class="flex flex-col gap-3 rounded-2xl border-2 border-[var(--mrl-petroleo)] bg-card p-4 shadow-sm"
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-2"
                >
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold">
                            {{ seleccionado.tipo_etiqueta }} ·
                            {{ seleccionado.colaborador }}
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            {{ seleccionado.forma_etiqueta ?? '—' }} · ingreso
                            {{ formatearFecha(seleccionado.fecha_ingreso) }} ·
                            último día
                            {{ formatearFecha(seleccionado.fecha_baja) }} ·
                            salario diario
                            {{ moneda(seleccionado.sueldo_diario) }}
                        </p>
                    </div>
                    <ChipEstado
                        :etiqueta="
                            etapas.find(
                                (e) => e.clave === seleccionado?.estado_flujo,
                            )?.etiqueta ?? ''
                        "
                        tono="alerta"
                    />
                </header>

                <ul class="divide-y divide-border/60">
                    <li
                        v-for="r in seleccionado.desglose"
                        :key="r.concepto_clave ?? r.clave"
                        class="py-2"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0 text-sm">
                                <p>{{ r.concepto }}</p>
                                <p
                                    v-if="r.ajustado"
                                    class="text-xs text-muted-foreground"
                                >
                                    Calculado:
                                    {{ moneda(r.valor_calculado ?? 0) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="font-medium tabular-nums">
                                    {{ r.tipo === 'deduccion' ? '−' : ''
                                    }}{{ moneda(r.importe) }}
                                </span>
                                <Button
                                    v-if="
                                        seleccionado.puede.editar &&
                                        r.concepto_clave
                                    "
                                    size="icon"
                                    variant="ghost"
                                    :aria-label="`Editar ${r.concepto}`"
                                    @click="editar(r.concepto_clave, r.importe)"
                                    ><Pencil class="size-4"
                                /></Button>
                            </div>
                        </div>
                        <form
                            v-if="editando === r.concepto_clave"
                            class="mt-2 grid gap-2 rounded-xl bg-crema/40 p-3 sm:grid-cols-[8rem_1fr_auto]"
                            @submit.prevent="guardarAjuste"
                        >
                            <div>
                                <Label class="text-xs"
                                    >Importe actualizado</Label
                                >
                                <Input
                                    v-model="formAjuste.importe"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                />
                                <InputError
                                    :message="formAjuste.errors.importe"
                                />
                            </div>
                            <div>
                                <Label class="text-xs">Motivo</Label>
                                <Input v-model="formAjuste.motivo" />
                                <InputError
                                    :message="formAjuste.errors.motivo"
                                />
                            </div>
                            <div class="flex items-end gap-1">
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="formAjuste.processing"
                                    >Guardar</Button
                                >
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="editando = null"
                                    >Cancelar</Button
                                >
                            </div>
                        </form>
                    </li>
                </ul>

                <p class="flex items-center justify-end gap-3 text-sm">
                    <span class="font-medium">Total</span>
                    <span class="text-lg font-bold tabular-nums">{{
                        moneda(seleccionado.monto)
                    }}</span>
                </p>
                <p class="text-xs text-muted-foreground">
                    Los importes se pueden editar; cada cambio queda registrado
                    con fecha y usuario.
                </p>

                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        v-if="seleccionado.puede.recalcular"
                        variant="outline"
                        :disabled="procesando"
                        @click="
                            accion(
                                rutasFiniquitos.recalcular.url(seleccionado.id),
                            )
                        "
                        ><RotateCcw class="size-4" />Recalcular</Button
                    >
                    <Button
                        v-if="seleccionado.puede.revisar"
                        variant="outline"
                        :disabled="procesando"
                        @click="
                            accion(rutasFiniquitos.revisar.url(seleccionado.id))
                        "
                        ><CheckCircle2 class="size-4" />Marcar como
                        revisado</Button
                    >
                    <Button v-if="seleccionado.puede.recibo" as-child>
                        <a
                            :href="rutasFiniquitos.recibo.url(seleccionado.id)"
                            target="_blank"
                            ><Download class="size-4" />Recibo de
                            {{ seleccionado.tipo_etiqueta.toLowerCase() }}
                            (PDF)</a
                        >
                    </Button>
                    <label
                        v-if="seleccionado.puede.subir_firmado"
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
                        v-if="seleccionado.puede.registrar_pago"
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
                        El tipo se propone con la causal de baja: las marcadas
                        en el catálogo como
                        <strong>«Implica indemnización»</strong> generan una
                        <strong>liquidación</strong>, que agrega los conceptos
                        de indemnización.
                    </p>
                    <p
                        v-if="seleccionado.advertencia_liquidacion"
                        class="rounded-xl bg-warning-soft px-3 py-2 text-xs text-warning"
                    >
                        {{ seleccionado.advertencia_liquidacion }}
                    </p>
                    <p v-if="seleccionado.causal" class="text-xs">
                        Causal: {{ seleccionado.causal }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="seleccionado.puede.cambiar_tipo"
                            size="sm"
                            variant="outline"
                            @click="abrirTipo"
                            >Cambiar a
                            {{
                                seleccionado.tipo === 'liquidacion'
                                    ? 'finiquito'
                                    : 'liquidación'
                            }}</Button
                        >
                        <Button
                            v-if="seleccionado.baja_id"
                            size="sm"
                            variant="ghost"
                            as-child
                        >
                            <Link
                                :href="
                                    rutasBajas.show.url(seleccionado.baja_id)
                                "
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
                        v-if="!seleccionado.historial_ajustes.length"
                        class="text-xs text-muted-foreground"
                    >
                        Sin ajustes: los importes son los calculados.
                    </p>
                    <ol v-else class="grid gap-2">
                        <li
                            v-for="(a, i) in seleccionado.historial_ajustes"
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
    </div>

    <Dialog v-model:open="tipoAbierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Cambiar el tipo del documento</DialogTitle>
                <DialogDescription
                    >Es una decisión de RH/Jurídico para este caso. No cambia
                    los montos: la indemnización se ajusta por concepto y el
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
                        :opciones="formas_pago"
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
