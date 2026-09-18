<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Briefcase,
    Building2,
    Calendar,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    CircleDashed,
    ClipboardList,
    Clock,
    Eye,
    Fingerprint,
    FolderOpen,
    Hexagon,
    History,
    Home,
    Hourglass,
    IdCard,
    KeyRound,
    LayoutDashboard,
    ListChecks,
    Lock,
    Mail,
    MapPinned,
    Network,
    Pencil,
    Phone,
    PhoneCall,
    Plus,
    Receipt,
    ScrollText,
    ShieldCheck,
    Sparkles,
    Unlock,
    User,
    UserCog,
    UserPlus,
    UserRound,
    Wallet,
    X,
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
import { Combobox } from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAlertas } from '@/composables/useAlertas';
import {
    restablecerAcceso,
    revocarAcceso,
    store as crearCuentaUsuario,
    update as actualizarCuentaUsuario,
} from '@/routes/administracion/usuarios';
import { darDeBaja, reactivar } from '@/routes/rh/expedientes';
import { update as actualizarAvisos } from '@/routes/rh/expedientes/avisos';
import { update as actualizarDatosLaborales } from '@/routes/rh/expedientes/datos-laborales';
import { update as actualizarDatosPersonales } from '@/routes/rh/expedientes/datos-personales';
import { store as registrarMovimientoPrestamo } from '@/routes/rh/expedientes/prestamos/movimientos';
import {
    descargar as descargarRecibo,
    store as generarReciboNomina,
} from '@/routes/rh/expedientes/recibos-nomina';
import { show as showSolicitud } from '@/routes/rh/solicitudes';
import { edit as editSeguridad } from '@/routes/security';
import type {
    AltaDigitalResumenExpediente,
    DocumentoExpedienteItem,
    AvisosManualExpediente,
    ExpedienteColaborador,
    MovimientoLaboralItem,
    OnboardingItem,
    PrestamoItem,
    ReciboNominaItem,
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
    puedeEditarCuenta: boolean;
    puedeCrearCuenta: boolean;
    puedeEditarLaborales: boolean;
    rolesDisponibles: string[];
    empresasDisponibles: { id: number; nombre: string }[];
    sucursalesDisponibles: { id: number; nombre: string; empresa_id: number | null }[];
    departamentosDisponibles: { id: number; nombre: string }[];
    puestosDisponibles: { id: number; nombre: string }[];
    jefesDisponibles: { id: number; name: string; apellidos: string | null; numero_empleado: string | null }[];
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
    avisoPrivacidadTexto: string;
    consentimientoDatosTexto: string;
    saldoVacaciones: SaldoVacaciones;
    solicitudesVacaciones: SolicitudVacacionesItem[];
    solicitudes: SolicitudExpedienteItem[];
    movimientosLaborales: MovimientoLaboralItem[];
    recibosNomina: ReciboNominaItem[];
    prestamos: PrestamoItem[];
}>();

