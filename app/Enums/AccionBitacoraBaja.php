<?php

namespace App\Enums;

/**
 * Acciones que deja cada cambio en la bitácora de una baja (RN-13): quién,
 * cuándo, valor anterior y valor nuevo.
 */
enum AccionBitacoraBaja: string
{
    case Creo = 'creo';
    case Registro = 'registro';
    case Actualizo = 'actualizo';
    case Envio = 'envio';
    case Valido = 'valido';
    case EnvioJuridico = 'envio_juridico';
    case AproboJuridico = 'aprobo_juridico';
    case Rechazo = 'rechazo';
    case DevolvioJuridico = 'devolvio_juridico';
    case ConversionFlujo = 'conversion_flujo';
    case CambioCausal = 'cambio_causal';
    case CambioRecontratable = 'cambio_recontratable';
    case CambioRotacion = 'cambio_rotacion';
    case AdjuntoDocumento = 'adjunto_documento';
    case QuitoDocumento = 'quito_documento';
    case Cerro = 'cerro';
    case Reabrio = 'reabrio';
    case Cancelo = 'cancelo';
    case Migrada = 'migrada';
    case Alerta = 'alerta';
    case DocumentoSalida = 'documento_salida';
    case AvisoRescision = 'aviso_rescision';
    case FirmadoSalida = 'firmado_salida';
    case ValidoPlazo = 'valido_plazo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Creo => 'Creó la baja',
            self::Registro => 'Registró la baja (aplicada de inmediato)',
            self::Actualizo => 'Actualizó datos',
            self::Envio => 'Envió a RH (flujo anterior)',
            self::Valido => 'RH validó (flujo anterior)',
            self::EnvioJuridico => 'Envió a Jurídico',
            self::AproboJuridico => 'Jurídico aprobó',
            self::Rechazo => 'Rechazó (flujo anterior)',
            self::DevolvioJuridico => 'Jurídico la devolvió a RH',
            self::ConversionFlujo => 'Convertida al flujo de baja directa',
            self::CambioCausal => 'Cambió la causal',
            self::CambioRecontratable => 'Cambió «¿Recontratable?»',
            self::CambioRotacion => 'Cambió «cuenta en rotación»',
            self::AdjuntoDocumento => 'Adjuntó documento',
            self::QuitoDocumento => 'Quitó documento',
            self::Cerro => 'Cerró la baja',
            self::Reabrio => 'Reabrió la baja',
            self::Cancelo => 'Marcó que la baja no procede',
            self::Migrada => 'Registro creado desde un cierre anterior',
            self::Alerta => 'Alerta enviada',
            self::DocumentoSalida => 'Generó el documento de salida',
            self::AvisoRescision => 'Capturó el aviso de rescisión',
            self::FirmadoSalida => 'Cargó el documento de salida firmado',
            self::ValidoPlazo => 'Validó el plazo del aviso de rescisión',
        };
    }
}
