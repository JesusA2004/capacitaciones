<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Briefcase,
    Building2,
    Calendar,
    ChevronDown,
    ClipboardList,
    Clock,
    FileSignature,
    FolderOpen,
    History,
    IdCard,
    LayoutDashboard,
    ListChecks,
    Lock,
    Mail,
    MapPinned,
    PencilLine,
    Phone,
    Receipt,
    Route,
    ScrollText,
    Unlock,
    User,
    UserCog,
    UserRound,
    Wallet,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, nextTick, ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import HistorialCorrecciones from '@/components/correcciones/HistorialCorrecciones.vue';
import SiguientePasoPanel from '@/components/flujos/SiguientePaso.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import AvisosComunicaciones from '@/components/Rh/AvisosComunicaciones.vue';
import type { AvisoComunicacion } from '@/components/Rh/AvisosComunicaciones.vue';
import CampoInfo from '@/components/Rh/CampoInfo.vue';
import DatosFaltantesBanner from '@/components/Rh/DatosFaltantesBanner.vue';
import type { DatosFaltantesResumen } from '@/components/Rh/DatosFaltantesBanner.vue';
import ExpedienteCentro from '@/components/Rh/Expediente/ExpedienteCentro.vue';
import ExpedienteContratacion from '@/components/Rh/Expediente/ExpedienteContratacion.vue';
import ExpedienteDatosPersonales from '@/components/Rh/Expediente/ExpedienteDatosPersonales.vue';
import HistorialCambiosDatos from '@/components/Rh/Expediente/HistorialCambiosDatos.vue';
import PestanaChecklist from '@/components/Rh/Expediente/PestanaChecklist.vue';
import PestanaCuenta from '@/components/Rh/Expediente/PestanaCuenta.vue';
import PestanaDocumentos from '@/components/Rh/Expediente/PestanaDocumentos.vue';
import PestanaLaborales from '@/components/Rh/Expediente/PestanaLaborales.vue';
import PestanaPrestamos from '@/components/Rh/Expediente/PestanaPrestamos.vue';
import PestanaRecibos from '@/components/Rh/Expediente/PestanaRecibos.vue';
import PestanaSolicitudes from '@/components/Rh/Expediente/PestanaSolicitudes.vue';
import PestanaVacaciones from '@/components/Rh/Expediente/PestanaVacaciones.vue';
import MovimientosLaboralesTimeline from '@/components/Rh/MovimientosLaboralesTimeline.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useAccionEnCurso } from '@/composables/useAccionEnCurso';
import { useAlertas } from '@/composables/useAlertas';
import {
    restablecerAcceso,
    revocarAcceso,
} from '@/routes/administracion/usuarios';
import { darDeBaja, reactivar } from '@/routes/rh/expedientes';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import type {
    SiguientePaso,
    AltaDigitalResumenExpediente,
    CompletitudDatos,
    DocumentoExpedienteItem,
    AvisosManualExpediente,
    CentroExpediente,
    ExpedienteColaborador,
    HistorialCambioExpediente,
    MovimientoLaboralItem,
    OnboardingItem,
    PrestamoItem,
    ReciboNominaItem,
    ResumenExpediente,
    SaldoVacaciones,
    SolicitudExpedienteItem,
    SolicitudVacacionesItem,
} from '@/types';

const accion = useAccionEnCurso();

