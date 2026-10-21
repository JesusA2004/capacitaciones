<script setup lang="ts">
import { FileArchive, FileSignature } from '@lucide/vue';
import { computed, ref } from 'vue';
import DescargaEnProgreso from '@/components/DescargaEnProgreso.vue';
import DocumentosProceso from '@/components/documentos/DocumentosProceso.vue';
import ExpedienteDetalle from '@/components/Rh/ExpedienteDetalle.vue';
import ExpedienteDocumentosOficiales from '@/components/Rh/ExpedienteDocumentosOficiales.vue';
import ResponsivaFormDialog from '@/components/Rh/Responsivas/ResponsivaFormDialog.vue';
import ResponsivasColaborador from '@/components/Rh/Responsivas/ResponsivasColaborador.vue';
import { Button } from '@/components/ui/button';
import { useDescargaArchivo } from '@/composables/useDescargaArchivo';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard } from '@/routes';
import {
    descargarZip,
    index as indexExpedientes,
} from '@/routes/rh/expedientes';
import type {
    AltaDigitalResumenExpediente,
    AvisosManualExpediente,
    CentroExpediente,
    CompletitudDatos,
    DocumentoExpedienteItem,
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

const props = defineProps<{
    esPropio: boolean;
    puedeEditar: boolean;
    puedeGenerarRecibos?: boolean;
    puedeReactivar: boolean;
    cicloUrl: string | null;
    puedeIniciarBaja?: boolean;
    puedeGestionarAcceso: boolean;
    puedeDarDeBaja: boolean;
    puedeGestionarPassword: boolean;
    puedeEditarCuenta: boolean;
    puedeCrearCuenta: boolean;
    puedeEditarLaborales: boolean;
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
    puedeDescargarExpediente?: boolean;
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
    recibosNomina: ReciboNominaItem[] | null;
    expedienteHistorico?: InstanceType<
        typeof ExpedienteDetalle
    >['$props']['expedienteHistorico'];
    datosMedicos?: InstanceType<
        typeof ExpedienteDetalle
    >['$props']['datosMedicos'];
    contactoEmergenciaExtra?: InstanceType<
        typeof ExpedienteDetalle
    >['$props']['contactoEmergenciaExtra'];
    datosFaltantes?: InstanceType<
        typeof ExpedienteDetalle
    >['$props']['datosFaltantes'];
    puedeAvisarDatosFaltantes?: boolean;
    avisosComunicaciones?: InstanceType<
        typeof ExpedienteDetalle
    >['$props']['avisosComunicaciones'];
    prestamos: PrestamoItem[];
    camposCriticos?: string[];
    puedeEditarDatosMedicos?: boolean;
    opcionesDatos?: InstanceType<
        typeof ExpedienteDetalle
    >['$props']['opcionesDatos'];
    historialCambios?: HistorialCambioExpediente[] | null;
    centro?: CentroExpediente | null;
    documentosOficiales:
        | InstanceType<
              typeof ExpedienteDocumentosOficiales
          >['$props']['documentos']
        | null;
}>();

const { tienePermiso } = usePermisos();

// Responsivas de equipo: el gerente (con alcance) y RH. La regla real la
// aplica ResponsivaPolicy; aquí solo se oculta el botón.
const puedeGenerarResponsiva = computed(
    () =>
        !props.esPropio &&
        !props.esCuentaPropia &&
        props.colaborador.estatus !== 'inactivo' &&
        tienePermiso('responsivas.generar'),
);
const responsivaAbierta = ref(false);

// «Descargar expediente completo»: armar el ZIP lee el NAS y tarda; se pide
// con fetch para mostrar el progreso, errores (403/429/500) y poder cancelar.
const {
    estado: estadoDescarga,
    error: errorDescarga,
    nombreArchivo: archivoDescargado,
    enProgreso: descargandoExpediente,
    descargar,
    cancelar: cancelarDescarga,
    reintentar: reintentarDescarga,
    cerrar: cerrarDescarga,
} = useDescargaArchivo();

const nombreColaborador = computed(() =>
    [props.colaborador.name, props.colaborador.apellidos]
        .filter(Boolean)
        .join(' '),
);

function descargarExpedienteCompleto(): void {
    void descargar(descargarZip.url(props.colaborador.id), {
        nombreRespaldo: `Expediente ${nombreColaborador.value}.zip`,
    });
}
const versionResponsivas = ref(0);

// Huella del estado de los documentos del expediente: al aprobar/rechazar
// uno (p. ej. el último obligatorio), «Documentos de contratación» se
// recarga en el acto en vez de seguir «En espera» hasta refrescar la página.
const huellaDocumentos = computed(() =>
    props.documentosRequeridos
        .map((d) => `${d.documento?.id ?? 0}:${d.documento?.status ?? ''}`)
        .join('|'),
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Expedientes', href: indexExpedientes.url() },
            { title: 'Colaborador', href: '#' },
        ],
    },
});
</script>