const form = useForm({
    fecha_nacimiento: props.colaborador.fecha_nacimiento ?? '',
    telefono: props.colaborador.telefono ?? '',
    telefono_corporativo: props.colaborador.telefono_corporativo ?? '',
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

const { mostrarExito, mostrarError, confirmarEliminacion } = useAlertas();

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

async function darDeBajaColaborador() {
    const confirmado = await confirmarEliminacion(
        `a «${props.colaborador.name} ${props.colaborador.apellidos ?? ''}» — esto termina su relación laboral (baja), no solo su acceso`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(darDeBaja.url(props.colaborador.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('El colaborador se dio de baja correctamente.'),
        onError: () => mostrarError('No fue posible dar de baja al colaborador.'),
    });
}

function revocarAccesoColaborador() {
    if (props.colaborador.usuario_id === null) {
        return;
    }

    router.post(
        revocarAcceso.url(props.colaborador.usuario_id),
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
    if (props.colaborador.usuario_id === null) {
        return;
    }

    router.post(
        restablecerAcceso.url(props.colaborador.usuario_id),
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

const anilloExpediente = computed(
    () => `conic-gradient(var(--primary) ${props.resumenExpediente.porcentaje * 3.6}deg, var(--primary-foreground, var(--muted)) 0deg)`,
);

// --- Cuenta de acceso: crear (colaborador sin cuenta todavía) ---
function alternarRol(lista: string[], rol: string, marcado: boolean): string[] {
    return marcado ? [...new Set([...lista, rol])] : lista.filter((r) => r !== rol);
}

const formCrearCuenta = useForm({
    email: props.colaborador.correo_personal ?? '',
    roles: [] as string[],
});

function crearCuenta() {
    formCrearCuenta
        .transform((datos) => ({
            colaborador_id: props.colaborador.id,
            email: datos.email,
            roles: datos.roles,
        }))
        .post(crearCuentaUsuario.url(), {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito(
                    'Cuenta creada. Se envió un correo para que establezca su contraseña.',
                ),
            onError: () => mostrarError('No se pudo crear la cuenta de acceso.'),
        });
}

// --- Cuenta de acceso: editar correo/roles (colaborador con cuenta) ---
const editandoCuenta = ref(false);

const formCuenta = useForm({
    email: props.colaborador.email ?? '',
    roles: [...props.colaborador.roles] as string[],
});

function iniciarEdicionCuenta() {
    formCuenta.email = props.colaborador.email ?? '';
    formCuenta.roles = [...props.colaborador.roles];
    editandoCuenta.value = true;
}

function guardarCuenta() {
    if (props.colaborador.usuario_id === null) {
        return;
    }

    formCuenta
        .transform((datos) => ({ email: datos.email, roles: datos.roles }))
        .put(actualizarCuentaUsuario.url(props.colaborador.usuario_id), {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito('Cuenta actualizada correctamente.');
                editandoCuenta.value = false;
            },
            onError: () => mostrarError('No se pudo actualizar la cuenta.'),
        });
}

// --- Avisos: mostrar el texto real detrás del checkbox ---
const avisoDialogAbierto = ref(false);
const avisoDialogTitulo = ref('');
const avisoDialogTexto = ref('');

function abrirAviso(tipo: 'privacidad' | 'datos') {
    avisoDialogTitulo.value =
        tipo === 'privacidad' ? 'Aviso de privacidad' : 'Consentimiento de datos';
    avisoDialogTexto.value =
        tipo === 'privacidad'
            ? props.avisoPrivacidadTexto
            : props.consentimientoDatosTexto;
    avisoDialogAbierto.value = true;
}

// --- Datos laborales: empresa/sucursal/departamento/puesto/jefe/sueldo ---
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
    jefe_id: props.colaborador.jefe ? String(props.colaborador.jefe.id) : '',
    sueldo_mensual: props.colaborador.sueldo_mensual ?? '',
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
        (sucursal) => String(sucursal.id) === formLaborales.sucursal_principal_id,
    );

    if (!sigueDisponible) {
        formLaborales.sucursal_principal_id = '';
    }
}

const opcionesJefe = computed(() =>
    props.jefesDisponibles.map((jefe) => ({
        value: String(jefe.id),
        label: `${jefe.numero_empleado ? `${jefe.numero_empleado} — ` : ''}${jefe.name} ${jefe.apellidos ?? ''}`.trim(),
    })),
);

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
    formLaborales.jefe_id = props.colaborador.jefe
        ? String(props.colaborador.jefe.id)
        : '';
    formLaborales.sueldo_mensual = props.colaborador.sueldo_mensual ?? '';
    formLaborales.motivo = '';
    editandoLaborales.value = true;
}

function guardarLaborales() {
    formLaborales
        .transform((datos) => ({
            sucursal_principal_id: datos.sucursal_principal_id || null,
            departamento_id: datos.departamento_id || null,
            puesto_id: datos.puesto_id || null,
            jefe_id: datos.jefe_id || null,
            sueldo_mensual: datos.sueldo_mensual || null,
            motivo: datos.motivo || null,
        }))
        .put(actualizarDatosLaborales.url(props.colaborador.id), {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito('Datos laborales actualizados correctamente.');
                editandoLaborales.value = false;
            },
            onError: () => mostrarError('No se pudieron actualizar los datos laborales.'),
        });
}

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

function moneda(valor: number): string {
    return valor.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    });
}

// --- Recibos de nómina (ver App\Services\Nomina\ReciboNominaService) ---

const prestamoActivo = computed(
    () => props.prestamos.find((prestamo) => prestamo.estado === 'activo') ?? null,
);

const dialogoReciboAbierto = ref(false);

type ConceptoForm = { concepto: string; monto: number; tipo?: string; prestamo_id?: number };

const formRecibo = useForm({
    periodo_inicio: '',
    periodo_fin: '',
    fecha_pago: '',
    percepciones: [] as ConceptoForm[],
    deducciones: [] as ConceptoForm[],
});

function abrirDialogoRecibo() {
    formRecibo.reset();
    formRecibo.clearErrors();
    formRecibo.percepciones = [];

    // Sugerencia automática (RH puede quitarla/ajustarla antes de
    // confirmar): línea de pago del préstamo activo con el pago
    // programado — ver App\Services\Nomina\ReciboNominaService::generar().
    formRecibo.deducciones = prestamoActivo.value
        ? [
              {
                  concepto: 'Pago de préstamo interno',
                  monto: Number(prestamoActivo.value.pago_programado),
                  tipo: 'prestamo',
                  prestamo_id: prestamoActivo.value.id,
              },
          ]
        : [];

    dialogoReciboAbierto.value = true;
}

function agregarPercepcion() {
    formRecibo.percepciones.push({ concepto: '', monto: 0 });
}