const props = defineProps<{
    esPropio: boolean;
    puedeEditar: boolean;
    /** Solo RH (nomina.recibos.crear) genera recibos, nunca el propio. */
    puedeGenerarRecibos?: boolean;
    puedeReactivar: boolean;
    /** Ficha del Ciclo laboral (contratos, onboarding, evaluación, baja). */
    cicloUrl?: string | null;
    siguientePaso?: SiguientePaso | null;
    siguientePasoSueldo?: SiguientePaso | null;
    /** Jefe directo (cadena de mando) o RH: ColaboradorPolicy::iniciarCierre. */
    puedeIniciarBaja?: boolean;
    puedeGestionarAcceso: boolean;
    /** Baja administrativa inmediata (cierres.ejecutar_baja + alcance), separada del acceso. */
    puedeDarDeBaja?: boolean;
    puedeGestionarPassword: boolean;
    puedeEditarCuenta: boolean;
    puedeCrearCuenta: boolean;
    puedeEditarLaborales: boolean;
    puedeVerSueldo: boolean;
    rolesDisponibles: string[];
    empresasDisponibles: { id: number; nombre: string }[];
    sucursalesDisponibles: {
        id: number;
        nombre: string;
        empresa_id: number | null;
    }[];
    departamentosDisponibles: { id: number; nombre: string }[];
    puestosDisponibles: {
        id: number;
        nombre: string;
        puesto_superior_id: number | null;
    }[];
    esCuentaPropia: boolean;
    puedeRevisarDocumentos: boolean;
    puedeVerExtraccion: boolean;
    puedeAplicarExtraccion: boolean;
    puedeReprocesarExtraccion: boolean;
    puedeIgnorarExtraccion: boolean;
    puedeGestionarAvisos: boolean;
    colaborador: ExpedienteColaborador;
    resumenExpediente: ResumenExpediente;
    completitudDatos: CompletitudDatos;
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
    /** null = quien mira no puede ver recibos (el colaborador nunca ve los suyos: se entregan impresos). */
    recibosNomina: ReciboNominaItem[] | null;
    prestamos: PrestamoItem[];
    /** PDF(s) históricos únicos (migración inicial): fuera del checklist. */
    expedienteHistorico?: {
        id: number;
        nombre: string;
        original: string;
        size: number | null;
        migrado_en: string | null;
        url: string;
        descargar_url: string;
    }[];
    /** Solo llega con permiso expedientes.datos_medicos.ver. */
    datosMedicos?: {
        condicion_medica: string | null;
        alergias: string | null;
    } | null;
    contactoEmergenciaExtra?: {
        parentesco: string | null;
        direccion: string | null;
    };
    /** ⚠ Datos contractuales faltantes (DatosFaltantesService). */
    datosFaltantes?: DatosFaltantesResumen;
    puedeAvisarDatosFaltantes?: boolean;
    /** Avisos generales + personales con su estado de lectura. */
    avisosComunicaciones?: AvisoComunicacion[];
    /** Datos críticos: cambiarlos exige motivo (DatosExpedienteService::CAMPOS_CRITICOS). */
    camposCriticos?: string[];
    puedeEditarDatosMedicos?: boolean;
    opcionesDatos?: {
        genero: { value: string; label: string }[];
        estado_civil: { value: string; label: string }[];
    };
    /** Cambios administrativos de datos (antes/después); null en «Mi expediente». */
    historialCambios?: HistorialCambioExpediente[] | null;
    /** Expediente como centro (prop diferida); null en «Mi expediente». */
    centro?: CentroExpediente | null;
}>();

// Acuse de alta IMSS: lo sube RH al concluir el alta. Verde = registrado,
// naranja = falta. Es un indicador, no un pendiente de la bandeja.
const { mostrarExito, mostrarError, confirmarDelicada } = useAlertas();

function reactivarColaborador() {
    accion.ejecutar('reactivarColaborador', (estado) => {
        router.post(
            reactivar.url(props.colaborador.id),
            {},
            {
                ...estado,
                preserveScroll: true,
                onSuccess: () =>
                    mostrarExito('Colaborador reactivado correctamente.'),
                onError: () =>
                    mostrarError('No fue posible reactivar al colaborador.'),
            },
        );
    });
}

async function darDeBajaColaborador() {
    const confirmado = await confirmarDelicada(
        `¿Dar de baja a ${props.colaborador.name} ${props.colaborador.apellidos ?? ''}?`,
        'Termina su relación laboral, no solo su acceso. Su expediente y su historial se conservan.',
        'Dar de baja',
    );

    if (!confirmado) {
        return;
    }

    accion.ejecutar('darDeBajaColaborador', (estado) => {
        router.delete(darDeBaja.url(props.colaborador.id), {
            ...estado,
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito('El colaborador se dio de baja correctamente.'),
            onError: () =>
                mostrarError('No fue posible dar de baja al colaborador.'),
        });
    });
}

