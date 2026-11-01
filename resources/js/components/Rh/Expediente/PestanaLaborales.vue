<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Briefcase,
    Building2,
    CalendarClock,
    CalendarDays,
    Hexagon,
    Hourglass,
    MapPinned,
    Network,
    Pencil,
    ShieldCheck,
    UserRound,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import BotonCorregir from '@/components/correcciones/BotonCorregir.vue';
import InputError from '@/components/InputError.vue';
import CampoInfo from '@/components/Rh/CampoInfo.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store as subirAltaImss } from '@/routes/rh/expedientes/alta-imss';
import { update as actualizarDatosLaborales } from '@/routes/rh/expedientes/datos-laborales';
import type { ExpedienteColaborador } from '@/types';

const props = defineProps<{
    colaborador: ExpedienteColaborador;
    departamentosDisponibles: { id: number; nombre: string }[];
    empresasDisponibles: { id: number; nombre: string }[];
    esPropio: boolean;
    puedeEditarLaborales: boolean;
    puedeVerSueldo: boolean;
    puestosDisponibles: {
        id: number;
        nombre: string;
        puesto_superior_id: number | null;
    }[];
    sucursalesDisponibles: {
        id: number;
        nombre: string;
        empresa_id: number | null;
    }[];
}>();

const subiendoImss = ref(false);

function subirAcuseImss(evento: Event) {
    const archivo = (evento.target as HTMLInputElement).files?.[0];

    if (!archivo) {
        return;
    }

    subiendoImss.value = true;
    router.post(
        subirAltaImss.url(props.colaborador.id),
        { archivo },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                subiendoImss.value = false;
                (evento.target as HTMLInputElement).value = '';
            },
        },
    );
}

const editandoLaborales = ref(false);

const empresaSeleccionada = ref(
    props.colaborador.empresa ? String(props.colaborador.empresa.id) : '',
);

const formLaborales = useForm({
    sucursal_principal_id: props.colaborador.sucursal
        ? String(props.colaborador.sucursal.id)
        : '',
    departamento_id: props.colaborador.departamento
        ? String(props.colaborador.departamento.id)
        : '',
    puesto_id: props.colaborador.puesto
        ? String(props.colaborador.puesto.id)
        : '',
    sueldo_mensual: props.colaborador.sueldo_mensual ?? '',
    fecha_ingreso: props.colaborador.fecha_ingreso ?? '',
    motivo: '',
});

const sucursalesFiltradas = computed(() =>
    empresaSeleccionada.value
        ? props.sucursalesDisponibles.filter(
              (sucursal) =>
                  String(sucursal.empresa_id) === empresaSeleccionada.value,
          )
        : props.sucursalesDisponibles,
);

function alCambiarEmpresa(valor: string) {
    empresaSeleccionada.value = valor;

    const sigueDisponible = sucursalesFiltradas.value.some(
        (sucursal) =>
            String(sucursal.id) === formLaborales.sucursal_principal_id,
    );

    if (!sigueDisponible) {
        formLaborales.sucursal_principal_id = '';
    }
}

function alCambiarPuestoLaboral(valor: string) {
    formLaborales.puesto_id = valor;
}

function alCambiarSucursalLaboral(valor: string) {
    formLaborales.sucursal_principal_id = valor;
}

function iniciarEdicionLaborales() {
    empresaSeleccionada.value = props.colaborador.empresa
        ? String(props.colaborador.empresa.id)
        : '';
    formLaborales.sucursal_principal_id = props.colaborador.sucursal
        ? String(props.colaborador.sucursal.id)
        : '';
    formLaborales.departamento_id = props.colaborador.departamento
        ? String(props.colaborador.departamento.id)
        : '';
    formLaborales.puesto_id = props.colaborador.puesto
        ? String(props.colaborador.puesto.id)
        : '';
    formLaborales.sueldo_mensual = props.colaborador.sueldo_mensual ?? '';
    formLaborales.fecha_ingreso = props.colaborador.fecha_ingreso ?? '';
    formLaborales.motivo = '';
    editandoLaborales.value = true;
}

