<?php

namespace App\Enums;

/**
 * Tipos de pendiente de la bandeja de trabajo (App\Models\TareaRh). Cada
 * tarea apunta al objeto relacionado (relacionado_type/relacionado_id) y a
 * la acción concreta que se espera — la bandeja ("¿qué me toca hacer?") no
 * obliga al usuario a entender módulos.
 */
enum TipoTarea: string
{
    // Etapa 1 — reclutamiento
    case CandidatoRevisionPerfil = 'candidato_revision_perfil';
    case CandidatoEntrevista = 'candidato_entrevista';
    case CandidatoPsicometricas = 'candidato_psicometricas';
    case CandidatoRevisionPsicometricas = 'candidato_revision_psicometricas';
    case CandidatoSocioeconomico = 'candidato_socioeconomico';
    case CandidatoReferencias = 'candidato_referencias';
    case CandidatoPreautorizacion = 'candidato_preautorizacion';
    case CandidatoAutorizacionRh = 'candidato_autorizacion_rh';
    case CandidatoInvitacion = 'candidato_invitacion';
    // Solo se abre cuando el gerente solicita explícitamente una
    // intervención sobre un rechazo de RH (CLAUDE.md §10-12) — nunca por un
    // rechazo normal.
    case CandidatoIntervencionPendiente = 'candidato_intervencion_pendiente';

    // Etapa 2 — contratación y expediente
    case ExpedienteIncompleto = 'expediente_incompleto';
    case DocumentoPorRevisar = 'documento_por_revisar';
    case DocumentoRechazado = 'documento_rechazado';
    case ContratoPendiente = 'contrato_pendiente';
    case FirmaPendiente = 'firma_pendiente';
    case ImpresionPendiente = 'impresion_pendiente';
    case FirmaFisicaPendiente = 'firma_fisica_pendiente';
    case EnvioOriginalPendiente = 'envio_original_pendiente';
    case RecepcionOriginalPendiente = 'recepcion_original_pendiente';
    case EscaneoPendiente = 'escaneo_pendiente';
    case ActivacionPendiente = 'activacion_pendiente';

    // Etapa 3 — onboarding
    case OnboardingModuloPendiente = 'onboarding_modulo_pendiente';
    case OnboardingRefuerzo = 'onboarding_refuerzo';
    case OnboardingEntregaActivos = 'onboarding_entrega_activos';
    case PlantillaFaltante = 'plantilla_faltante';

    // Etapa 4 — periodo de prueba
    case ContratoPorVencer = 'contrato_por_vencer';
    case EvaluacionPendiente = 'evaluacion_pendiente';
    case EvaluacionPorAutorizar = 'evaluacion_por_autorizar';

    // Etapa 6 — cierre laboral y reingreso
    case CierrePreautorizacion = 'cierre_preautorizacion';
    case CierreAutorizacionRh = 'cierre_autorizacion_rh';
    case FiniquitoPendiente = 'finiquito_pendiente';
    case FiniquitoPorAutorizar = 'finiquito_por_autorizar';
    case PagoPorProgramar = 'pago_por_programar';
    case CitaFiniquito = 'cita_finiquito';
    case CierrePorConcluir = 'cierre_por_concluir';
    case ReingresoPreautorizacion = 'reingreso_preautorizacion';
    case ReingresoRevision = 'reingreso_revision';

    // Etapa 5 (fuera del ciclo de este cierre; se conservan por compatibilidad)
    case VacacionesPendiente = 'vacaciones_pendiente';
    case PermisoPendiente = 'permiso_pendiente';
    case PrestamoPendiente = 'prestamo_pendiente';