function revocarAccesoColaborador() {
    const usuarioId = props.colaborador.usuario_id;

    if (usuarioId === null) {
        return;
    }

    accion.ejecutar('revocarAccesoColaborador', (estado) => {
        router.post(
            revocarAcceso.url(usuarioId),
            {},
            {
                ...estado,
                preserveScroll: true,
                onSuccess: () =>
                    mostrarExito(
                        'Acceso revocado. El colaborador sigue activo en la plantilla.',
                    ),
                onError: () =>
                    mostrarError('No fue posible revocar el acceso.'),
            },
        );
    });
}

function restablecerAccesoColaborador() {
    const usuarioId = props.colaborador.usuario_id;

    if (usuarioId === null) {
        return;
    }

    accion.ejecutar('restablecerAccesoColaborador', (estado) => {
        router.post(
            restablecerAcceso.url(usuarioId),
            {},
            {
                ...estado,
                preserveScroll: true,
                onSuccess: () => mostrarExito('Acceso restablecido.'),
                onError: () =>
                    mostrarError('No fue posible restablecer el acceso.'),
            },
        );
    });
}

const anilloExpediente = computed(
    () =>
        `conic-gradient(var(--primary) ${props.resumenExpediente.porcentaje * 3.6}deg, var(--primary-foreground, var(--muted)) 0deg)`,
);

// --- Cuenta de acceso: la crea «Generar credenciales» (sin correo) ---
// --- Cuenta de acceso: editar correo/roles (colaborador con cuenta) ---
// --- Datos laborales: empresa/sucursal/departamento/puesto/sueldo ---
// (el jefe directo no se captura: sale del organigrama al guardar)
// --- Recibos de nómina (ver App\Services\Nomina\ReciboNominaService) ---

// Sugerencia simple (nunca un cálculo fiscal: ISR/IMSS quedan fuera) a
// partir del sueldo mensual registrado y el número de días del periodo —
// RH siempre puede editar el resultado antes de generar el recibo, ver
// App\Services\Nomina\ReciboNominaService::generar().

// Vista previa del recibo con lo capturado, ANTES de «Generar» (el backend
// no crea el recibo ni archiva nada). Blob en memoria: los montos no viajan
// en la URL.

// --- Préstamos (ver App\Services\Nomina\PrestamoService) ---

/**
 * Pestaña con la que abre el expediente: `?tab=documentos` (u otra) permite
 * que una notificación lleve directo a la sección que avisa — ver
 * App\Services\Notificaciones\DestinoNotificacionService.
 */
// En "Mi expediente" nadie edita sus propios datos (ni RH): pide una
// corrección con la solicitud "Actualización de datos".
const urlSolicitarCorreccion = indexSolicitudes.url({
    query: { nueva: 'actualizacion_datos' },
});

// «Corregir sueldo» desde el panel: abre su diálogo, con la misma validación.
type PestanaExpediente = {
    valor: string;
    etiqueta: string;
    icono: Component;
    contador?: number;
};

const PESTANAS = [
    'resumen',
    'personales',
    'contratacion',
    'cambios',
    'laborales',
    'cuenta',
    'documentos',
    'onboarding',
    'avisos',
    'vacaciones',
    'recibos',
    'prestamos',
    'solicitudes',
    'historial',
];
const pestanaInicial = (() => {
    if (typeof window === 'undefined') {
        return 'resumen';
    }

    const solicitada = new URLSearchParams(window.location.search).get('tab');

    return solicitada &&
        PESTANAS.includes(solicitada) &&
        !(
            (solicitada === 'cuenta' ||
                solicitada === 'onboarding' ||
                solicitada === 'contratacion' ||
                solicitada === 'cambios') &&
            props.esPropio
        ) &&
        !(solicitada === 'recibos' && props.recibosNomina === null)
        ? solicitada
        : 'resumen';
})();

/** Datos personales + laborales que faltan (mismo cálculo del aviso de arriba). */
const datosFaltantesTotal = computed(() =>
    props.datosFaltantes
        ? props.datosFaltantes.personales.length +
          props.datosFaltantes.laborales.length
        : props.completitudDatos.faltantes.length,
);

/** Pestaña visible (controlada: las tarjetas del centro cambian de pestaña). */
const pestana = ref<string>(pestanaInicial);

