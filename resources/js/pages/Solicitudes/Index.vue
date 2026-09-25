<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Banknote,
    Baby,
    Cake,
    Calendar,
    Clock,
    ClipboardList,
    Eye,
    FileEdit,
    FileWarning,
    Heart,
    LogOut,
    MessageSquare,
    Plus,
    Timer,
    UserX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
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
import { dashboard } from '@/routes';
import { index, show, store } from '@/routes/solicitudes';
import type {
    ColaboradorParaBaja,
    RespuestaPaginada,
    SaldoVacaciones,
    SolicitudInternaItem,
    TipoSolicitudInternaFormulario,
} from '@/types';

const props = defineProps<{
    solicitudes: RespuestaPaginada<SolicitudInternaItem>;
    tipos: TipoSolicitudInternaFormulario[];
    saldoVacaciones: SaldoVacaciones;
    colaboradoresParaBaja: ColaboradorParaBaja[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis solicitudes', href: index.url() },
        ],
    },
});

const dialogoAbierto = ref(false);
const pasoFormulario = ref<'tipo' | 'detalle'>('tipo');

const ICONO_TIPO: Record<string, typeof ClipboardList> = {
    vacaciones: Calendar,
    permiso_con_goce: FileEdit,
    permiso_sin_goce: FileEdit,
    permiso_tiempo: Timer,
    salida_temprano: LogOut,
    llegada_tarde: Clock,
    incapacidad: FileWarning,
    constancia_laboral: ClipboardList,
    actualizacion_datos: FileEdit,
    actualizacion_bancaria: Banknote,
    reposicion_documental: FileWarning,
    prestamo: Banknote,
    baja_colaborador: UserX,
    permiso_especial_cumpleanos: Cake,
    permiso_especial_paternidad: Baby,
    permiso_especial_fallecimiento: Heart,
    solicitud_general: MessageSquare,
};

const DESCRIPCION_TIPO: Record<string, string> = {
    vacaciones: 'Solicita días de tu saldo disponible.',
    permiso_con_goce: 'Permiso pagado por un rango de fechas.',
    permiso_sin_goce: 'Permiso sin pago por un rango de fechas.',
    permiso_tiempo: 'Salida por algunas horas en el día.',
    salida_temprano: 'Terminar tu jornada antes de la hora habitual.',
    llegada_tarde: 'Llegar después de tu horario habitual.',
    incapacidad: 'Registra una incapacidad médica y su comprobante.',
    constancia_laboral: 'Pide una constancia de que trabajas aquí.',
    actualizacion_datos: 'Corrige o actualiza tus datos personales.',
    actualizacion_bancaria: 'Actualiza tu cuenta para depósito de nómina.',
    reposicion_documental: 'Repone un documento de tu expediente.',
    prestamo: 'Solicita un préstamo con descuento a nómina.',
    baja_colaborador: 'Inicia la baja de un colaborador a tu cargo.',
    permiso_especial_cumpleanos: 'Día libre por tu cumpleaños.',
    permiso_especial_paternidad: 'Permiso especial por paternidad.',
    permiso_especial_fallecimiento: 'Permiso especial por fallecimiento familiar.',
    solicitud_general: 'Cualquier otro trámite que no encaje arriba.',
};

const AVISO_FORMATO_AUTOMATICO: Record<string, string> = {
    vacaciones: 'Al aprobarse se generará tu formato de vacaciones automáticamente.',
    permiso_con_goce: 'Al aprobarse se generará el formato de permiso automáticamente.',
    permiso_sin_goce: 'Al aprobarse se generará el formato de permiso automáticamente.',
    permiso_tiempo: 'Al aprobarse se generará el formato de permiso automáticamente.',
    salida_temprano: 'Al aprobarse se generará el formato de permiso automáticamente.',
    llegada_tarde: 'Al aprobarse se generará el formato de permiso automáticamente.',
    prestamo: 'Al aprobarse se generará el contrato de crédito correspondiente.',
};

function seleccionarTipo(clave: string) {
    form.tipo = clave;
    pasoFormulario.value = 'detalle';
}

function abrirNuevaSolicitud() {
    pasoFormulario.value = 'tipo';
    dialogoAbierto.value = true;
}

const form = useForm({
    tipo: '',
    motivo: '',
    observaciones: '',
    fecha_inicio: '',
    fecha_fin: '',
    dias_solicitados: '',
    monto_solicitado: '',
    plazo_meses: '',
    colaborador_objetivo_id: '',
    fecha_efectiva: '',
    tipo_baja: '',
});