function guardarLaborales() {
    formLaborales
        .transform((datos) => ({
            sucursal_principal_id: datos.sucursal_principal_id || null,
            departamento_id: datos.departamento_id || null,
            puesto_id: datos.puesto_id || null,
            sueldo_mensual: datos.sueldo_mensual || null,
            fecha_ingreso: datos.fecha_ingreso || null,
            motivo: datos.motivo || null,
        }))
        .put(actualizarDatosLaborales.url(props.colaborador.id), {
            preserveScroll: true,
            // El aviso (guardado, «sin cambios» o el monto que quedó) lo da el
            // servidor con su flash toast; un 422 lo avisan los errores
            // globales (toast + foco en el campo). Nunca un «éxito» propio.
            onSuccess: () => {
                editandoLaborales.value = false;
            },
        });
}

/**
 * Sueldo y fecha de ingreso son datos críticos: si cambian, el motivo es
 * obligatorio (ActualizarDatosLaboralesRequest). Se dice ANTES de guardar.
 */
const motivoLaboralRequerido = computed(() => {
    const sueldo = Number(formLaborales.sueldo_mensual || 0);
    const actual = Number(props.colaborador.sueldo_mensual ?? 0);
    const cambiaSueldo = sueldo > 0 && Math.abs(sueldo - actual) > 0.004;
    const cambiaIngreso =
        !!formLaborales.fecha_ingreso &&
        formLaborales.fecha_ingreso !== (props.colaborador.fecha_ingreso ?? '');

    return cambiaSueldo || cambiaIngreso;
});

const corregirSueldoAlInicio =
    typeof window !== 'undefined' &&
    new URLSearchParams(window.location.search).get('accion') ===
        'corregir_sueldo';

function sueldoFormateado(valor: string | null): string {
    if (!valor) {
        return 'Sin capturar';
    }

    return Number(valor).toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    });
}
</script>