/**
 * Cinco secciones en vez de 14 pestañas al mismo nivel. Los valores no cambian:
 * `?tab=` y las tarjetas del centro siguen llevando a cada pestaña.
 */
const gruposPestanas = computed(() => {
    const ajenas = !props.esPropio;
    const grupos = [
        {
            clave: 'resumen',
            etiqueta: 'Resumen',
            icono: LayoutDashboard,
            pestanas: [
                {
                    valor: 'resumen',
                    etiqueta: 'Resumen',
                    icono: LayoutDashboard,
                },
            ],
        },
        {
            clave: 'datos',
            etiqueta: 'Datos',
            icono: IdCard,
            pestanas: [
                { valor: 'personales', etiqueta: 'Personales', icono: IdCard },
                { valor: 'laborales', etiqueta: 'Laborales', icono: Briefcase },
                ajenas && {
                    valor: 'cuenta',
                    etiqueta: 'Cuenta',
                    icono: UserCog,
                },
                props.historialCambios && {
                    valor: 'cambios',
                    etiqueta: 'Cambios de datos',
                    icono: PencilLine,
                    contador: props.historialCambios.length,
                },
            ],
        },
        {
            clave: 'documentos',
            etiqueta: 'Documentos',
            icono: FolderOpen,
            pestanas: [
                {
                    valor: 'documentos',
                    etiqueta: 'Documentos',
                    icono: FolderOpen,
                },
                ajenas && {
                    valor: 'contratacion',
                    etiqueta: 'Contratación',
                    icono: FileSignature,
                },
                ajenas && {
                    valor: 'onboarding',
                    etiqueta: 'Checklist',
                    icono: ListChecks,
                },
            ],
        },
        {
            clave: 'nomina',
            etiqueta: 'Nómina y solicitudes',
            icono: Receipt,
            pestanas: [
                {
                    valor: 'vacaciones',
                    etiqueta: 'Vacaciones',
                    icono: Calendar,
                },
                props.recibosNomina !== null && {
                    valor: 'recibos',
                    etiqueta: 'Recibos de nómina',
                    icono: Receipt,
                },
                { valor: 'prestamos', etiqueta: 'Préstamos', icono: Wallet },
                {
                    valor: 'solicitudes',
                    etiqueta: 'Solicitudes',
                    icono: ClipboardList,
                },
            ],
        },
        {
            clave: 'historial',
            etiqueta: 'Historial y avisos',
            icono: History,
            pestanas: [
                {
                    valor: 'historial',
                    etiqueta: 'Historial RH',
                    icono: History,
                },
                { valor: 'avisos', etiqueta: 'Avisos', icono: ScrollText },
            ],
        },
    ];

    return grupos.map((grupo) => ({
        ...grupo,
        pestanas: grupo.pestanas.filter((p) => !!p) as PestanaExpediente[],
    }));
});

const grupoActivo = computed(() =>
    gruposPestanas.value.find((g) =>
        g.pestanas.some((p) => p.valor === pestana.value),
    ),
);

const datosPersonalesRef = ref<InstanceType<
    typeof ExpedienteDatosPersonales
> | null>(null);

/** «Completar datos»: abre Datos personales ya en edición (si puede editar). */
function irACompletarExpediente() {
    pestana.value = 'personales';

    if (props.puedeEditar) {
        void nextTick(() => datosPersonalesRef.value?.iniciarEdicion());
    }
}
</script>

