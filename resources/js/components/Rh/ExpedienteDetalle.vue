<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Briefcase,
    Building2,
    Calendar,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    CircleDashed,
    ClipboardList,
    Fingerprint,
    Home,
    Hourglass,
    IdCard,
    KeyRound,
    Layers,
    ListChecks,
    Lock,
    Mail,
    MapPinned,
    Phone,
    PhoneCall,
    ScrollText,
    ShieldCheck,
    Unlock,
    User,
    UserCog,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import InputError from '@/components/InputError.vue';
import CampoInfo from '@/components/Rh/CampoInfo.vue';
import EstablecerPasswordDialog from '@/components/Rh/EstablecerPasswordDialog.vue';
import ExpedienteDocumentos from '@/components/Rh/ExpedienteDocumentos.vue';
import MovimientosLaboralesTimeline from '@/components/Rh/MovimientosLaboralesTimeline.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useAlertas } from '@/composables/useAlertas';
import {
    reactivar,
    restablecerAcceso,
    revocarAcceso,
} from '@/routes/administracion/usuarios';
import { update as actualizarAvisos } from '@/routes/rh/expedientes/avisos';
import { update as actualizarDatosPersonales } from '@/routes/rh/expedientes/datos-personales';
import { show as showSolicitud } from '@/routes/rh/solicitudes';
import { edit as editSeguridad } from '@/routes/security';
import type {
    AltaDigitalResumenExpediente,
    DocumentoExpedienteItem,
    AvisosManualExpediente,
    ExpedienteColaborador,
    MovimientoLaboralItem,
    OnboardingItem,
    ResumenExpediente,
    SaldoVacaciones,
    SolicitudExpedienteItem,
    SolicitudVacacionesItem,
} from '@/types';

const props = defineProps<{
    esPropio: boolean;
    puedeEditar: boolean;
    puedeReactivar: boolean;
    puedeGestionarAcceso: boolean;
    puedeGestionarPassword: boolean;
    esCuentaPropia: boolean;
    puedeRevisarDocumentos: boolean;
    puedeVerExtraccion: boolean;
    puedeAplicarExtraccion: boolean;
    puedeReprocesarExtraccion: boolean;
    puedeIgnorarExtraccion: boolean;
    puedeGestionarAvisos: boolean;
    colaborador: ExpedienteColaborador;
    resumenExpediente: ResumenExpediente;
    documentosRequeridos: DocumentoExpedienteItem[];
    onboarding: OnboardingItem[];
    altaDigital: AltaDigitalResumenExpediente;
    avisosManual: AvisosManualExpediente;
    saldoVacaciones: SaldoVacaciones;
    solicitudesVacaciones: SolicitudVacacionesItem[];
    solicitudes: SolicitudExpedienteItem[];
    movimientosLaborales: MovimientoLaboralItem[];
}>();

const form = useForm({
    fecha_nacimiento: props.colaborador.fecha_nacimiento ?? '',
    curp: props.colaborador.curp ?? '',
    rfc: props.colaborador.rfc ?? '',
    nss: props.colaborador.nss ?? '',
    domicilio: props.colaborador.domicilio ?? '',
    correo_personal: props.colaborador.correo_personal ?? '',
    contacto_emergencia_nombre:
        props.colaborador.contacto_emergencia_nombre ?? '',
    contacto_emergencia_telefono:
        props.colaborador.contacto_emergencia_telefono ?? '',
});

function guardarDatosPersonales() {
    form.put(actualizarDatosPersonales.url(props.colaborador.id), {
        preserveScroll: true,
    });
}

const formAvisos = useForm({
    aviso_privacidad_aceptado:
        props.avisosManual?.aviso_privacidad_aceptado ?? false,
    consentimiento_datos_aceptado:
        props.avisosManual?.consentimiento_datos_aceptado ?? false,
});

function guardarAvisosManual() {
    formAvisos.put(actualizarAvisos.url(props.colaborador.id), {
        preserveScroll: true,
    });
}

const { mostrarExito, mostrarError } = useAlertas();

function reactivarColaborador() {
    router.post(
        reactivar.url(props.colaborador.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito('Colaborador reactivado correctamente.'),
            onError: () =>
                mostrarError('No fue posible reactivar al colaborador.'),
        },
    );
}