<template>
    <Card class="rounded-2xl border-border/60">
        <CardHeader class="flex flex-row items-center justify-between gap-2">
            <CardTitle class="flex items-center gap-2 text-base">
                <Briefcase class="size-4" />
                Datos laborales
            </CardTitle>
            <div
                v-if="puedeEditarLaborales && !editandoLaborales"
                class="flex flex-wrap items-center gap-2"
            >
                <BotonCorregir
                    v-if="puedeVerSueldo"
                    dominio="salario"
                    :sujeto-id="colaborador.id"
                    etiqueta="Corregir sueldo"
                    :abrir-al-inicio="corregirSueldoAlInicio"
                    permiso="salarios.editar"
                />
                <BotonCorregir dominio="laboral" :sujeto-id="colaborador.id" />
                <Button
                    size="sm"
                    variant="ghost"
                    @click="iniciarEdicionLaborales"
                >
                    <Pencil class="size-3.5" />
                    Editar
                </Button>
            </div>
        </CardHeader>
        <CardContent class="flex flex-col gap-6">
            <form
                v-if="editandoLaborales"
                class="flex flex-col gap-4"
                @submit.prevent="guardarLaborales"
            >
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label>Empresa</Label>
                        <SelectSimple
                            :model-value="empresaSeleccionada"
                            :opciones="
                                empresasDisponibles.map((e) => ({
                                    value: String(e.id),
                                    label: e.nombre,
                                }))
                            "
                            placeholder="Selecciona una empresa"
                            @update:model-value="
                                (v) => alCambiarEmpresa(String(v ?? ''))
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Sucursal</Label>
                        <SelectSimple
                            :model-value="formLaborales.sucursal_principal_id"
                            :opciones="
                                sucursalesFiltradas.map((s) => ({
                                    value: String(s.id),
                                    label: s.nombre,
                                }))
                            "
                            placeholder="Selecciona una sucursal"
                            @update:model-value="
                                (v) => alCambiarSucursalLaboral(String(v ?? ''))
                            "
                        />
                        <InputError
                            :message="
                                formLaborales.errors.sucursal_principal_id
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Departamento</Label>
                        <SelectSimple
                            v-model="formLaborales.departamento_id"
                            :opciones="
                                departamentosDisponibles.map((d) => ({
                                    value: String(d.id),
                                    label: d.nombre,
                                }))
                            "
                            placeholder="Selecciona un departamento"
                        />
                        <InputError
                            :message="formLaborales.errors.departamento_id"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Puesto</Label>
                        <SelectSimple
                            :model-value="formLaborales.puesto_id"
                            :opciones="
                                puestosDisponibles.map((p) => ({
                                    value: String(p.id),
                                    label: p.nombre,
                                }))
                            "
                            placeholder="Selecciona un puesto"
                            @update:model-value="
                                (v) => alCambiarPuestoLaboral(String(v ?? ''))
                            "
                        />
                        <InputError :message="formLaborales.errors.puesto_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Jefe directo</Label>
                        <p
                            class="flex min-h-9 items-center rounded-md border border-dashed px-3 text-sm text-muted-foreground"
                        >
                            Se asigna solo según el organigrama (puesto y
                            sucursal) al guardar.
                        </p>
                    </div>
                    <div v-if="puedeVerSueldo" class="grid gap-2">
                        <Label for="sueldo_mensual">Sueldo mensual</Label>
                        <Input
                            id="sueldo_mensual"
                            v-model="formLaborales.sueldo_mensual"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                        />
                        <InputError
                            :message="formLaborales.errors.sueldo_mensual"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="fecha_ingreso_laboral"
                            >Fecha de ingreso</Label
                        >
                        <DatePicker
                            id="fecha_ingreso_laboral"
                            v-model="formLaborales.fecha_ingreso"
                        />
                        <p class="text-xs text-muted-foreground">
                            De ella salen todas las fechas del contrato.
                        </p>
                        <InputError
                            :message="formLaborales.errors.fecha_ingreso"
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="motivo_laboral"
                            >Motivo del cambio
                            {{
                                motivoLaboralRequerido
                                    ? '(obligatorio: cambias un dato crítico)'
                                    : '(obligatorio si cambias sueldo o fecha de ingreso)'
                            }}</Label
                        >
                        <Input
                            id="motivo_laboral"
                            v-model="formLaborales.motivo"
                            name="motivo"
                            :required="motivoLaboralRequerido"
                            :aria-invalid="!!formLaborales.errors.motivo"
                            placeholder="Ej. promoción, reubicación, ajuste de sueldo, corrección de captura..."
                        />
                        <InputError :message="formLaborales.errors.motivo" />
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="formLaborales.processing"
                    >
                        <Spinner v-if="formLaborales.processing" />
                        Guardar cambios
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="editandoLaborales = false"
                    >
                        Cancelar
                    </Button>
                </div>
            </form>

            <template v-else>
                <div class="flex flex-col gap-3">
                    <p
                        class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <Network class="size-3.5" />
                        Ubicación organizacional
                    </p>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <CampoInfo :icono="Building2" etiqueta="Empresa">
                            {{ colaborador.empresa?.nombre ?? '—' }}
                        </CampoInfo>
                        <CampoInfo :icono="MapPinned" etiqueta="Sucursal">
                            {{ colaborador.sucursal?.nombre ?? '—' }}
                        </CampoInfo>
                        <CampoInfo :icono="Hexagon" etiqueta="Departamento">
                            {{ colaborador.departamento?.nombre ?? '—' }}
                        </CampoInfo>
                        <CampoInfo :icono="BadgeCheck" etiqueta="Puesto">
                            {{ colaborador.puesto?.nombre ?? '—' }}
                        </CampoInfo>
                        <CampoInfo :icono="UserRound" etiqueta="Jefe directo">
                            <template v-if="colaborador.jefe">
                                {{ colaborador.jefe.name }}
                                {{ colaborador.jefe.apellidos }}
                            </template>
                            <template v-else>Sin asignar</template>
                        </CampoInfo>
                        <CampoInfo
                            v-if="puedeVerSueldo"
                            :icono="Wallet"
                            etiqueta="Sueldo mensual"
                        >
                            {{ sueldoFormateado(colaborador.sueldo_mensual) }}
                        </CampoInfo>
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t pt-4">
                    <p
                        class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <ShieldCheck class="size-3.5" />
                        Estatus laboral e IMSS
                    </p>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <CampoInfo
                            :icono="CalendarDays"
                            etiqueta="Fecha de ingreso"
                        >
                            {{ colaborador.fecha_ingreso ?? '—' }}
                        </CampoInfo>
                        <CampoInfo etiqueta="Estado laboral">
                            <EstadoBadge :estado="colaborador.estatus" />
                        </CampoInfo>
                        <CampoInfo etiqueta="Estatus IMSS">
                            <EstadoBadge
                                :estado="colaborador.estatus_imss"
                                etiqueta="IMSS"
                            />
                        </CampoInfo>
                        <CampoInfo
                            :icono="CalendarClock"
                            etiqueta="Fecha alta IMSS"
                        >
                            {{ colaborador.fecha_alta_imss ?? '—' }}
                        </CampoInfo>
                        <CampoInfo
                            v-if="colaborador.imss_alta"
                            etiqueta="Acuse de alta IMSS"
                            class="sm:col-span-2"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        colaborador.imss_alta === 'registrada'
                                            ? 'bg-success-soft text-success'
                                            : 'bg-warning-soft text-warning'
                                    "
                                    data-imss-alta
                                >
                                    {{
                                        colaborador.imss_alta === 'registrada'
                                            ? 'Registrado'
                                            : 'Falta el acuse'
                                    }}
                                </span>
                                <a
                                    v-if="colaborador.imss_alta_archivo"
                                    :href="colaborador.imss_alta_archivo.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-xs underline"
                                    >{{
                                        colaborador.imss_alta_archivo.nombre
                                    }}</a
                                >
                                <label
                                    v-if="colaborador.puede_subir_imss"
                                    class="inline-flex cursor-pointer items-center gap-1 text-xs font-medium text-primary underline"
                                >
                                    <Spinner
                                        v-if="subiendoImss"
                                        class="size-3"
                                    />
                                    {{
                                        colaborador.imss_alta === 'registrada'
                                            ? 'Reemplazar archivo'
                                            : 'Subir acuse'
                                    }}
                                    <input
                                        type="file"
                                        class="sr-only"
                                        accept="application/pdf,image/*"
                                        :disabled="subiendoImss"
                                        @change="subirAcuseImss"
                                    />
                                </label>
                            </div>
                        </CampoInfo>
                        <CampoInfo
                            v-if="!esPropio"
                            :icono="Hourglass"
                            etiqueta="Periodo de prueba"
                            class="sm:col-span-2"
                        >
                            <template
                                v-if="
                                    colaborador.periodo_prueba_inicio &&
                                    colaborador.periodo_prueba_fin
                                "
                            >
                                {{ colaborador.periodo_prueba_inicio }}
                                —
                                {{ colaborador.periodo_prueba_fin }}
                                <span
                                    v-if="colaborador.en_periodo_prueba"
                                    class="text-warning"
                                    >(vigente)</span
                                >
                            </template>
                            <template v-else>—</template>
                        </CampoInfo>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    Los cambios de arriba quedan registrados como movimientos
                    laborales (ver pestaña «Historial RH»). El correo, los roles
                    y la contraseña de acceso están en la pestaña «Cuenta».
                </p>
            </template>
        </CardContent>
    </Card>
</template>