<template>
    <ExpedienteDetalle
        :es-propio="esPropio"
        :puede-editar="puedeEditar"
        :puede-generar-recibos="puedeGenerarRecibos"
        :puede-reactivar="puedeReactivar"
        :ciclo-url="cicloUrl"
        :puede-iniciar-baja="puedeIniciarBaja"
        :puede-gestionar-acceso="puedeGestionarAcceso"
        :puede-dar-de-baja="puedeDarDeBaja"
        :puede-gestionar-password="puedeGestionarPassword"
        :puede-editar-cuenta="puedeEditarCuenta"
        :puede-crear-cuenta="puedeCrearCuenta"
        :puede-editar-laborales="puedeEditarLaborales"
        :roles-disponibles="rolesDisponibles"
        :empresas-disponibles="empresasDisponibles"
        :sucursales-disponibles="sucursalesDisponibles"
        :departamentos-disponibles="departamentosDisponibles"
        :puestos-disponibles="puestosDisponibles"
        :es-cuenta-propia="esCuentaPropia"
        :puede-revisar-documentos="puedeRevisarDocumentos"
        :puede-ver-extraccion="puedeVerExtraccion"
        :puede-aplicar-extraccion="puedeAplicarExtraccion"
        :puede-reprocesar-extraccion="puedeReprocesarExtraccion"
        :puede-ignorar-extraccion="puedeIgnorarExtraccion"
        :puede-gestionar-avisos="puedeGestionarAvisos"
        :colaborador="colaborador"
        :resumen-expediente="resumenExpediente"
        :completitud-datos="completitudDatos"
        :documentos-requeridos="documentosRequeridos"
        :onboarding="onboarding"
        :alta-digital="altaDigital"
        :avisos-manual="avisosManual"
        :aviso-privacidad-texto="avisoPrivacidadTexto"
        :consentimiento-datos-texto="consentimientoDatosTexto"
        :saldo-vacaciones="saldoVacaciones"
        :solicitudes-vacaciones="solicitudesVacaciones"
        :solicitudes="solicitudes"
        :movimientos-laborales="movimientosLaborales"
        :recibos-nomina="recibosNomina"
        :expediente-historico="expedienteHistorico"
        :datos-medicos="datosMedicos"
        :contacto-emergencia-extra="contactoEmergenciaExtra"
        :datos-faltantes="datosFaltantes"
        :puede-avisar-datos-faltantes="puedeAvisarDatosFaltantes"
        :avisos-comunicaciones="avisosComunicaciones"
        :prestamos="prestamos"
        :campos-criticos="camposCriticos"
        :puede-editar-datos-medicos="puedeEditarDatosMedicos"
        :opciones-datos="opcionesDatos"
        :historial-cambios="historialCambios"
        :centro="centro"
    >
        <template #acciones-encabezado>
            <!-- ZIP con todo el expediente + INDICE.csv: descarga directa,
                 no visita Inertia. La regla real: EmployeeDocumentPolicy. -->
            <Button
                v-if="puedeDescargarExpediente"
                size="sm"
                :disabled="descargandoExpediente"
                :aria-busy="descargandoExpediente"
                @click="descargarExpedienteCompleto"
            >
                <FileArchive class="size-3.5" />
                {{
                    descargandoExpediente
                        ? 'Preparando expediente…'
                        : 'Descargar expediente completo'
                }}
            </Button>
            <DescargaEnProgreso
                v-if="puedeDescargarExpediente"
                :estado="estadoDescarga"
                :error="errorDescarga"
                :nombre-archivo="archivoDescargado"
                titulo="Preparando el expediente completo"
                :sujeto="nombreColaborador"
                descripcion="Reuniendo documentos, versiones y el índice… esto puede tardar unos segundos."
                @cancelar="cancelarDescarga"
                @reintentar="reintentarDescarga"
                @cerrar="cerrarDescarga"
            />
            <template v-if="puedeGenerarResponsiva">
                <Button
                    size="sm"
                    variant="secondary"
                    @click="responsivaAbierta = true"
                >
                    <FileSignature class="size-3.5" />
                    Generar responsiva
                </Button>
                <ResponsivaFormDialog
                    v-model:open="responsivaAbierta"
                    :colaborador-id="colaborador.id"
                    @generada="versionResponsivas++"
                />
            </template>
        </template>
        <template #documentos-oficiales>
            <ResponsivasColaborador
                v-if="!esPropio && !esCuentaPropia"
                :colaborador-id="colaborador.id"
                :version="versionResponsivas"
                @generar="responsivaAbierta = true"
            />
            <DocumentosProceso
                tipo="colaborador"
                :id="colaborador.id"
                :version="huellaDocumentos"
            />
            <ExpedienteDocumentosOficiales
                v-if="props.documentosOficiales !== null"
                :documentos="props.documentosOficiales"
                :puede-descargar="tienePermiso('formatos_oficiales.descargar')"
            />
        </template>
    </ExpedienteDetalle>
</template>