    public function etiqueta(): string
    {
        return match ($this) {
            self::CandidatoRevisionPerfil => 'Revisar perfil del candidato',
            self::CandidatoEntrevista => 'Candidato por entrevistar',
            self::CandidatoPsicometricas => 'Psicométricas por registrar',
            self::CandidatoRevisionPsicometricas => 'Psicométricas por revisar',
            self::CandidatoSocioeconomico => 'Estudio socioeconómico pendiente',
            self::CandidatoReferencias => 'Referencias por validar',
            self::CandidatoPreautorizacion => 'Candidato por preautorizar',
            self::CandidatoAutorizacionRh => 'Candidato pendiente de autorización final',
            self::CandidatoInvitacion => 'Generar QR de contratación',
            self::CandidatoIntervencionPendiente => 'Intervención de candidato pendiente',
            self::ExpedienteIncompleto => 'Expediente incompleto',
            self::DocumentoPorRevisar => 'Documento por revisar',
            self::DocumentoRechazado => 'Documento rechazado',
            self::ContratoPendiente => 'Contrato pendiente',
            self::FirmaPendiente => 'Firma pendiente',
            self::ImpresionPendiente => 'Contrato por imprimir',
            self::FirmaFisicaPendiente => 'Firma física pendiente',
            self::EnvioOriginalPendiente => 'Original pendiente de envío',
            self::RecepcionOriginalPendiente => 'Original pendiente de recepción',
            self::EscaneoPendiente => 'Original pendiente de escaneo',
            self::ActivacionPendiente => 'Alta pendiente de activación',
            self::OnboardingModuloPendiente => 'Inducción pendiente',
            self::OnboardingRefuerzo => 'Onboarding con resultado menor al mínimo',
            self::OnboardingEntregaActivos => 'Activos y responsivas por entregar',
            self::PlantillaFaltante => 'Plantilla documental faltante',
            self::ContratoPorVencer => 'Contrato próximo a vencer',
            self::EvaluacionPendiente => 'Evaluación de periodo de prueba pendiente',
            self::EvaluacionPorAutorizar => 'Periodo de prueba pendiente de autorización',
            self::CierrePreautorizacion => 'Baja por preautorizar',
            self::CierreAutorizacionRh => 'Baja pendiente de autorización final',
            self::FiniquitoPendiente => 'Finiquito pendiente',
            self::FiniquitoPorAutorizar => 'Finiquito por autorizar',
            self::PagoPorProgramar => 'Pago de finiquito por programar',
            self::CitaFiniquito => 'Excolaborador por citar (firma y pago)',
            self::CierrePorConcluir => 'Cierre laboral por concluir',
            self::ReingresoPreautorizacion => 'Reingreso por preautorizar',
            self::ReingresoRevision => 'Reingreso por revisar',
            self::VacacionesPendiente => 'Vacaciones pendientes',
            self::PermisoPendiente => 'Permiso pendiente',
            self::PrestamoPendiente => 'Préstamo pendiente',
        };
    }

    /**
     * Etapa del ciclo a la que pertenece el pendiente (null = Etapa 5, fuera
     * del recorrido de este cierre).
     */
    public function etapa(): ?EtapaCicloLaboral
    {
        return match ($this) {
            self::CandidatoRevisionPerfil, self::CandidatoEntrevista, self::CandidatoPsicometricas,
            self::CandidatoRevisionPsicometricas, self::CandidatoSocioeconomico, self::CandidatoReferencias,
            self::CandidatoPreautorizacion, self::CandidatoAutorizacionRh,
            self::CandidatoIntervencionPendiente => EtapaCicloLaboral::Reclutamiento,
            self::CandidatoInvitacion, self::ExpedienteIncompleto, self::DocumentoPorRevisar, self::DocumentoRechazado,
            self::ContratoPendiente, self::FirmaPendiente, self::ImpresionPendiente, self::FirmaFisicaPendiente,
            self::EnvioOriginalPendiente, self::RecepcionOriginalPendiente, self::EscaneoPendiente,
            self::ActivacionPendiente => EtapaCicloLaboral::Contratacion,
            self::OnboardingModuloPendiente, self::OnboardingRefuerzo, self::OnboardingEntregaActivos,
            self::PlantillaFaltante => EtapaCicloLaboral::Onboarding,
            self::ContratoPorVencer, self::EvaluacionPendiente, self::EvaluacionPorAutorizar => EtapaCicloLaboral::PeriodoPrueba,
            self::CierrePreautorizacion, self::CierreAutorizacionRh, self::FiniquitoPendiente, self::FiniquitoPorAutorizar,
            self::PagoPorProgramar, self::CitaFiniquito, self::CierrePorConcluir => EtapaCicloLaboral::Cierre,
            self::ReingresoPreautorizacion, self::ReingresoRevision => EtapaCicloLaboral::Reingreso,
            self::VacacionesPendiente, self::PermisoPendiente, self::PrestamoPendiente => null,
        };
    }

    /**
     * Tipos que pertenecen al ciclo laboral de este cierre (excluye Etapa 5).
     *
     * @return list<self>
     */
    public static function delCiclo(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->etapa() !== null));
    }

    /**
     * Pendientes del pipeline de un candidato (se resuelven en bloque al
     * avanzar de estado).
     *
     * @return list<self>
     */
    public static function deReclutamiento(): array
    {
        return [
            self::CandidatoRevisionPerfil, self::CandidatoEntrevista, self::CandidatoPsicometricas,
            self::CandidatoRevisionPsicometricas, self::CandidatoSocioeconomico, self::CandidatoReferencias,
            self::CandidatoPreautorizacion, self::CandidatoAutorizacionRh, self::CandidatoInvitacion,
        ];
    }
}
