<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    CircleCheck,
    CircleMinus,
    Info,
    MapPinned,
    UserRound,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionBuscable } from '@/components/ui/combobox-buscable';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { enviarJson, ErrorSolicitud } from '@/lib/http';
import {
    store as aplicarCambio,
    vistaPrevia as rutaVistaPrevia,
} from '@/routes/rh/cambio-sucursal';
import type {
    ResultadoCambioSucursal,
    VistaPreviaCambioSucursal,
} from '@/types';

/**
 * «Cambio de sucursal»: Puestos y salarios (Trasladar personas → Sucursal /
 * Ambos) y expediente (Datos laborales). Elige la sucursal destino viendo sus
 * plazas libres del puesto, fecha efectiva y motivo, y muestra el resumen
 * antes de confirmar: origen → destino, jefe nuevo (del organigrama) y qué
 * plaza se ocupa o se libera. El backend (CambioSucursalService) revalida
 * todo: permiso, alcance, plaza libre y fecha.
 */
const props = defineProps<{
    open: boolean;
    colaboradorIds: number[];
    /** «Ambos»: también cambia a este puesto. */
    puestoDestinoId?: number | null;
}>();

const emit = defineEmits<{
    'update:open': [abierto: boolean];
    aplicado: [resultado: ResultadoCambioSucursal | null];
}>();

const hoy = new Date().toLocaleDateString('en-CA', {
    timeZone: 'America/Mexico_City',
});

const sucursalId = ref<number | undefined>(undefined);
const fecha = ref<string>(hoy);
const motivo = ref('');
const autorizar = ref(false);
const datos = ref<VistaPreviaCambioSucursal | null>(null);
const cargando = ref(false);
const enviando = ref(false);
const error = ref<string | null>(null);
let turno = 0;

const cuerpo = computed(() => ({
    colaborador_ids: props.colaboradorIds,
    puesto_destino_id: props.puestoDestinoId ?? null,
    sucursal_id: sucursalId.value ?? null,
    fecha: fecha.value || null,
    autorizar_plaza: autorizar.value,
    motivo: motivo.value.trim() || null,
}));

async function consultar(): Promise<void> {
    const actual = ++turno;
    cargando.value = true;
    error.value = null;

    try {
        const respuesta = await enviarJson<VistaPreviaCambioSucursal>(
            'POST',
            rutaVistaPrevia.url(),
            cuerpo.value,
        );

        if (actual === turno) {
            datos.value = respuesta;
        }
    } catch (e) {
        if (actual === turno) {
            error.value =
                e instanceof ErrorSolicitud || e instanceof Error
                    ? e.message
                    : 'No se pudo calcular el cambio.';

            if (datos.value) {
                datos.value = { ...datos.value, resumen: null };
            }
        }
    } finally {
        if (actual === turno) {
            cargando.value = false;
        }
    }
}

watch(
    () => props.open,
    (abierto) => {
        if (abierto) {
            sucursalId.value = undefined;
            fecha.value = hoy;
            motivo.value = '';
            autorizar.value = false;
            datos.value = null;
            void consultar();
        }
    },
    { immediate: true },
);

watch([sucursalId, fecha, autorizar, () => props.puestoDestinoId], () => {
    if (props.open) {
        void consultar();
    }
});

const opciones = computed<OpcionBuscable[]>(() =>
    (datos.value?.sucursales ?? []).map((s) => ({
        value: s.id,
        label: s.nombre,
        grupo: s.es_corporativo ? 'Corporativo' : 'Sucursales',
        descripcion:
            s.autorizadas === 0
                ? 'Sin plazas autorizadas de este puesto'
                : `${s.ocupadas} de ${s.autorizadas} ocupadas`,
        chip: s.actual
            ? 'Actual'
            : s.libres > 0
              ? `${s.libres} libre${s.libres === 1 ? '' : 's'}`
              : undefined,
        chipTono: s.libres > 0 ? 'primario' : 'neutro',
    })),
);

const resumen = computed(() => datos.value?.resumen ?? null);

