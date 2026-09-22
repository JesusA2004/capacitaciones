<?php

namespace App\Enums;

/**
 * Tipos de pendiente de la bandeja de trabajo (App\Models\TareaRh). Cada
 * tarea apunta al objeto relacionado (relacionado_type/relacionado_id).
 */
enum TipoTarea: string
{
    case ExpedienteIncompleto = 'expediente_incompleto';
    case DocumentoRechazado = 'documento_rechazado';
    case ContratoPendiente = 'contrato_pendiente';
    case FirmaPendiente = 'firma_pendiente';
    case ImpresionPendiente = 'impresion_pendiente';
    case FirmaFisicaPendiente = 'firma_fisica_pendiente';
    case EnvioOriginalPendiente = 'envio_original_pendiente';
    case RecepcionOriginalPendiente = 'recepcion_original_pendiente';
    case EscaneoPendiente = 'escaneo_pendiente';
    case ContratoPorVencer = 'contrato_por_vencer';
    case EvaluacionPendiente = 'evaluacion_pendiente';
    case EvaluacionPorAutorizar = 'evaluacion_por_autorizar';
    case VacacionesPendiente = 'vacaciones_pendiente';
    case PermisoPendiente = 'permiso_pendiente';
    case PrestamoPendiente = 'prestamo_pendiente';
    case FiniquitoPendiente = 'finiquito_pendiente';
    case ActivacionPendiente = 'activacion_pendiente';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ExpedienteIncompleto => 'Expediente incompleto',
            self::DocumentoRechazado => 'Documento rechazado',
            self::ContratoPendiente => 'Contrato pendiente',
            self::FirmaPendiente => 'Firma pendiente',
            self::ImpresionPendiente => 'Impresión pendiente',
            self::FirmaFisicaPendiente => 'Firma física pendiente',
            self::EnvioOriginalPendiente => 'Original pendiente de envío',
            self::RecepcionOriginalPendiente => 'Original pendiente de recepción',
            self::EscaneoPendiente => 'Original pendiente de escaneo',
            self::ContratoPorVencer => 'Contrato próximo a vencer',
            self::EvaluacionPendiente => 'Evaluación de periodo de prueba pendiente',
            self::EvaluacionPorAutorizar => 'Evaluación por autorizar',
            self::VacacionesPendiente => 'Vacaciones pendientes',
            self::PermisoPendiente => 'Permiso pendiente',
            self::PrestamoPendiente => 'Préstamo pendiente',
            self::FiniquitoPendiente => 'Finiquito pendiente',
            self::ActivacionPendiente => 'Alta pendiente de activación',
        };
    }
}