function revocarAccesoColaborador() {
    router.post(
        revocarAcceso.url(props.colaborador.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito(
                    'Acceso revocado. El colaborador sigue activo en la plantilla.',
                ),
            onError: () => mostrarError('No fue posible revocar el acceso.'),
        },
    );
}

function restablecerAccesoColaborador() {
    router.post(
        restablecerAcceso.url(props.colaborador.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Acceso restablecido.'),
            onError: () =>
                mostrarError('No fue posible restablecer el acceso.'),
        },
    );
}

const dialogoPasswordAbierto = ref(false);

const onboardingPorcentaje = computed(() => {
    if (props.onboarding.length === 0) {
        return 0;
    }

    const completados = props.onboarding.filter(
        (item) => item.completado,
    ).length;

    return Math.round((completados / props.onboarding.length) * 100);
});
</script>

<template>
    <Head :title="`Expediente de ${colaborador.name}`" />

    <div class="flex w-full min-w-0 flex-col gap-6 p-4 sm:p-6">
        <Card
            class="rounded-3xl border-border/60 shadow-sm transition-shadow hover:shadow-md"
        >
            <CardContent
                class="flex flex-col gap-4 sm:flex-row sm:items-center"
            >
                <Avatar
                    class="size-16 shrink-0 rounded-2xl ring-2 ring-border/60"
                >
                    <AvatarImage
                        v-if="colaborador.foto_url"
                        :src="colaborador.foto_url"
                        alt=""
                        class="object-cover"
                    />
                    <AvatarFallback
                        class="rounded-2xl bg-primary/10 text-primary"
                    >
                        <User class="size-7" />
                    </AvatarFallback>
                </Avatar>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-lg font-semibold">
                            {{ colaborador.name }} {{ colaborador.apellidos }}
                        </h1>
                        <EstadoBadge
                            :estado="colaborador.estatus"
                            :etiqueta="colaborador.deleted_at ? 'Baja' : undefined"
                        />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{
                            colaborador.numero_empleado ??
                            'Sin número de empleado'
                        }}
                        · {{ colaborador.puesto?.nombre ?? 'Sin puesto' }}
                    </p>
                    <div
                        class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                    >
                        <span class="flex items-center gap-1"
                            ><Building2 class="size-3.5" />{{
                                colaborador.empresa?.nombre ?? '—'
                            }}</span
                        >
                        <span class="flex items-center gap-1"
                            ><MapPinned class="size-3.5" />{{
                                colaborador.sucursal?.nombre ?? '—'
                            }}</span
                        >
                        <span class="flex items-center gap-1"
                            ><Briefcase class="size-3.5" />{{
                                colaborador.departamento?.nombre ?? '—'
                            }}</span
                        >
                        <span class="flex items-center gap-1">
                            Acceso al sistema:
                            <span
                                :class="
                                    colaborador.acceso_bloqueado_en
                                        ? 'font-medium text-destructive'
                                        : 'font-medium text-emerald-600 dark:text-emerald-400'
                                "
                            >
                                {{
                                    colaborador.acceso_bloqueado_en
                                        ? 'No (bloqueado)'
                                        : 'Sí'
                                }}
                            </span>
                        </span>
                    </div>
                </div>

                <div class="flex flex-col items-end gap-2">
                    <div class="flex flex-col items-end gap-1">
                        <span class="text-xs text-muted-foreground"
                            >Expediente</span
                        >
                        <div class="flex items-center gap-2">
                            <Progress
                                :model-value="resumenExpediente.porcentaje"
                                class="h-2 w-28"
                            />
                            <span class="text-sm font-semibold tabular-nums"
                                >{{ resumenExpediente.porcentaje }}%</span
                            >
                        </div>
                    </div>
                    <Button
                        v-if="colaborador.deleted_at && puedeReactivar"
                        size="sm"
                        variant="success"
                        @click="reactivarColaborador"
                    >
                        Reactivar colaborador
                    </Button>
                    <p
                        v-else-if="colaborador.deleted_at"
                        class="text-xs text-muted-foreground"
                    >
                        Baja — solo un administrador puede reactivar.
                    </p>
                    <template v-else-if="puedeGestionarAcceso">
                        <Button
                            v-if="colaborador.acceso_bloqueado_en"
                            size="sm"
                            variant="success"
                            @click="restablecerAccesoColaborador"
                        >
                            Restablecer acceso
                        </Button>
                        <Button
                            v-else
                            size="sm"
                            variant="outline"
                            @click="revocarAccesoColaborador"
                        >
                            Revocar acceso
                        </Button>
                    </template>
                </div>
            </CardContent>
        </Card>

        <Tabs default-value="resumen">
            <TabsList>
                <TabsTrigger value="resumen">Resumen</TabsTrigger>
                <TabsTrigger value="personales">Datos personales</TabsTrigger>
                <TabsTrigger value="laborales">Datos laborales</TabsTrigger>
                <TabsTrigger v-if="!esPropio" value="usuario">Usuario</TabsTrigger>
                <TabsTrigger value="documentos">Documentos</TabsTrigger>
                <TabsTrigger value="onboarding">Onboarding</TabsTrigger>
                <TabsTrigger value="avisos">Avisos</TabsTrigger>
                <TabsTrigger value="vacaciones">Vacaciones</TabsTrigger>
                <TabsTrigger value="solicitudes">Solicitudes</TabsTrigger>
                <TabsTrigger value="historial">Historial RH</TabsTrigger>
            </TabsList>

            <TabsContent value="resumen" class="pt-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Card class="rounded-2xl border-border/60">
                        <CardContent class="pt-6 text-center">
                            <p class="text-2xl font-semibold tabular-nums">
                                {{ resumenExpediente.requeridos_aprobados }}/{{
                                    resumenExpediente.requeridos_total
                                }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Documentos requeridos aprobados
                            </p>
                        </CardContent>
                    </Card>
                    <Card class="rounded-2xl border-border/60">
                        <CardContent class="pt-6 text-center">
                            <p
                                class="text-2xl font-semibold text-warning tabular-nums"
                            >
                                {{ resumenExpediente.pendientes }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Pendientes de cargar/revisar
                            </p>
                        </CardContent>
                    </Card>
                    <Card class="rounded-2xl border-border/60">
                        <CardContent class="pt-6 text-center">
                            <p
                                class="text-2xl font-semibold text-destructive tabular-nums"
                            >
                                {{ resumenExpediente.rechazados }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Rechazados o con corrección pendiente
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <CampoInfo
                        :icono="Mail"
                        etiqueta="Correo"
                        :valor="colaborador.email"
                    />
                    <CampoInfo
                        :icono="Phone"
                        etiqueta="Teléfono"
                        :valor="colaborador.telefono"
                    />
                    <CampoInfo :icono="UserRound" etiqueta="Jefe directo">
                        <template v-if="colaborador.jefe">
                            {{ colaborador.jefe.name }}
                            {{ colaborador.jefe.apellidos }}
                        </template>
                        <template v-else>Sin asignar</template>
                    </CampoInfo>
                    <CampoInfo :icono="Building2" etiqueta="Empresa">
                        {{ colaborador.empresa?.nombre ?? 'Sin asignar' }}
                    </CampoInfo>
                </div>
            </TabsContent>

            <TabsContent value="personales" class="pt-4">
                <Card class="rounded-2xl border-border/60">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <IdCard class="size-4" />
                            Datos personales
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            class="flex flex-col gap-6"
                            @submit.prevent="guardarDatosPersonales"
                        >
                            <div class="flex flex-col gap-3">
                                <p
                                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    <Fingerprint class="size-3.5" />
                                    Identificación
                                </p>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="fecha_nacimiento"
                                            >Fecha de nacimiento</Label
                                        >
                                        <DatePicker
                                            id="fecha_nacimiento"
                                            v-model="form.fecha_nacimiento"
                                            :disabled="!puedeEditar"
                                        />
                                        <InputError
                                            :message="form.errors.fecha_nacimiento"
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="curp">CURP</Label>
                                        <Input
                                            id="curp"
                                            v-model="form.curp"
                                            class="uppercase"
                                            maxlength="18"
                                            :disabled="!puedeEditar"
                                        />
                                        <InputError :message="form.errors.curp" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="rfc">RFC</Label>
                                        <Input
                                            id="rfc"
                                            v-model="form.rfc"
                                            class="uppercase"
                                            maxlength="13"
                                            :disabled="!puedeEditar"
                                        />
                                        <InputError :message="form.errors.rfc" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="nss">NSS</Label>
                                        <Input
                                            id="nss"
                                            v-model="form.nss"
                                            maxlength="11"
                                            :disabled="!puedeEditar"
                                        />
                                        <InputError :message="form.errors.nss" />
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3 border-t pt-4">
                                <p
                                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    <Home class="size-3.5" />
                                    Contacto
                                </p>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2 sm:col-span-2">
                                        <Label for="domicilio">Domicilio</Label>
                                        <Input
                                            id="domicilio"
                                            v-model="form.domicilio"
                                            :disabled="!puedeEditar"
                                        />
                                        <InputError :message="form.errors.domicilio" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="correo_personal"
                                            >Correo personal</Label
                                        >
                                        <Input
                                            id="correo_personal"
                                            v-model="form.correo_personal"
                                            type="email"
                                            :disabled="!puedeEditar"
                                        />
                                        <InputError
                                            :message="form.errors.correo_personal"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3 border-t pt-4">
                                <p
                                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    <PhoneCall class="size-3.5" />
                                    Contacto de emergencia
                                </p>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="contacto_emergencia_nombre"
                                            >Nombre</Label
                                        >
                                        <Input
                                            id="contacto_emergencia_nombre"
                                            v-model="form.contacto_emergencia_nombre"
                                            :disabled="!puedeEditar"
                                        />
                                        <InputError
                                            :message="
                                                form.errors.contacto_emergencia_nombre
                                            "
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="contacto_emergencia_telefono"
                                            >Teléfono</Label
                                        >
                                        <Input
                                            id="contacto_emergencia_telefono"
                                            v-model="
                                                form.contacto_emergencia_telefono
                                            "
                                            :disabled="!puedeEditar"
                                        />
                                        <InputError
                                            :message="
                                                form.errors
                                                    .contacto_emergencia_telefono
                                            "
                                        />
                                    </div>
                                </div>
                            </div>

                            <div v-if="puedeEditar">
                                <Button
                                    type="submit"
                                    :disabled="form.processing"
                                >
                                    <Spinner v-if="form.processing" />
                                    Guardar datos personales
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="laborales" class="pt-4">
                <Card class="rounded-2xl border-border/60">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Briefcase class="size-4" />
                            Datos laborales
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-6">
                        <div class="flex flex-col gap-3">
                            <p
                                class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                <Layers class="size-3.5" />
                                Ubicación organizacional
                            </p>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <CampoInfo :icono="Building2" etiqueta="Empresa">
                                    {{ colaborador.empresa?.nombre ?? '—' }}
                                </CampoInfo>
                                <CampoInfo :icono="MapPinned" etiqueta="Sucursal">
                                    {{ colaborador.sucursal?.nombre ?? '—' }}
                                </CampoInfo>
                                <CampoInfo :icono="Briefcase" etiqueta="Departamento">
                                    {{ colaborador.departamento?.nombre ?? '—' }}
                                </CampoInfo>
                                <CampoInfo :icono="BadgeCheck" etiqueta="Puesto">
                                    {{ colaborador.puesto?.nombre ?? '—' }}
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
                                <CampoInfo :icono="CalendarDays" etiqueta="Fecha de ingreso">
                                    {{ colaborador.fecha_ingreso ?? '—' }}
                                </CampoInfo>
                                <CampoInfo etiqueta="Estado laboral">
                                    <EstadoBadge :estado="colaborador.estatus" />
                                </CampoInfo>
                                <CampoInfo etiqueta="Estatus IMSS">
                                    <EstadoBadge :estado="colaborador.estatus_imss" />
                                </CampoInfo>
                                <CampoInfo :icono="CalendarClock" etiqueta="Fecha alta IMSS">
                                    {{ colaborador.fecha_alta_imss ?? '—' }}
                                </CampoInfo>
                                <CampoInfo
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
                                        {{ colaborador.periodo_prueba_inicio }} —
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
                            Puesto, sucursal y departamento se editan desde
                            Administración → Usuarios. La cuenta de acceso
                            (correo, roles, contraseña) está en la pestaña
                            «Usuario».
                        </p>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent v-if="!esPropio" value="usuario" class="pt-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Card class="rounded-2xl border-border/60">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base">
                                <UserCog class="size-4" />
                                Cuenta de acceso
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="grid gap-4 text-sm">
                            <CampoInfo
                                :icono="Mail"
                                etiqueta="Correo (usuario de acceso)"
                                :valor="colaborador.email"
                            />
                            <div>
                                <p class="text-xs text-muted-foreground">Roles</p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <Badge
                                        v-for="rol in colaborador.roles"
                                        :key="rol"
                                        variant="outline"
                                        class="capitalize"
                                    >
                                        {{ rol.replace(/_/g, ' ') }}
                                    </Badge>
                                    <span
                                        v-if="colaborador.roles.length === 0"
                                        class="text-xs text-muted-foreground"
                                        >Sin roles asignados</span
                                    >
                                </div>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">
                                    Acceso al sistema
                                </p>
                                <p
                                    class="flex items-center gap-1.5 font-medium"
                                    :class="
                                        colaborador.acceso_bloqueado_en
                                            ? 'text-destructive'
                                            : 'text-[var(--success)]'
                                    "
                                >
                                    <Lock
                                        v-if="colaborador.acceso_bloqueado_en"
                                        class="size-3.5"
                                    />
                                    <ShieldCheck v-else class="size-3.5" />
                                    {{
                                        colaborador.acceso_bloqueado_en
                                            ? 'Bloqueado'
                                            : 'Activo'
                                    }}
                                </p>
                            </div>

                            <div
                                v-if="puedeGestionarAcceso"
                                class="flex flex-wrap gap-2 border-t pt-3"
                            >
                                <Button
                                    v-if="colaborador.acceso_bloqueado_en"
                                    size="sm"
                                    variant="success"
                                    @click="restablecerAccesoColaborador"
                                >
                                    <Unlock class="size-3.5" />
                                    Restablecer acceso
                                </Button>
                                <Button
                                    v-else
                                    size="sm"
                                    variant="outline"
                                    @click="revocarAccesoColaborador"
                                >
                                    <Lock class="size-3.5" />
                                    Quitar acceso
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card class="rounded-2xl border-border/60">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base">
                                <KeyRound class="size-4" />
                                Contraseña
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-3 text-sm">
                            <p class="text-muted-foreground">
                                Por seguridad, la contraseña se guarda cifrada
                                y no se puede consultar la actual — ni un
                                administrador puede leerla. Solo puedes
                                establecer una nueva.
                            </p>
                            <Button
                                v-if="puedeGestionarPassword"
                                size="sm"
                                class="w-fit"
                                @click="dialogoPasswordAbierto = true"
                            >
                                <KeyRound class="size-3.5" />
                                Establecer contraseña nueva
                            </Button>
                            <p
                                v-else-if="esCuentaPropia"
                                class="text-xs text-muted-foreground"
                            >
                                Es tu propia cuenta: no puedes restablecerte
                                la contraseña desde aquí. Usa
                                <Link
                                    :href="editSeguridad.url()"
                                    class="font-medium text-primary underline underline-offset-2"
                                    >Configuración → Seguridad</Link
                                >.
                            </p>
                            <p
                                v-else
                                class="text-xs text-muted-foreground"
                            >
                                No tienes permiso para cambiar la contraseña
                                de este colaborador.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </TabsContent>

            <TabsContent value="documentos" class="pt-4">
                <ExpedienteDocumentos
                    :colaborador-id="colaborador.id"
                    :documentos="documentosRequeridos"
                    :puede-subir="esPropio || puedeEditar"
                    :puede-revisar="puedeRevisarDocumentos"
                    :puede-ver-extraccion="puedeVerExtraccion"
                    :puede-aplicar-extraccion="puedeAplicarExtraccion"
                    :puede-reprocesar-extraccion="puedeReprocesarExtraccion"
                    :puede-ignorar-extraccion="puedeIgnorarExtraccion"
                />
            </TabsContent>

            <TabsContent value="onboarding" class="pt-4">
                <Card class="rounded-2xl border-border/60">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <ListChecks class="size-4" />
                            Checklist de incorporación
                        </CardTitle>
                        <div class="flex items-center gap-2 pt-1">
                            <Progress
                                :model-value="onboardingPorcentaje"
                                class="h-2 flex-1"
                            />
                            <span
                                class="text-xs font-semibold tabular-nums text-muted-foreground"
                                >{{ onboardingPorcentaje }}%</span
                            >
                        </div>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-2">
                        <div
                            v-for="item in onboarding"
                            :key="item.clave"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm"
                            :class="
                                item.completado
                                    ? 'bg-[var(--success)]/5'
                                    : 'bg-muted/40'
                            "
                        >
                            <CheckCircle2
                                v-if="item.completado"
                                class="size-4 shrink-0 text-[var(--success)]"
                            />
                            <CircleDashed
                                v-else
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span
                                :class="{
                                    'text-muted-foreground': !item.completado,
                                }"
                                >{{ item.etiqueta }}</span
                            >
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="avisos" class="pt-4">
                <Card v-if="altaDigital" class="rounded-2xl border-border/60">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <ScrollText class="size-4" />
                            Avisos y consentimientos
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="grid gap-3 text-sm">
                        <p class="flex items-center gap-1.5">
                            <CheckCircle2
                                v-if="altaDigital.aviso_privacidad_aceptado"
                                class="size-4 text-[var(--success)]"
                            />
                            <CircleDashed
                                v-else
                                class="size-4 text-muted-foreground"
                            />
                            Aviso de privacidad
                            <span
                                v-if="altaDigital.aviso_privacidad_aceptado_en"
                                class="text-xs text-muted-foreground"
                                >·
                                {{
                                    new Date(
                                        altaDigital.aviso_privacidad_aceptado_en,
                                    ).toLocaleString()
                                }}</span
                            >
                        </p>
                        <p class="flex items-center gap-1.5">
                            <CheckCircle2
                                v-if="altaDigital.consentimiento_datos_aceptado"
                                class="size-4 text-[var(--success)]"
                            />
                            <CircleDashed
                                v-else
                                class="size-4 text-muted-foreground"
                            />
                            Consentimiento de datos
                            <span
                                v-if="
                                    altaDigital.consentimiento_datos_aceptado_en
                                "
                                class="text-xs text-muted-foreground"
                                >·
                                {{
                                    new Date(
                                        altaDigital.consentimiento_datos_aceptado_en,
                                    ).toLocaleString()
                                }}</span
                            >
                        </p>
                    </CardContent>
                </Card>

                <Card v-else class="rounded-2xl border-border/60">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <ScrollText class="size-4" />
                            Avisos y consentimientos
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p class="rounded-lg bg-muted/60 px-3 py-2 text-xs text-muted-foreground">
                            Este colaborador no tiene un Alta digital
                            registrada (se dio de alta directamente), así que
                            no hay firma electrónica de por medio. Puedes
                            registrar aquí manualmente si ya aceptó el aviso
                            de privacidad y el consentimiento de datos (por
                            ejemplo, en papel o por correo).
                        </p>

                        <form
                            class="flex flex-col gap-3"
                            @submit.prevent="guardarAvisosManual"
                        >
                            <label
                                class="flex items-start gap-2.5 rounded-xl border border-border/50 px-3 py-2.5 text-sm"
                            >
                                <Checkbox
                                    v-model="formAvisos.aviso_privacidad_aceptado"
                                    :disabled="!puedeGestionarAvisos"
                                />
                                <span>
                                    <span class="font-medium">Aviso de privacidad aceptado</span>
                                    <span
                                        v-if="avisosManual?.aviso_privacidad_aceptado_en"
                                        class="block text-xs text-muted-foreground"
                                        >Registrado el
                                        {{
                                            new Date(
                                                avisosManual.aviso_privacidad_aceptado_en,
                                            ).toLocaleString()
                                        }}</span
                                    >
                                </span>
                            </label>

                            <label
                                class="flex items-start gap-2.5 rounded-xl border border-border/50 px-3 py-2.5 text-sm"
                            >
                                <Checkbox
                                    v-model="
                                        formAvisos.consentimiento_datos_aceptado
                                    "
                                    :disabled="!puedeGestionarAvisos"
                                />
                                <span>
                                    <span class="font-medium">Consentimiento de datos aceptado</span>
                                    <span
                                        v-if="
                                            avisosManual?.consentimiento_datos_aceptado_en
                                        "
                                        class="block text-xs text-muted-foreground"
                                        >Registrado el
                                        {{
                                            new Date(
                                                avisosManual.consentimiento_datos_aceptado_en,
                                            ).toLocaleString()
                                        }}</span
                                    >
                                </span>
                            </label>

                            <p
                                v-if="avisosManual?.registrado_por"
                                class="text-xs text-muted-foreground"
                            >
                                Última vez registrado por
                                {{ avisosManual.registrado_por }}.
                            </p>

                            <Button
                                v-if="puedeGestionarAvisos"
                                type="submit"
                                size="sm"
                                class="w-fit"
                                :disabled="formAvisos.processing"
                            >
                                <Spinner v-if="formAvisos.processing" />
                                Guardar avisos
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </TabsContent>
            <TabsContent value="vacaciones" class="pt-4">
                <Card class="rounded-2xl border-border/60">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Calendar class="size-4" />
                            Vacaciones
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="rounded-xl border p-3 text-center">
                                <p class="text-lg font-semibold">
                                    {{ saldoVacaciones.dias_generados }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Generados
                                </p>
                            </div>
                            <div class="rounded-xl border p-3 text-center">
                                <p class="text-lg font-semibold">
                                    {{ saldoVacaciones.dias_usados }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Usados
                                </p>
                            </div>
                            <div class="rounded-xl border p-3 text-center">
                                <p class="text-lg font-semibold">
                                    {{ saldoVacaciones.dias_en_solicitud }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    En solicitud
                                </p>
                            </div>
                            <div class="rounded-xl border p-3 text-center">
                                <p class="text-lg font-semibold">
                                    {{ saldoVacaciones.dias_disponibles }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Disponibles
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <p
                                v-for="solicitud in solicitudesVacaciones"
                                :key="solicitud.id"
                                class="flex items-center justify-between rounded-lg border p-2 text-sm"
                            >
                                <span
                                    >{{ solicitud.fecha_inicio }} —
                                    {{ solicitud.fecha_fin }} ({{
                                        solicitud.dias_solicitados
                                    }}
                                    días)</span
                                >
                                <EstadoBadge :estado="solicitud.estado" />
                            </p>
                            <p
                                v-if="!solicitudesVacaciones.length"
                                class="text-sm text-muted-foreground"
                            >
                                Sin solicitudes de vacaciones registradas.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>
            <TabsContent value="solicitudes" class="pt-4">
                <Card class="rounded-2xl border-border/60">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base">
                            <ClipboardList class="size-4" />
                            Solicitudes
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-2">
                        <Link
                            v-for="solicitud in solicitudes"
                            :key="solicitud.id"
                            :href="showSolicitud.url(solicitud.id)"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border/60 p-3 text-sm transition-colors hover:border-primary/40 hover:bg-accent/40"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ solicitud.folio }} ·
                                    {{ solicitud.tipo_etiqueta }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ solicitud.motivo }}
                                </p>
                            </div>
                            <EstadoBadge :estado="solicitud.estado" />
                        </Link>
                        <p
                            v-if="!solicitudes.length"
                            class="text-sm text-muted-foreground"
                        >
                            Sin solicitudes registradas.
                        </p>
                    </CardContent>
                </Card>
            </TabsContent>
            <TabsContent value="historial" class="pt-4">
                <MovimientosLaboralesTimeline
                    :movimientos="movimientosLaborales"
                />
            </TabsContent>
        </Tabs>
    </div>

    <EstablecerPasswordDialog
        v-if="dialogoPasswordAbierto"
        v-model:open="dialogoPasswordAbierto"
        :colaborador-id="colaborador.id"
        :colaborador-nombre="`${colaborador.name} ${colaborador.apellidos ?? ''}`"
    />
</template>