function agregarDeduccion() {
    formRecibo.deducciones.push({ concepto: '', monto: 0 });
}

function generarRecibo() {
    formRecibo.post(generarReciboNomina.url(props.colaborador.id), {
        preserveScroll: true,
        onSuccess: () => {
            mostrarExito('Recibo de nómina generado correctamente.');
            dialogoReciboAbierto.value = false;
        },
        onError: () => mostrarError('No fue posible generar el recibo de nómina.'),
    });
}

function descargarReciboUrl(reciboId: number): string {
    return descargarRecibo.url(reciboId);
}

// --- Préstamos (ver App\Services\Nomina\PrestamoService) ---

const formPago = useForm({
    monto: 0,
    tipo: 'manual',
});

function registrarPago(prestamoId: number) {
    formPago.post(registrarMovimientoPrestamo.url(prestamoId), {
        preserveScroll: true,
        onSuccess: () => {
            mostrarExito('Movimiento del préstamo registrado correctamente.');
            formPago.reset();
        },
        onError: () => mostrarError('No fue posible registrar el movimiento.'),
    });
}
</script>

<template>
    <Head :title="`Expediente de ${colaborador.name}`" />

    <div class="flex w-full min-w-0 flex-col gap-6 p-4">
        <Card
            class="overflow-hidden rounded-3xl border-border/60 bg-gradient-to-br from-primary/10 via-card to-card shadow-sm transition-shadow hover:shadow-md"
        >
            <CardContent
                class="flex flex-col gap-4 sm:flex-row sm:items-center"
            >
                <Avatar
                    class="size-20 shrink-0 rounded-2xl ring-2 ring-primary/30"
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
                        <User class="size-9" />
                    </AvatarFallback>
                </Avatar>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-lg font-semibold">
                            {{ colaborador.name }} {{ colaborador.apellidos }}
                        </h1>
                        <EstadoBadge :estado="colaborador.estatus" />
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

                <div class="flex flex-col items-end gap-3 sm:flex-row sm:items-center">
                    <div class="flex flex-col items-end gap-1">
                        <span class="text-xs text-muted-foreground">Expediente</span>
                        <div
                            class="relative flex size-14 items-center justify-center rounded-full"
                            :style="{ background: anilloExpediente }"
                        >
                            <div
                                class="flex size-11 items-center justify-center rounded-full bg-card text-xs font-semibold tabular-nums"
                            >
                                {{ resumenExpediente.porcentaje }}%
                            </div>
                        </div>
                    </div>

                    <Button
                        v-if="colaborador.estatus === 'inactivo' && puedeReactivar"
                        size="sm"
                        variant="success"
                        @click="reactivarColaborador"
                    >
                        Reactivar colaborador
                    </Button>
                    <p
                        v-else-if="colaborador.estatus === 'inactivo'"
                        class="text-xs text-muted-foreground"
                    >
                        Baja — solo un administrador puede reactivar.
                    </p>
                    <DropdownMenu v-else-if="puedeGestionarAcceso">
                        <DropdownMenuTrigger as-child>
                            <Button size="sm" variant="outline">
                                Más acciones
                                <ChevronDown class="size-3.5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-80">
                            <template v-if="colaborador.tiene_cuenta">
                                <DropdownMenuItem
                                    v-if="colaborador.acceso_bloqueado_en"
                                    class="flex-col items-start gap-0.5 py-2"
                                    @select="restablecerAccesoColaborador"
                                >
                                    <span class="flex items-center gap-1.5 font-medium">
                                        <Unlock class="size-3.5" />
                                        Restablecer acceso
                                    </span>
                                    <span class="text-xs whitespace-normal text-muted-foreground">
                                        Vuelve a permitir el inicio de sesión. El colaborador nunca dejó de estar activo en la plantilla.
                                    </span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-else
                                    class="flex-col items-start gap-0.5 py-2"
                                    @select="revocarAccesoColaborador"
                                >
                                    <span class="flex items-center gap-1.5 font-medium">
                                        <Lock class="size-3.5" />
                                        Revocar acceso
                                    </span>
                                    <span class="text-xs whitespace-normal text-muted-foreground">
                                        Solo bloquea el inicio de sesión (ej. incapacidad, suspensión temporal). Sigue activo en la plantilla — su empleo no cambia.
                                    </span>
                                </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem
                                variant="destructive"
                                class="flex-col items-start gap-0.5 py-2"
                                @select="darDeBajaColaborador"
                            >
                                <span class="font-medium">Dar de baja</span>
                                <span class="text-xs whitespace-normal opacity-80">
                                    Termina la relación laboral y libera la plaza en headcount/vacantes. Se conserva todo el historial — nunca se borra nada (baja lógica).
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </CardContent>
        </Card>

        <Tabs default-value="resumen" orientation="vertical" class="items-start gap-4 lg:flex-row lg:gap-6">
            <TabsList
                class="h-auto w-full flex-row justify-start gap-1 overflow-x-auto bg-muted/60 p-1.5 lg:w-56 lg:shrink-0 lg:flex-col lg:items-stretch lg:gap-0.5 lg:overflow-visible lg:rounded-2xl lg:p-2"
            >
                <TabsTrigger value="resumen" class="justify-start gap-2 lg:w-full">
                    <LayoutDashboard class="size-4" /> Resumen
                </TabsTrigger>
                <TabsTrigger value="personales" class="justify-start gap-2 lg:w-full">
                    <IdCard class="size-4" /> Datos personales
                </TabsTrigger>
                <TabsTrigger value="laborales" class="justify-start gap-2 lg:w-full">
                    <Briefcase class="size-4" /> Datos laborales
                </TabsTrigger>
                <TabsTrigger v-if="!esPropio" value="cuenta" class="justify-start gap-2 lg:w-full">
                    <UserCog class="size-4" /> Cuenta
                </TabsTrigger>
                <TabsTrigger value="documentos" class="justify-start gap-2 lg:w-full">
                    <FolderOpen class="size-4" /> Documentos
                </TabsTrigger>
                <TabsTrigger value="onboarding" class="justify-start gap-2 lg:w-full">
                    <ListChecks class="size-4" /> Onboarding
                </TabsTrigger>
                <TabsTrigger value="avisos" class="justify-start gap-2 lg:w-full">
                    <ScrollText class="size-4" /> Avisos
                </TabsTrigger>
                <TabsTrigger value="vacaciones" class="justify-start gap-2 lg:w-full">
                    <Calendar class="size-4" /> Vacaciones
                </TabsTrigger>
                <TabsTrigger value="recibos" class="justify-start gap-2 lg:w-full">
                    <Receipt class="size-4" /> Recibos de nómina
                </TabsTrigger>
                <TabsTrigger value="prestamos" class="justify-start gap-2 lg:w-full">
                    <Wallet class="size-4" /> Préstamos
                </TabsTrigger>
                <TabsTrigger value="solicitudes" class="justify-start gap-2 lg:w-full">
                    <ClipboardList class="size-4" /> Solicitudes
                </TabsTrigger>
                <TabsTrigger value="historial" class="justify-start gap-2 lg:w-full">
                    <History class="size-4" /> Historial RH
                </TabsTrigger>
            </TabsList>

            <div class="min-w-0 flex-1">
                <TabsContent value="resumen">
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

                <TabsContent value="personales">
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
                                        <div class="grid gap-2">
                                            <Label for="telefono">Teléfono personal</Label>
                                            <Input
                                                id="telefono"
                                                v-model="form.telefono"
                                                :disabled="!puedeEditar"
                                            />
                                            <InputError :message="form.errors.telefono" />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="telefono_corporativo"
                                                >Teléfono corporativo (opcional)</Label
                                            >
                                            <Input
                                                id="telefono_corporativo"
                                                v-model="form.telefono_corporativo"
                                                :disabled="!puedeEditar"
                                            />
                                            <InputError
                                                :message="form.errors.telefono_corporativo"
                                            />
                                        </div>
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
                                        <div class="grid gap-2">
                                            <Label>Correo corporativo</Label>
                                            <Input
                                                :model-value="colaborador.email ?? 'Sin cuenta de acceso'"
                                                readonly
                                                disabled
                                            />
                                            <p class="text-xs text-muted-foreground">
                                                Correo de la cuenta de acceso — se edita
                                                desde la pestaña «Cuenta».
                                            </p>
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

                <TabsContent value="laborales">
                    <Card class="rounded-2xl border-border/60">
                        <CardHeader class="flex flex-row items-center justify-between gap-2">
                            <CardTitle class="flex items-center gap-2 text-base">
                                <Briefcase class="size-4" />
                                Datos laborales
                            </CardTitle>
                            <Button
                                v-if="puedeEditarLaborales && !editandoLaborales"
                                size="sm"
                                variant="ghost"
                                @click="iniciarEdicionLaborales"
                            >
                                <Pencil class="size-3.5" />
                                Editar
                            </Button>
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
                                        <Select
                                            :model-value="empresaSeleccionada"
                                            @update:model-value="
                                                (v) => alCambiarEmpresa(String(v ?? ''))
                                            "
                                        >
                                            <SelectTrigger class="w-full">
                                                <SelectValue placeholder="Selecciona una empresa" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="empresa in empresasDisponibles"
                                                    :key="empresa.id"
                                                    :value="String(empresa.id)"
                                                >
                                                    {{ empresa.nombre }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div class="grid gap-2">
                                        <Label>Sucursal</Label>
                                        <Select v-model="formLaborales.sucursal_principal_id">
                                            <SelectTrigger class="w-full">
                                                <SelectValue placeholder="Selecciona una sucursal" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="sucursal in sucursalesFiltradas"
                                                    :key="sucursal.id"
                                                    :value="String(sucursal.id)"
                                                >
                                                    {{ sucursal.nombre }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError :message="formLaborales.errors.sucursal_principal_id" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label>Departamento</Label>
                                        <Select v-model="formLaborales.departamento_id">
                                            <SelectTrigger class="w-full">
                                                <SelectValue placeholder="Selecciona un departamento" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="departamento in departamentosDisponibles"
                                                    :key="departamento.id"
                                                    :value="String(departamento.id)"
                                                >
                                                    {{ departamento.nombre }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError :message="formLaborales.errors.departamento_id" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label>Puesto</Label>
                                        <Select v-model="formLaborales.puesto_id">
                                            <SelectTrigger class="w-full">
                                                <SelectValue placeholder="Selecciona un puesto" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem
                                                    v-for="puesto in puestosDisponibles"
                                                    :key="puesto.id"
                                                    :value="String(puesto.id)"
                                                >
                                                    {{ puesto.nombre }}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError :message="formLaborales.errors.puesto_id" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label>Jefe directo</Label>
                                        <Combobox
                                            v-model="formLaborales.jefe_id"
                                            :items="opcionesJefe"
                                            placeholder="Busca por nombre o número de empleado..."
                                            empty-text="Sin resultados."
                                        />
                                        <InputError :message="formLaborales.errors.jefe_id" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="sueldo_mensual">Sueldo mensual</Label>
                                        <Input
                                            id="sueldo_mensual"
                                            v-model="formLaborales.sueldo_mensual"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            placeholder="0.00"
                                        />
                                        <InputError :message="formLaborales.errors.sueldo_mensual" />
                                    </div>
                                    <div class="grid gap-2 sm:col-span-2">
                                        <Label for="motivo_laboral">Motivo del cambio (opcional)</Label>
                                        <Input
                                            id="motivo_laboral"
                                            v-model="formLaborales.motivo"
                                            placeholder="Ej. promoción, reubicación, ajuste de sueldo..."
                                        />
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <Button type="submit" size="sm" :disabled="formLaborales.processing">
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
                                        <CampoInfo :icono="Wallet" etiqueta="Sueldo mensual">
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

                                <div
                                    v-if="colaborador.sueldo_mensual"
                                    class="flex items-center justify-between border-t pt-4"
                                >
                                    <a
                                        :href="reciboNominaUrl"
                                        target="_blank"
                                        class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                                    >
                                        <Receipt class="size-3.5" />
                                        Generar recibo de nómina (PDF)
                                    </a>
                                </div>

                                <p class="text-xs text-muted-foreground">
                                    Los cambios de arriba quedan registrados como
                                    movimientos laborales (ver pestaña «Historial
                                    RH»). El correo, los roles y la contraseña de
                                    acceso están en la pestaña «Cuenta».
                                </p>
                            </template>
                        </CardContent>
                    </Card>
                </TabsContent>

                <TabsContent v-if="!esPropio" value="cuenta">
                    <div v-if="!colaborador.tiene_cuenta" class="grid grid-cols-1 gap-4">
                        <Card v-if="puedeCrearCuenta" class="rounded-2xl border-border/60">
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2 text-base">
                                    <UserPlus class="size-4" />
                                    Crear cuenta de acceso
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form class="flex flex-col gap-4" @submit.prevent="crearCuenta">
                                    <div class="grid gap-2">
                                        <Label for="crear-email">Correo de acceso</Label>
                                        <Input
                                            id="crear-email"
                                            v-model="formCrearCuenta.email"
                                            type="email"
                                        />
                                        <InputError :message="formCrearCuenta.errors.email" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label>Roles</Label>
                                        <div
                                            class="grid max-h-40 grid-cols-2 gap-2 overflow-y-auto rounded-lg border p-2"
                                        >
                                            <label
                                                v-for="rol in rolesDisponibles"
                                                :key="rol"
                                                class="flex items-center gap-2 text-sm capitalize"
                                            >
                                                <Checkbox
                                                    :model-value="formCrearCuenta.roles.includes(rol)"
                                                    @update:model-value="
                                                        (v) =>
                                                            (formCrearCuenta.roles = alternarRol(
                                                                formCrearCuenta.roles,
                                                                rol,
                                                                !!v,
                                                            ))
                                                    "
                                                />
                                                {{ rol.replace(/_/g, ' ') }}
                                            </label>
                                        </div>
                                        <InputError :message="formCrearCuenta.errors.roles" />
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        Se enviará un correo al colaborador para que
                                        establezca su propia contraseña.
                                    </p>
                                    <Button
                                        type="submit"
                                        class="w-fit"
                                        :disabled="formCrearCuenta.processing"
                                    >
                                        <Spinner v-if="formCrearCuenta.processing" />
                                        Crear cuenta
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                        <p v-else class="text-sm text-muted-foreground">
                            Este colaborador todavía no tiene cuenta de acceso y no
                            tienes permiso para crear una.
                        </p>
                    </div>

                    <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <Card class="rounded-2xl border-border/60">
                            <CardHeader class="flex flex-row items-center justify-between gap-2">
                                <CardTitle class="flex items-center gap-2 text-base">
                                    <UserCog class="size-4" />
                                    Cuenta de acceso
                                </CardTitle>
                                <Button
                                    v-if="puedeEditarCuenta && !editandoCuenta"
                                    size="sm"
                                    variant="ghost"
                                    @click="iniciarEdicionCuenta"
                                >
                                    <Pencil class="size-3.5" />
                                    Editar
                                </Button>
                            </CardHeader>
                            <CardContent class="grid gap-4 text-sm">
                                <template v-if="editandoCuenta">
                                    <form class="flex flex-col gap-4" @submit.prevent="guardarCuenta">
                                        <div class="grid gap-2">
                                            <Label for="cuenta-email">Correo de acceso</Label>
                                            <Input id="cuenta-email" v-model="formCuenta.email" type="email" />
                                            <InputError :message="formCuenta.errors.email" />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label>Roles</Label>
                                            <div
                                                class="grid max-h-40 grid-cols-2 gap-2 overflow-y-auto rounded-lg border p-2"
                                            >
                                                <label
                                                    v-for="rol in rolesDisponibles"
                                                    :key="rol"
                                                    class="flex items-center gap-2 text-sm capitalize"
                                                >
                                                    <Checkbox
                                                        :model-value="formCuenta.roles.includes(rol)"
                                                        @update:model-value="
                                                            (v) =>
                                                                (formCuenta.roles = alternarRol(
                                                                    formCuenta.roles,
                                                                    rol,
                                                                    !!v,
                                                                ))
                                                        "
                                                    />
                                                    {{ rol.replace(/_/g, ' ') }}
                                                </label>
                                            </div>
                                            <InputError :message="formCuenta.errors.roles" />
                                        </div>
                                        <div class="flex gap-2">
                                            <Button type="submit" size="sm" :disabled="formCuenta.processing">
                                                <Spinner v-if="formCuenta.processing" />
                                                Guardar
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                @click="editandoCuenta = false"
                                            >
                                                Cancelar
                                            </Button>
                                        </div>
                                    </form>
                                </template>
                                <template v-else>
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
                                </template>
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

                <TabsContent value="documentos">
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

                <TabsContent value="onboarding">
                    <Card class="rounded-2xl border-border/60">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base">
                                <ListChecks class="size-4" />
                                Checklist de incorporación
                            </CardTitle>
                            <div class="flex items-center gap-2 pt-1">
                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-primary transition-all"
                                        :style="{ width: `${onboardingPorcentaje}%` }"
                                    />
                                </div>
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

                <TabsContent value="avisos">
                    <Card v-if="altaDigital" class="rounded-2xl border-border/60">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base">
                                <ScrollText class="size-4" />
                                Avisos y consentimientos
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="grid gap-3 text-sm">
                            <p class="flex flex-wrap items-center gap-1.5">
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
                                <Button
                                    variant="link"
                                    size="sm"
                                    class="h-auto p-0 text-xs"
                                    @click="abrirAviso('privacidad')"
                                >
                                    <Eye class="size-3" />
                                    Leer completo
                                </Button>
                            </p>
                            <p class="flex flex-wrap items-center gap-1.5">
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
                                <Button
                                    variant="link"
                                    size="sm"
                                    class="h-auto p-0 text-xs"
                                    @click="abrirAviso('datos')"
                                >
                                    <Eye class="size-3" />
                                    Leer completo
                                </Button>
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
                                        <Button
                                            variant="link"
                                            size="sm"
                                            type="button"
                                            class="h-auto p-0 text-xs"
                                            @click.stop="abrirAviso('privacidad')"
                                        >
                                            <Eye class="size-3" />
                                            Leer aviso completo
                                        </Button>
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
                                        <Button
                                            variant="link"
                                            size="sm"
                                            type="button"
                                            class="h-auto p-0 text-xs"
                                            @click.stop="abrirAviso('datos')"
                                        >
                                            <Eye class="size-3" />
                                            Leer texto completo
                                        </Button>
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
                <TabsContent value="vacaciones">
                    <Card class="rounded-2xl border-border/60">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base">
                                <Calendar class="size-4" />
                                Vacaciones
                            </CardTitle>
                            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Clock class="size-3.5" />
                                {{ saldoVacaciones.antiguedad_anios }}
                                {{ saldoVacaciones.antiguedad_anios === 1 ? 'año' : 'años' }} de antigüedad
                            </p>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-4">
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div
                                    class="group rounded-xl border p-3 text-center transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                                >
                                    <CalendarCheck
                                        class="mx-auto mb-1 size-4 text-primary transition-transform group-hover:scale-110"
                                    />
                                    <p class="text-lg font-semibold">
                                        {{ saldoVacaciones.dias_generados }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Te corresponden este año
                                    </p>
                                </div>
                                <div
                                    class="group rounded-xl border p-3 text-center transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                                >
                                    <CheckCircle2
                                        class="mx-auto mb-1 size-4 text-[var(--success)] transition-transform group-hover:scale-110"
                                    />
                                    <p class="text-lg font-semibold">
                                        {{ saldoVacaciones.dias_usados }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Usados
                                    </p>
                                </div>
                                <div
                                    class="group rounded-xl border p-3 text-center transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                                >
                                    <Hourglass
                                        class="mx-auto mb-1 size-4 text-warning transition-transform group-hover:scale-110"
                                    />
                                    <p class="text-lg font-semibold">
                                        {{ saldoVacaciones.dias_en_solicitud }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        En solicitud
                                    </p>
                                </div>
                                <div
                                    class="group rounded-xl border p-3 text-center transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                                >
                                    <Sparkles
                                        class="mx-auto mb-1 size-4 text-[var(--success)] transition-transform group-hover:scale-110"
                                    />
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
                <TabsContent value="recibos">
                    <Card class="rounded-2xl border-border/60">
                        <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2">
                            <CardTitle class="flex items-center gap-2 text-base">
                                <Receipt class="size-4" />
                                Recibos de nómina
                            </CardTitle>
                            <Button
                                v-if="puedeEditar && colaborador.sueldo_mensual"
                                size="sm"
                                type="button"
                                @click="abrirDialogoRecibo"
                            >
                                <Plus class="size-4" />
                                Generar recibo
                            </Button>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-2">
                            <p
                                v-if="!colaborador.sueldo_mensual"
                                class="text-sm text-muted-foreground"
                            >
                                Captura el sueldo mensual en «Datos laborales» antes de generar un recibo.
                            </p>
                            <div
                                v-for="recibo in recibosNomina"
                                :key="recibo.id"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border/60 p-3 text-sm"
                            >
                                <div class="min-w-0">
                                    <p class="font-medium">
                                        {{ recibo.periodo_inicio }} — {{ recibo.periodo_fin }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Pago: {{ recibo.fecha_pago }} · Neto: {{ moneda(recibo.neto) }}
                                    </p>
                                </div>
                                <a
                                    v-if="recibo.tiene_pdf"
                                    :href="descargarReciboUrl(recibo.id)"
                                    target="_blank"
                                    class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                                >
                                    <Receipt class="size-3.5" />
                                    Descargar
                                </a>
                                <span v-else class="text-xs text-muted-foreground">
                                    PDF no disponible
                                </span>
                            </div>
                            <p
                                v-if="!recibosNomina.length"
                                class="text-sm text-muted-foreground"
                            >
                                Sin recibos generados.
                            </p>
                        </CardContent>
                    </Card>
                </TabsContent>

                <TabsContent value="prestamos">
                    <Card class="rounded-2xl border-border/60">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base">
                                <Wallet class="size-4" />
                                Préstamos
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-4">
                            <p
                                v-if="!prestamos.length"
                                class="text-sm text-muted-foreground"
                            >
                                Sin préstamos registrados.
                            </p>
                            <div
                                v-for="prestamo in prestamos"
                                :key="prestamo.id"
                                class="rounded-xl border border-border/60 p-4"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="font-medium">
                                            {{ moneda(prestamo.monto_original) }} ·
                                            {{ prestamo.plazo }} pagos ({{ prestamo.periodicidad }})
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            Saldo: {{ moneda(prestamo.saldo) }} ·
                                            Pago programado: {{ moneda(prestamo.pago_programado) }} ·
                                            {{ prestamo.porcentaje_pagado ?? 0 }}% pagado
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            Próximo descuento: {{ prestamo.fecha_primer_descuento ?? '—' }}
                                        </p>
                                    </div>
                                    <EstadoBadge :estado="prestamo.estado" />
                                </div>

                                <Table v-if="prestamo.movimientos.length" class="mt-3">
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Fecha</TableHead>
                                            <TableHead>Tipo</TableHead>
                                            <TableHead class="text-right">Monto</TableHead>
                                            <TableHead class="text-right">Saldo</TableHead>
                                            <TableHead>Registró</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="movimiento in prestamo.movimientos"
                                            :key="movimiento.id"
                                        >
                                            <TableCell>{{ movimiento.fecha }}</TableCell>
                                            <TableCell class="capitalize">{{ movimiento.tipo }}</TableCell>
                                            <TableCell class="text-right">
                                                {{ moneda(movimiento.monto) }}
                                            </TableCell>
                                            <TableCell class="text-right">
                                                {{ moneda(movimiento.saldo_nuevo) }}
                                            </TableCell>
                                            <TableCell>{{ movimiento.registrado_por ?? '—' }}</TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                                <p v-else class="mt-3 text-xs text-muted-foreground">
                                    Sin movimientos registrados todavía.
                                </p>

                                <form
                                    v-if="puedeEditar && prestamo.estado === 'activo'"
                                    class="mt-3 flex flex-wrap items-end gap-2"
                                    @submit.prevent="registrarPago(prestamo.id)"
                                >
                                    <div class="grid gap-1.5">
                                        <Label class="text-xs">Monto del abono</Label>
                                        <Input
                                            v-model.number="formPago.monto"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            class="w-36"
                                        />
                                    </div>
                                    <div class="grid gap-1.5">
                                        <Label class="text-xs">Tipo</Label>
                                        <Select v-model="formPago.tipo">
                                            <SelectTrigger class="w-36">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="manual">Manual</SelectItem>
                                                <SelectItem value="ajuste">Ajuste</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <Button type="submit" size="sm" :disabled="formPago.processing">
                                        Registrar movimiento
                                    </Button>
                                </form>
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>

                <TabsContent value="solicitudes">
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
                <TabsContent value="historial">
                    <MovimientosLaboralesTimeline
                        :movimientos="movimientosLaborales"
                    />
                </TabsContent>
            </div>
        </Tabs>
    </div>

    <EstablecerPasswordDialog
        v-if="dialogoPasswordAbierto && colaborador.usuario_id !== null"
        v-model:open="dialogoPasswordAbierto"
        :colaborador-id="colaborador.usuario_id"
        :colaborador-nombre="`${colaborador.name} ${colaborador.apellidos ?? ''}`"
    />

    <Dialog v-model:open="avisoDialogAbierto">
        <DialogContent class="max-h-[80vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ avisoDialogTitulo }}</DialogTitle>
            </DialogHeader>
            <p class="text-sm whitespace-pre-line text-muted-foreground">
                {{ avisoDialogTexto }}
            </p>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="dialogoReciboAbierto">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Generar recibo de nómina</DialogTitle>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="generarRecibo">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label>Inicio del periodo</Label>
                        <DatePicker v-model="formRecibo.periodo_inicio" />
                        <InputError :message="formRecibo.errors.periodo_inicio" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Fin del periodo</Label>
                        <DatePicker v-model="formRecibo.periodo_fin" />
                        <InputError :message="formRecibo.errors.periodo_fin" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Fecha de pago</Label>
                        <DatePicker v-model="formRecibo.fecha_pago" />
                        <InputError :message="formRecibo.errors.fecha_pago" />
                    </div>
                </div>

                <div>
                    <Label class="mb-2 block">Percepciones</Label>
                    <div class="flex items-center justify-between rounded-lg border border-border/60 bg-muted/40 p-2.5 text-sm">
                        <span class="text-muted-foreground">Sueldo mensual (bloqueado)</span>
                        <span class="font-medium">{{ sueldoFormateado(colaborador.sueldo_mensual) }}</span>
                    </div>
                    <div
                        v-for="(percepcion, indice) in formRecibo.percepciones"
                        :key="indice"
                        class="mt-2 flex items-center gap-2"
                    >
                        <Input
                            v-model="percepcion.concepto"
                            placeholder="Concepto (bono, comisión, otros)"
                            class="flex-1"
                        />
                        <Input
                            v-model.number="percepcion.monto"
                            type="number"
                            step="0.01"
                            min="0"
                            class="w-32"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            @click="formRecibo.percepciones.splice(indice, 1)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-2"
                        @click="agregarPercepcion"
                    >
                        <Plus class="size-4" />
                        Agregar percepción
                    </Button>
                </div>

                <div>
                    <Label class="mb-2 block">Deducciones</Label>
                    <p
                        v-if="!formRecibo.deducciones.length"
                        class="text-xs text-muted-foreground"
                    >
                        Sin deducciones agregadas.
                    </p>
                    <div
                        v-for="(deduccion, indice) in formRecibo.deducciones"
                        :key="indice"
                        class="mt-2 flex items-center gap-2"
                    >
                        <Input
                            v-model="deduccion.concepto"
                            placeholder="Concepto"
                            class="flex-1"
                        />
                        <Input
                            v-model.number="deduccion.monto"
                            type="number"
                            step="0.01"
                            min="0"
                            class="w-32"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            @click="formRecibo.deducciones.splice(indice, 1)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-2"
                        @click="agregarDeduccion"
                    >
                        <Plus class="size-4" />
                        Agregar deducción
                    </Button>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="dialogoReciboAbierto = false"
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="formRecibo.processing">
                        Generar recibo
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