function enviar() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            dialogoAbierto.value = false;
            pasoFormulario.value = 'tipo';
            form.reset();
        },
    });
}

const tipoActual = computed(() =>
    props.tipos.find((t) => t.clave === form.tipo),
);

function nombreTipo(clave: string): string {
    return props.tipos.find((t) => t.clave === clave)?.nombre ?? clave;
}

const TIPOS_BAJA = [
    { value: 'renuncia', label: 'Renuncia voluntaria' },
    { value: 'despido', label: 'Despido' },
    { value: 'mutuo_acuerdo', label: 'Mutuo acuerdo' },
    { value: 'fin_contrato', label: 'Fin de contrato' },
    { value: 'abandono', label: 'Abandono de empleo' },
    { value: 'otro', label: 'Otro' },
];
</script>

<template>
    <Head title="Mis solicitudes" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Mis solicitudes"
            descripcion="Vacaciones, permisos, préstamos, incapacidades y otros trámites internos, todo en un solo lugar."
            :icono="ClipboardList"
        >
            <Button data-tour="mis-solicitudes-nueva" @click="abrirNuevaSolicitud">
                <Plus class="size-4" />
                Nueva solicitud
            </Button>
        </CrudPageHeader>

        <CrudEmptyState
            v-if="!solicitudes.data.length"
            :icono="ClipboardList"
            titulo="Todavía no tienes solicitudes"
            descripcion="Crea tu primera solicitud interna con el botón de arriba."
        >
            <Button @click="abrirNuevaSolicitud">
                <Plus class="size-4" />
                Crear la primera
            </Button>
        </CrudEmptyState>

        <div
            v-else
            data-tour="mis-solicitudes-lista"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="solicitud in solicitudes.data"
                :key="solicitud.id"
                :href="show.url(solicitud.id)"
                class="group flex flex-col gap-2.5 rounded-2xl border border-border/60 bg-card p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ solicitud.folio }}
                        </p>
                        <p class="text-base font-semibold">
                            {{ nombreTipo(solicitud.tipo) }}
                        </p>
                    </div>
                    <EstadoBadge :estado="solicitud.estado" />
                </div>
                <p class="line-clamp-2 text-sm text-muted-foreground">
                    {{ solicitud.motivo }}
                </p>
                <div
                    class="mt-1 flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>{{ solicitud.created_at }}</span>
                    <Eye
                        class="size-4 opacity-60 transition-opacity md:opacity-0 md:group-hover:opacity-100"
                    />
                </div>
            </Link>
        </div>
    </div>

    <Dialog v-model:open="dialogoAbierto">
        <DialogContent
            class="max-h-[90vh] w-[calc(100vw-2rem)] max-w-none overflow-y-auto sm:w-[min(94vw,1100px)]"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        pasoFormulario === 'tipo'
                            ? '¿Qué necesitas solicitar?'
                            : `Nueva solicitud — ${tipoActual?.nombre ?? ''}`
                    }}
                </DialogTitle>
            </DialogHeader>

            <div
                v-if="pasoFormulario === 'tipo'"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <button
                    v-for="tipo in tipos"
                    :key="tipo.clave"
                    type="button"
                    class="flex flex-col items-start gap-2 rounded-2xl border border-border/60 bg-card p-4 text-left transition-colors hover:border-primary/40 hover:bg-accent/40"
                    @click="seleccionarTipo(tipo.clave)"
                >
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <component
                            :is="ICONO_TIPO[tipo.clave] ?? ClipboardList"
                            class="size-4.5"
                        />
                    </span>
                    <span class="text-sm font-semibold">{{
                        tipo.nombre
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        DESCRIPCION_TIPO[tipo.clave] ??
                        'Solicítalo desde aquí.'
                    }}</span>
                </button>
            </div>

            <form
                v-else
                class="grid gap-4"
                @submit.prevent="enviar"
            >
                <button
                    type="button"
                    class="flex w-fit items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
                    @click="pasoFormulario = 'tipo'"
                >
                    <ArrowLeft class="size-3.5" />
                    Cambiar tipo de solicitud
                </button>

                <div
                    v-if="AVISO_FORMATO_AUTOMATICO[form.tipo]"
                    class="rounded-lg border border-[var(--brand-primary)]/30 bg-[var(--brand-primary)]/5 p-3 text-xs text-foreground"
                >
                    {{ AVISO_FORMATO_AUTOMATICO[form.tipo] }}
                </div>

                <p v-if="form.errors.tipo" class="text-sm text-destructive">
                    {{ form.errors.tipo }}
                </p>

                <!-- Vacaciones: saldo disponible + días a solicitar -->
                <div
                    v-if="tipoActual?.requiere_dias"
                    class="rounded-lg border border-border/60 bg-muted/40 p-3 text-sm"
                >
                    Días disponibles:
                    <span class="font-semibold">{{
                        saldoVacaciones.dias_disponibles
                    }}</span>
                    de {{ saldoVacaciones.dias_generados }} generados este
                    periodo.
                </div>

                <div
                    v-if="tipoActual?.requiere_fechas || tipoActual?.requiere_horario"
                    class="grid grid-cols-2 gap-4"
                >
                    <div class="grid gap-2">
                        <Label for="fecha_inicio">{{
                            tipoActual?.requiere_fechas
                                ? 'Fecha de inicio'
                                : 'Fecha'
                        }}</Label>
                        <DatePicker
                            id="fecha_inicio"
                            v-model="form.fecha_inicio"
                        />
                        <p
                            v-if="form.errors.fecha_inicio"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.fecha_inicio }}
                        </p>
                    </div>
                    <div v-if="tipoActual?.requiere_fechas" class="grid gap-2">
                        <Label for="fecha_fin">Fecha de fin</Label>
                        <DatePicker
                            id="fecha_fin"
                            v-model="form.fecha_fin"
                        />
                        <p
                            v-if="form.errors.fecha_fin"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.fecha_fin }}
                        </p>
                    </div>
                </div>

                <div v-if="tipoActual?.requiere_dias" class="grid gap-2">
                    <Label for="dias_solicitados">Días a solicitar</Label>
                    <Input
                        id="dias_solicitados"
                        v-model="form.dias_solicitados"
                        type="number"
                        min="1"
                        :max="saldoVacaciones.dias_disponibles"
                    />
                    <p
                        v-if="form.errors.dias_solicitados"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.dias_solicitados }}
                    </p>
                </div>

                <!-- Préstamo interno: monto y plazo -->
                <div v-if="tipoActual?.requiere_monto" class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="monto_solicitado">Monto solicitado</Label>
                        <Input
                            id="monto_solicitado"
                            v-model="form.monto_solicitado"
                            type="number"
                            min="1"
                            step="0.01"
                        />
                        <p
                            v-if="form.errors.monto_solicitado"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.monto_solicitado }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="plazo_meses">Plazo (meses)</Label>
                        <Input
                            id="plazo_meses"
                            v-model="form.plazo_meses"
                            type="number"
                            min="1"
                            max="36"
                        />
                    </div>
                </div>

                <!-- Baja de colaborador: a quién se solicita dar de baja -->
                <div
                    v-if="tipoActual?.requiere_colaborador_objetivo"
                    class="grid gap-2"
                >
                    <Label for="colaborador_objetivo_id">Colaborador</Label>
                    <Select v-model="form.colaborador_objetivo_id">
                        <SelectTrigger id="colaborador_objetivo_id" class="w-full">
                            <SelectValue placeholder="Selecciona un colaborador" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="colaborador in colaboradoresParaBaja"
                                :key="colaborador.id"
                                :value="String(colaborador.id)"
                            >
                                {{ colaborador.name }}
                                {{ colaborador.apellidos ?? '' }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="form.errors.colaborador_objetivo_id"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.colaborador_objetivo_id }}
                    </p>
                </div>

                <div
                    v-if="tipoActual?.requiere_colaborador_objetivo"
                    class="grid grid-cols-2 gap-3"
                >
                    <div class="grid gap-2">
                        <Label for="fecha_efectiva">Fecha efectiva de baja</Label>
                        <DatePicker
                            id="fecha_efectiva"
                            v-model="form.fecha_efectiva"
                        />
                        <p
                            v-if="form.errors.fecha_efectiva"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.fecha_efectiva }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="tipo_baja">Tipo de baja</Label>
                        <Select v-model="form.tipo_baja">
                            <SelectTrigger id="tipo_baja" class="w-full">
                                <SelectValue placeholder="Selecciona" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in TIPOS_BAJA"
                                    :key="opcion.value"
                                    :value="opcion.value"
                                >
                                    {{ opcion.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="form.errors.tipo_baja"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.tipo_baja }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="motivo">Motivo</Label>
                    <Textarea id="motivo" v-model="form.motivo" rows="3" />
                    <p
                        v-if="form.errors.motivo"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.motivo }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="observaciones">Observaciones (opcional)</Label>
                    <Textarea
                        id="observaciones"
                        v-model="form.observaciones"
                        rows="2"
                    />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="dialogoAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Enviar solicitud
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
