<?php

namespace App\Enums;

/**
 * Avance del cierre laboral (App\Models\CierreLaboral). El orden de los
 * casos refleja el flujo (CierreLaboralService::avanzar() nunca retrocede):
 *
 *   iniciado [baja APLICADA al iniciarla por el jefe directo o RH;
 *   documentos de la causa pendientes] → aviso_registrado → finiquito_en_proceso
 *   → [registrar entrega del finiquito, desde la fecha efectiva]
 *   → baja_ejecutada → expediente_cerrado
 *
 * LEGACY (solo con CIERRE_FLUJO_LEGACY=true / cierres históricos):
 * finiquito_autorizado → pago_programado → finiquito_firmado → pagado.
 *
 * `iniciado` conserva su valor histórico: hoy significa "baja aplicada".
 * `solicitado` y `pendiente_rh` solo quedan en el historial (bajas
 * rechazadas antes de que la baja fuera inmediata); los cierres abiertos
 * en esos estados se migraron a `iniciado`.
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
            self::Solicitado => 'Solicitado (flujo anterior)',
            self::PendienteRh => 'Pendiente de RH (flujo anterior)',
            self::Iniciado => 'Baja aplicada · documentos pendientes',
            self::AvisoRegistrado => 'Aviso o renuncia registrada',
            self::FiniquitoEnProceso => 'Finiquito calculado · pendiente de entrega',
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
     * true cuando la baja ya está aplicada (a partir de aquí corre el
     * finiquito). Solo los estados del flujo anterior quedan fuera.
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