<template>
    <Head :title="`Expediente de ${colaborador.name}`" />

    <div class="pagina-ancha flex flex-col gap-6">
        <Card
            data-tour="expediente-cabecera"
            class="overflow-hidden rounded-3xl border-border/60 bg-gradient-to-br from-primary/10 via-card to-card shadow-sm transition-shadow hover:shadow-md"
        >
            <CardContent
                class="flex flex-col gap-4 2xl:flex-row 2xl:items-center"
            >
                <!-- Identidad + avance: siempre en una fila; las acciones van
                     debajo (y a la derecha solo en pantallas muy anchas) para
                     que el nombre nunca quede aplastado en una columna. -->
                <div
                    class="flex min-w-0 flex-1 items-start gap-3 sm:items-center sm:gap-4"
                >
                    <Avatar
                        class="size-14 shrink-0 rounded-2xl ring-2 ring-primary/30 sm:size-20"
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
                            <User class="size-7 sm:size-9" />
                        </AvatarFallback>
                    </Avatar>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-lg font-semibold">
                                {{ colaborador.name }}
                                {{ colaborador.apellidos }}
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
                                            : 'font-medium text-success'
                                    "
                                >
                                    {{
                                        colaborador.acceso_bloqueado_en
                                            ? 'No (bloqueado)'
                                            : 'Sí'
                                    }}
                                </span>
                            </span>
                            <span
                                class="flex items-center gap-1 font-medium text-foreground sm:hidden"
                            >
                                Expediente
                                {{ resumenExpediente.porcentaje }}%
                            </span>
                        </div>
                    </div>

                    <div
                        class="hidden shrink-0 flex-col items-end gap-1 sm:flex"
                    >
                        <span class="text-xs text-muted-foreground"
                            >Expediente</span
                        >
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
                </div>

                <div
                    class="flex flex-wrap items-center gap-2 border-t border-border/50 pt-3 empty:hidden 2xl:max-w-[60%] 2xl:justify-end 2xl:border-t-0 2xl:pt-0"
                >
                    <Button
                        v-if="cicloUrl"
                        size="sm"
                        variant="secondary"
                        data-tour="expediente-ciclo"
                        as-child
                    >
                        <Link :href="cicloUrl">
                            <Route class="size-3.5" />
                            Ciclo laboral
                        </Link>
                    </Button>
                    <!-- Acciones de la página (p. ej. «Generar responsiva»). -->
                    <slot name="acciones-encabezado" />
                    <Button
                        :disabled="accion.ocupado.value"
                        v-if="
                            colaborador.estatus === 'inactivo' && puedeReactivar
                        "
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
                    <DropdownMenu
                        v-else-if="
                            puedeGestionarAcceso ||
                            puedeDarDeBaja ||
                            $slots['acciones-menu'] ||
                            (cicloUrl && puedeIniciarBaja)
                        "
                    >
                        <DropdownMenuTrigger as-child>
                            <Button size="sm" variant="outline">
                                Más acciones
                                <ChevronDown class="size-3.5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-80">
                            <slot name="acciones-menu" />
                            <DropdownMenuItem
                                v-if="cicloUrl && puedeIniciarBaja"
                                as-child
                                class="font-medium text-destructive"
                            >
                                <Link
                                    :href="`${cicloUrl}?accion=solicitar_baja`"
                                >
                                    Iniciar baja
                                </Link>
                            </DropdownMenuItem>
                            <template
                                v-if="
                                    puedeGestionarAcceso &&
                                    colaborador.tiene_cuenta
                                "
                            >
                                <DropdownMenuItem
                                    v-if="colaborador.acceso_bloqueado_en"
                                    class="flex-col items-start gap-0.5 py-2"
                                    @select="restablecerAccesoColaborador"
                                >
                                    <span
                                        class="flex items-center gap-1.5 font-medium"
                                    >
                                        <Unlock class="size-3.5" />
                                        Restablecer acceso
                                    </span>
                                    <span
                                        class="text-xs whitespace-normal text-muted-foreground"
                                    >
                                        Vuelve a permitir el inicio de sesión.
                                        El colaborador nunca dejó de estar
                                        activo en la plantilla.
                                    </span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-else
                                    class="flex-col items-start gap-0.5 py-2"
                                    @select="revocarAccesoColaborador"
                                >
                                    <span
                                        class="flex items-center gap-1.5 font-medium"
                                    >
                                        <Lock class="size-3.5" />
                                        Revocar acceso
                                    </span>
                                    <span
                                        class="text-xs whitespace-normal text-muted-foreground"
                                    >
                                        Solo bloquea el inicio de sesión (ej.
                                        incapacidad, suspensión temporal). Sigue
                                        activo en la plantilla — su empleo no
                                        cambia.
                                    </span>
                                </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem
                                v-if="puedeDarDeBaja"
                                variant="destructive"
                                class="flex-col items-start gap-0.5 py-2"
                                @select="darDeBajaColaborador"
                            >
                                <span class="font-medium">Dar de baja</span>
                                <span
                                    class="text-xs whitespace-normal opacity-80"
                                >
                                    Baja administrativa inmediata: termina la
                                    relación laboral y libera la plaza, sin
                                    aprobaciones ni finiquito. Para el proceso
                                    normal usa «Iniciar baja» (Ciclo laboral).
                                    Nunca se borra nada (baja lógica).
                                </span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </CardContent>
        </Card>

        <div
            v-if="siguientePaso || siguientePasoSueldo"
            class="grid gap-4 lg:grid-cols-2"
        >
            <SiguientePasoPanel
                v-if="siguientePaso"
                compacto
                :paso="siguientePaso"
            />
            <SiguientePasoPanel
                v-if="siguientePasoSueldo"
                compacto
                titulo="Sueldo de esta persona"
                :paso="siguientePasoSueldo"
            />
        </div>

        <Card
            v-if="completitudDatos.aplica && completitudDatos.porcentaje < 100"
            class="border-warning/30 bg-warning-soft/50 py-0"
        >
            <CardContent class="flex flex-col gap-2 px-4 py-4 sm:px-6">
                <p
                    class="flex items-center gap-2 text-sm font-medium text-warning"
                >
                    <AlertTriangle class="size-4" />
                    Expediente incompleto
                </p>
                <p class="text-sm text-warning">
                    Faltan
                    {{ completitudDatos.faltantes.length }}
                    dato{{ completitudDatos.faltantes.length === 1 ? '' : 's' }}
                    que impiden generar contrato:
                </p>
                <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-warning">
                    <li
                        v-for="f in completitudDatos.faltantes"
                        :key="f.columna"
                        class="flex items-center gap-1"
                    >
                        • {{ f.etiqueta }}
                    </li>
                </ul>
                <div class="mt-1">
                    <Button
                        size="sm"
                        variant="outline"
                        class="border-warning/50 text-warning hover:bg-warning-soft"
                        @click="irACompletarExpediente"
                    >
                        Completar expediente
                    </Button>
                </div>
            </CardContent>
        </Card>

        <DatosFaltantesBanner
            v-if="datosFaltantes"
            data-tour="expediente-faltantes"
            class="mb-4"
            :datos="datosFaltantes"
            :colaborador-id="colaborador.id"
            :es-propio="esPropio"
            :puede-avisar="puedeAvisarDatosFaltantes ?? false"
            :documentos="{
                aprobados: resumenExpediente.requeridos_aprobados,
                total: resumenExpediente.requeridos_total,
            }"
        />
        <Tabs v-model="pestana" class="gap-4">
            <div data-tour="expediente-pestanas" class="flex flex-col gap-2">
                <nav
                    aria-label="Secciones del expediente"
                    class="scroll-x-limpio flex gap-1 rounded-xl bg-muted/60 p-1"
                >
                    <button
                        v-for="grupo in gruposPestanas"
                        :key="grupo.clave"
                        type="button"
                        class="inline-flex min-h-9 shrink-0 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm font-medium transicion-ui"
                        :class="
                            grupo.clave === grupoActivo?.clave
                                ? 'bg-card text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :aria-current="
                            grupo.clave === grupoActivo?.clave
                                ? 'true'
                                : undefined
                        "
                        @click="pestana = grupo.pestanas[0].valor"
                    >
                        <component :is="grupo.icono" class="size-4" />
                        {{ grupo.etiqueta }}
                    </button>
                </nav>
                <TabsList
                    class="scroll-x-limpio h-auto w-full justify-start gap-1 bg-transparent p-0"
                    :class="{
                        'sr-only': (grupoActivo?.pestanas.length ?? 0) < 2,
                    }"
                >
                    <TabsTrigger
                        v-for="p in grupoActivo?.pestanas ?? []"
                        :key="p.valor"
                        :value="p.valor"
                        class="flex-none gap-2 border border-transparent data-[state=active]:border-border data-[state=active]:bg-card"
                    >
                        <component :is="p.icono" class="size-4" />
                        {{ p.etiqueta }}
                        <span
                            v-if="p.contador"
                            class="rounded-full bg-muted px-1.5 text-[10px] tabular-nums"
                            >{{ p.contador }}</span
                        >
                    </TabsTrigger>
                </TabsList>
            </div>

            <div class="min-w-0 flex-1">
                <TabsContent value="resumen" class="flex flex-col gap-4">
                    <div
                        class="grid grid-cols-2 gap-3 md:grid-cols-4"
                        data-resumen-contadores
                    >
                        <TarjetaMetrica
                            etiqueta="Documentos aprobados"
                            :valor="`${resumenExpediente.requeridos_aprobados}/${resumenExpediente.requeridos_total}`"
                            :icono="FolderOpen"
                            :tono="
                                resumenExpediente.requeridos_aprobados ===
                                resumenExpediente.requeridos_total
                                    ? 'exito'
                                    : 'primario'
                            "
                            clicable
                            @click="pestana = 'documentos'"
                        />
                        <TarjetaMetrica
                            etiqueta="Por cargar o revisar"
                            :valor="resumenExpediente.pendientes"
                            :icono="Clock"
                            tono="alerta"
                            clicable
                            @click="pestana = 'documentos'"
                        />
                        <TarjetaMetrica
                            etiqueta="Rechazados"
                            :valor="resumenExpediente.rechazados"
                            :icono="AlertTriangle"
                            tono="peligro"
                            clicable
                            @click="pestana = 'documentos'"
                        />
                        <TarjetaMetrica
                            etiqueta="Datos faltantes"
                            :valor="datosFaltantesTotal"
                            :icono="IdCard"
                            :tono="
                                datosFaltantesTotal === 0 ? 'exito' : 'alerta'
                            "
                            :detalle="
                                datosFaltantesTotal === 0
                                    ? 'Completo'
                                    : 'Para contrato'
                            "
                            clicable
                            @click="pestana = 'personales'"
                        />
                    </div>

                    <Deferred v-if="!esPropio" data="centro">
                        <template #fallback>
                            <div class="grid gap-3" aria-busy="true">
                                <Skeleton class="h-28 rounded-2xl" />
                                <div class="grid gap-3 lg:grid-cols-2">
                                    <Skeleton class="h-32 rounded-2xl" />
                                    <Skeleton class="h-32 rounded-2xl" />
                                </div>
                                <div
                                    class="grid grid-cols-2 gap-3 xl:grid-cols-4"
                                >
                                    <Skeleton
                                        v-for="i in 4"
                                        :key="i"
                                        class="h-24 rounded-2xl"
                                    />
                                </div>
                            </div>
                        </template>
                        <ExpedienteCentro
                            v-if="centro"
                            :centro="centro"
                            @pestana="(p: string) => (pestana = p)"
                        />
                    </Deferred>

                    <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2">
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
                    <ExpedienteDatosPersonales
                        ref="datosPersonalesRef"
                        :colaborador="colaborador"
                        :puede-editar="puedeEditar"
                        :es-propio="esPropio"
                        :puede-editar-datos-medicos="
                            puedeEditarDatosMedicos ?? false
                        "
                        :datos-medicos="datosMedicos"
                        :campos-criticos="camposCriticos ?? []"
                        :opciones-datos="
                            opcionesDatos ?? { genero: [], estado_civil: [] }
                        "
                        :url-solicitar-correccion="urlSolicitarCorreccion"
                    />
                </TabsContent>

                <TabsContent v-if="!esPropio" value="contratacion">
                    <Deferred data="centro">
                        <template #fallback>
                            <div class="grid gap-3">
                                <Skeleton class="h-20 rounded-2xl" />
                                <Skeleton class="h-40 rounded-2xl" />
                            </div>
                        </template>
                        <ExpedienteContratacion
                            v-if="centro?.contratacion"
                            :plan="centro.contratacion"
                            @completar-datos="irACompletarExpediente"
                        />
                        <p
                            v-else
                            class="rounded-2xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground"
                        >
                            No tienes acceso al módulo de contratos para esta
                            persona.
                        </p>
                    </Deferred>
                </TabsContent>

                <TabsContent v-if="historialCambios" value="cambios">
                    <HistorialCambiosDatos :historial="historialCambios" />
                    <h3 class="mt-6 mb-3 text-sm font-semibold">
                        Correcciones auditadas
                    </h3>
                    <HistorialCorrecciones :colaborador-id="colaborador.id" />
                </TabsContent>

                <TabsContent
                    value="laborales"
                    force-mount
                    class="data-[state=inactive]:hidden"
                >
                    <PestanaLaborales
                        :colaborador="colaborador"
                        :departamentos-disponibles="departamentosDisponibles"
                        :empresas-disponibles="empresasDisponibles"
                        :es-propio="esPropio"
                        :puede-editar-laborales="puedeEditarLaborales"
                        :puede-ver-sueldo="puedeVerSueldo"
                        :puestos-disponibles="puestosDisponibles"
                        :sucursales-disponibles="sucursalesDisponibles"
                    />
                </TabsContent>

                <TabsContent
                    v-if="!esPropio"
                    value="cuenta"
                    force-mount
                    class="data-[state=inactive]:hidden"
                >
                    <PestanaCuenta
                        :colaborador="colaborador"
                        :es-cuenta-propia="esCuentaPropia"
                        :es-propio="esPropio"
                        :puede-crear-cuenta="puedeCrearCuenta"
                        :puede-editar-cuenta="puedeEditarCuenta"
                        :puede-gestionar-acceso="puedeGestionarAcceso"
                        :puede-gestionar-password="puedeGestionarPassword"
                        :roles-disponibles="rolesDisponibles"
                        :ocupado="accion.ocupado.value"
                        @restablecer-acceso="restablecerAccesoColaborador"
                        @revocar-acceso="revocarAccesoColaborador"
                    />
                </TabsContent>

                <TabsContent value="documentos" class="flex flex-col gap-4">
                    <PestanaDocumentos
                        :colaborador="colaborador"
                        :documentos-requeridos="documentosRequeridos"
                        :es-propio="esPropio"
                        :expediente-historico="expedienteHistorico"
                        :puede-aplicar-extraccion="puedeAplicarExtraccion"
                        :puede-editar="puedeEditar"
                        :puede-ignorar-extraccion="puedeIgnorarExtraccion"
                        :puede-reprocesar-extraccion="puedeReprocesarExtraccion"
                        :puede-revisar-documentos="puedeRevisarDocumentos"
                        :puede-ver-extraccion="puedeVerExtraccion"
                    >
                        <template #sin-expediente-historico
                            ><slot name="sin-expediente-historico"
                        /></template>
                        <template #documentos-oficiales
                            ><slot name="documentos-oficiales"
                        /></template>
                    </PestanaDocumentos>
                </TabsContent>

                <TabsContent value="onboarding">
                    <PestanaChecklist :onboarding="onboarding" />
                </TabsContent>

                <TabsContent value="avisos">
                    <AvisosComunicaciones
                        :avisos="avisosComunicaciones ?? []"
                    />
                </TabsContent>
                <TabsContent value="vacaciones">
                    <PestanaVacaciones
                        :saldo-vacaciones="saldoVacaciones"
                        :solicitudes="solicitudes"
                        :solicitudes-vacaciones="solicitudesVacaciones"
                    />
                </TabsContent>
                <TabsContent v-if="recibosNomina !== null" value="recibos">
                    <PestanaRecibos
                        :colaborador="colaborador"
                        :puede-generar-recibos="puedeGenerarRecibos"
                        :puede-ver-sueldo="puedeVerSueldo"
                        :recibos-nomina="recibosNomina"
                        :prestamos="prestamos"
                    />
                </TabsContent>

                <TabsContent value="prestamos">
                    <PestanaPrestamos
                        :prestamos="prestamos"
                        :puede-editar="puedeEditar"
                    />
                </TabsContent>

                <TabsContent value="solicitudes">
                    <PestanaSolicitudes :solicitudes="solicitudes" />
                </TabsContent>
                <TabsContent value="historial">
                    <MovimientosLaboralesTimeline
                        :movimientos="movimientosLaborales"
                    />
                </TabsContent>
            </div>
        </Tabs>
    </div>
</template>
