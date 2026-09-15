<script setup lang="ts">
import ExpedienteDetalle from '@/components/Rh/ExpedienteDetalle.vue';
import { dashboard } from '@/routes';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import type {
    AltaDigitalResumenExpediente,
    DocumentoExpedienteItem,
    ExpedienteColaborador,
    MovimientoLaboralItem,
    OnboardingItem,
    ResumenExpediente,
    SaldoVacaciones,
    SolicitudExpedienteItem,
    SolicitudVacacionesItem,
} from '@/types';

defineProps<{
    esPropio: boolean;
    puedeEditar: boolean;
    puedeReactivar: boolean;
    puedeRevisarDocumentos: boolean;
    puedeVerExtraccion: boolean;
    puedeAplicarExtraccion: boolean;
    puedeReprocesarExtraccion: boolean;
    puedeIgnorarExtraccion: boolean;
    colaborador: ExpedienteColaborador;
    resumenExpediente: ResumenExpediente;
    documentosRequeridos: DocumentoExpedienteItem[];
    onboarding: OnboardingItem[];
    altaDigital: AltaDigitalResumenExpediente;
    saldoVacaciones: SaldoVacaciones;
    solicitudesVacaciones: SolicitudVacacionesItem[];
    solicitudes: SolicitudExpedienteItem[];
    movimientosLaborales: MovimientoLaboralItem[];
}>();

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
        :puede-revisar-documentos="puedeRevisarDocumentos"
        :puede-ver-extraccion="puedeVerExtraccion"
        :puede-aplicar-extraccion="puedeAplicarExtraccion"
        :puede-reprocesar-extraccion="puedeReprocesarExtraccion"
        :puede-ignorar-extraccion="puedeIgnorarExtraccion"
        :colaborador="colaborador"
        :resumen-expediente="resumenExpediente"
        :documentos-requeridos="documentosRequeridos"
        :onboarding="onboarding"
        :alta-digital="altaDigital"
        :saldo-vacaciones="saldoVacaciones"
        :solicitudes-vacaciones="solicitudesVacaciones"
        :solicitudes="solicitudes"
        :movimientos-laborales="movimientosLaborales"
    />
</template>
