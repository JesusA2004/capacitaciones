<script setup lang="ts">
import ExpedienteDetalle from '@/components/Rh/ExpedienteDetalle.vue';
import ExpedienteDocumentosOficiales from '@/components/Rh/ExpedienteDocumentosOficiales.vue';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard } from '@/routes';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import type {
    AltaDigitalResumenExpediente,
    AvisosManualExpediente,
    DocumentoExpedienteItem,
    ExpedienteColaborador,
    FormatoOficialItem,
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
    documentosOficiales: InstanceType<typeof ExpedienteDocumentosOficiales>['$props']['documentos'] | null;
    formatosOficialesDisponibles: FormatoOficialItem[];
}>();

const { tienePermiso } = usePermisos();

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
        :puede-reactivar="puedeReactivar"
        :puede-gestionar-acceso="puedeGestionarAcceso"
        :puede-gestionar-password="puedeGestionarPassword"
        :puede-editar-cuenta="puedeEditarCuenta"
        :puede-crear-cuenta="puedeCrearCuenta"
        :puede-editar-laborales="puedeEditarLaborales"
        :roles-disponibles="rolesDisponibles"
        :empresas-disponibles="empresasDisponibles"
        :sucursales-disponibles="sucursalesDisponibles"
        :departamentos-disponibles="departamentosDisponibles"
        :puestos-disponibles="puestosDisponibles"
        :jefes-disponibles="jefesDisponibles"
        :es-cuenta-propia="esCuentaPropia"
        :puede-revisar-documentos="puedeRevisarDocumentos"
        :puede-ver-extraccion="puedeVerExtraccion"
        :puede-aplicar-extraccion="puedeAplicarExtraccion"
        :puede-reprocesar-extraccion="puedeReprocesarExtraccion"
        :puede-ignorar-extraccion="puedeIgnorarExtraccion"
        :puede-gestionar-avisos="puedeGestionarAvisos"
        :colaborador="colaborador"
        :resumen-expediente="resumenExpediente"
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
        :prestamos="prestamos"
    >
        <template #documentos-oficiales>
            <ExpedienteDocumentosOficiales
                v-if="props.documentosOficiales !== null"
                :colaborador="{ id: colaborador.id, nombre: `${colaborador.name} ${colaborador.apellidos ?? ''}`.trim() }"
                :documentos="props.documentosOficiales"
                :formatos="formatosOficialesDisponibles"
                :puede-descargar="tienePermiso('formatos_oficiales.descargar')"
            />
        </template>
    </ExpedienteDetalle>
</template>