const TONOS: Record<string, { icono: Component; clase: string }> = {
    vacante: { icono: CircleMinus, clase: 'text-warning' },
    ocupa: { icono: CircleCheck, clase: 'text-success' },
    fuera: { icono: AlertTriangle, clase: 'text-destructive' },
    info: { icono: Info, clase: 'text-muted-foreground' },
};

const puedeConfirmar = computed(
    () =>
        resumen.value !== null &&
        resumen.value.bloqueo === null &&
        !resumen.value.personas.some((p) => p.sin_cambios) &&
        !cargando.value &&
        !enviando.value,
);

function cerrar(): void {
    if (!enviando.value) {
        emit('update:open', false);
    }
}

function confirmar(): void {
    if (!puedeConfirmar.value) {
        return;
    }

    enviando.value = true;
    let resultado: ResultadoCambioSucursal | null = null;

    router.post(aplicarCambio.url(), cuerpo.value, {
        preserveScroll: true,
        onFlash: (flash) => {
            resultado =
                ((flash as Record<string, unknown>).cambioSucursal as
                    ResultadoCambioSucursal | undefined) ?? null;
        },
        onSuccess: () => {
            emit('update:open', false);
            emit('aplicado', resultado);
        },
        onError: (errores) => {
            error.value =
                Object.values(errores)[0] ?? 'No se pudo aplicar el cambio.';
        },
        onFinish: () => {
            enviando.value = false;
        },
    });
}

