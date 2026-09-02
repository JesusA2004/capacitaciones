<script setup lang="ts">
import ExpedienteDetalle from '@/components/Rh/ExpedienteDetalle.vue';
import { dashboard } from '@/routes';
import type {
    AltaDigitalResumenExpediente,
    DocumentoExpedienteItem,
    ExpedienteColaborador,
    OnboardingItem,
    ResumenExpediente,
    SaldoVacaciones,
    SolicitudVacacionesItem,
} from '@/types';

defineProps<{
    esPropio: boolean;
    puedeEditar: boolean;
    puedeRevisarDocumentos: boolean;
    colaborador: ExpedienteColaborador;
    resumenExpediente: ResumenExpediente;
    documentosRequeridos: DocumentoExpedienteItem[];
    onboarding: OnboardingItem[];
    altaDigital: AltaDigitalResumenExpediente;
    saldoVacaciones: SaldoVacaciones;
    solicitudesVacaciones: SolicitudVacacionesItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi expediente', href: '#' },
        ],
    },
});
</script>

<template>
    <ExpedienteDetalle
        :es-propio="esPropio"
        :puede-editar="puedeEditar"
        :puede-revisar-documentos="puedeRevisarDocumentos"
        :colaborador="colaborador"
        :resumen-expediente="resumenExpediente"
        :documentos-requeridos="documentosRequeridos"
        :onboarding="onboarding"
        :alta-digital="altaDigital"
        :saldo-vacaciones="saldoVacaciones"
        :solicitudes-vacaciones="solicitudesVacaciones"
    />
</template>
