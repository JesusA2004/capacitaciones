<?php

namespace App\Enums;

/**
 * Avance del cierre laboral (App\Models\CierreLaboral). El orden de los
 * casos refleja el flujo (CierreLaboralService::avanzar() nunca retrocede):
 *
 *   solicitado → (preautorización operativa) → pendiente_rh
 *   → (AUTORIZACIÓN RH) iniciado [documentos de la causa pendientes]
 *   → aviso_registrado → finiquito_en_proceso → finiquito_autorizado
 *   → pago_programado (regional de coordinación) → finiquito_firmado → pagado
 *   → baja_ejecutada (solo desde la fecha efectiva) → expediente_cerrado
 *
 * `iniciado` conserva su valor histórico: hoy significa "autorizado por RH".
 */
enum EstadoCierreLaboral: string
{
    case Solicitado = 'solicitado';
    case PendienteRh = 'pendiente_rh';
    case Iniciado = 'iniciado';
    case AvisoRegistrado = 'aviso_registrado';
    case FiniquitoEnProceso = 'finiquito_en_proceso';
    case FiniquitoAutorizado = 'finiquito_autorizado';
    case PagoProgramado = 'pago_programado';
    case FiniquitoFirmado = 'finiquito_firmado';
    case Pagado = 'pagado';
    case BajaEjecutada = 'baja_ejecutada';
    case ExpedienteCerrado = 'expediente_cerrado';
    case Rechazado = 'rechazado';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Solicitado => 'Solicitado · preautorización pendiente',
            self::PendienteRh => 'Preautorizado · pendiente de RH',
            self::Iniciado => 'Autorizado por RH · documentos pendientes',
            self::AvisoRegistrado => 'Aviso o renuncia registrada',
            self::FiniquitoEnProceso => 'Finiquito en revisión',
            self::FiniquitoAutorizado => 'Finiquito autorizado',
            self::PagoProgramado => 'Pago programado',
            self::FiniquitoFirmado => 'Finiquito firmado',
            self::Pagado => 'Pago confirmado',
            self::BajaEjecutada => 'Baja ejecutada',
            self::ExpedienteCerrado => 'Cierre laboral completo',
            self::Rechazado => 'Rechazado',
            self::Cancelado => 'Cancelado',
        };
    }

    public function esFinal(): bool
    {
        return in_array($this, [self::ExpedienteCerrado, self::Rechazado, self::Cancelado], true);
    }

    /**
     * true cuando RH ya autorizó la baja (a partir de aquí corre el finiquito).
     */
    public function autorizadoPorRh(): bool
    {
        return ! in_array($this, [self::Solicitado, self::PendienteRh, self::Rechazado, self::Cancelado], true);
    }

    /**
     * @return list<self>
     */
    public static function abiertos(): array
    {
        return array_values(array_filter(self::cases(), fn (self $e) => ! $e->esFinal()));
    }
}