const titulo = computed(() => {
    const n = props.colaboradorIds.length;
    const quien = n === 1 ? 'una persona' : `${n} personas`;

    return props.puestoDestinoId
        ? `Cambiar de puesto y sucursal a ${quien}`
        : `Cambio de sucursal de ${quien}`;
});
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => !v && cerrar()">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <MapPinned class="size-4.5 text-primary" />
                    {{ titulo }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="datos">
                        Puesto: <strong>{{ datos.puesto.nombre }}</strong
                        >{{ puestoDestinoId ? ' (nuevo)' : ' (no cambia)' }}.
                    </template>
                    Revisa el resumen antes de confirmar: el jefe directo sale
                    del organigrama y la plantilla se ajusta sola.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4">
                <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                    <div class="grid gap-1.5">
                        <Label for="cambio-sucursal-destino"
                            >¿A qué sucursal?</Label
                        >
                        <Skeleton
                            v-if="!datos && cargando"
                            class="h-10 w-full rounded-xl"
                        />
                        <ComboboxBuscable
                            v-else
                            id="cambio-sucursal-destino"
                            v-model="sucursalId"
                            numerico
                            :opciones="opciones"
                            placeholder="Elige la sucursal destino"
                            placeholder-busqueda="Buscar sucursal…"
                            texto-vacio="Sin sucursales"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="cambio-sucursal-fecha"
                            >Fecha efectiva</Label
                        >
                        <DatePicker
                            id="cambio-sucursal-fecha"
                            v-model="fecha"
                            :max-value="hoy"
                        />
                    </div>
                </div>

                <p
                    v-if="error"
                    class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-destructive"
                    role="alert"
                >
                    {{ error }}
                </p>

                <template v-if="sucursalId && resumen">
                    <!-- Personas: origen → destino y jefe -->
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="p in resumen.personas"
                            :key="p.id"
                            class="rounded-2xl border bg-crema/30 p-3"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <p class="font-medium">
                                    {{ p.nombre }}
                                    <span
                                        v-if="p.numero_empleado"
                                        class="text-xs font-normal text-muted-foreground"
                                        >#{{ p.numero_empleado }}</span
                                    >
                                </p>
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                    :class="
                                        p.sin_cambios
                                            ? 'bg-warning-soft text-warning'
                                            : 'bg-salvia text-foreground'
                                    "
                                    >{{ p.tipo_movimiento }}</span
                                >
                            </div>
                            <p
                                class="mt-1.5 flex flex-wrap items-center gap-1.5 text-sm"
                            >
                                <span class="text-muted-foreground">{{
                                    p.de
                                }}</span>
                                <ArrowRight class="size-3.5 shrink-0" />
                                <strong>{{ p.a }}</strong>
                            </p>
                            <p
                                class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <UserRound class="size-3.5" />
                                Jefe directo:
                                {{ p.jefe_actual ?? 'sin jefe' }}
                                <ArrowRight class="size-3" />
                                <strong class="text-foreground">{{
                                    p.jefe_nuevo ?? 'sin jefe asignable'
                                }}</strong>
                            </p>
                            <p
                                v-if="p.departamento_de !== p.departamento_a"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                Departamento: {{ p.departamento_de ?? '—' }} →
                                {{ p.departamento_a ?? '—' }}
                            </p>
                            <p
                                v-for="r in p.rutas"
                                :key="r"
                                class="mt-0.5 text-xs text-warning"
                            >
                                {{ r }}
                            </p>
                        </li>
                    </ul>

                    <!-- Plazas -->
                    <section class="rounded-2xl border p-3">
                        <h3 class="text-sm font-semibold">Plantilla</h3>
                        <ul class="mt-2 flex flex-col gap-1.5 text-sm">
                            <li
                                v-for="(h, i) in resumen.plazas_origen"
                                :key="i"
                                class="flex items-start gap-2"
                            >
                                <component
                                    :is="TONOS[h.tono]?.icono ?? Info"
                                    class="mt-0.5 size-4 shrink-0"
                                    :class="TONOS[h.tono]?.clase"
                                />
                                <span>{{ h.texto }}</span>
                            </li>
                        </ul>

                        <label
                            v-if="
                                resumen.requiere_autorizar &&
                                resumen.puede_autorizar
                            "
                            class="mt-3 flex items-start gap-2 rounded-xl bg-warning-soft p-3 text-sm"
                        >
                            <Checkbox
                                :model-value="autorizar"
                                @update:model-value="
                                    (v) => (autorizar = v === true)
                                "
                            />
                            <span>
                                <strong>Autorizar plaza en destino</strong>:
                                suma
                                {{ resumen.plaza_destino.faltan }}
                                {{
                                    resumen.plaza_destino.faltan === 1
                                        ? 'plaza'
                                        : 'plazas'
                                }}
                                de {{ resumen.plaza_destino.puesto }} en
                                {{ resumen.plaza_destino.sucursal }} (queda en
                                el histórico de plantilla con tu nombre y el
                                motivo).
                            </span>
                        </label>
                    </section>

                    <p
                        v-if="resumen.bloqueo"
                        class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-destructive"
                    >
                        {{ resumen.bloqueo }}
                    </p>
                    <ul
                        v-if="resumen.avisos.length"
                        class="flex flex-col gap-1 text-xs text-warning"
                    >
                        <li v-for="a in resumen.avisos" :key="a">{{ a }}</li>
                    </ul>

                    <div class="grid gap-1.5">
                        <Label for="cambio-sucursal-motivo"
                            >Motivo (queda en el movimiento laboral)</Label
                        >
                        <Textarea
                            id="cambio-sucursal-motivo"
                            v-model="motivo"
                            rows="2"
                            maxlength="255"
                            placeholder="Ej. Reubicación a Atlixco por apertura de la plaza"
                        />
                    </div>
                </template>

                <p
                    v-else-if="!sucursalId && datos"
                    class="rounded-2xl bg-crema/30 px-4 py-5 text-center text-sm text-muted-foreground"
                >
                    Elige la sucursal destino para ver el resumen: plaza que
                    ocupa, plaza que se libera y su nuevo jefe directo.
                </p>
                <Skeleton
                    v-else-if="sucursalId && cargando"
                    class="h-40 w-full rounded-2xl"
                />
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="enviando"
                    @click="cerrar"
                    >Cancelar</Button
                >
                <Button
                    type="button"
                    :disabled="!puedeConfirmar"
                    @click="confirmar"
                >
                    <Spinner v-if="enviando || cargando" />
                    Confirmar cambio
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
