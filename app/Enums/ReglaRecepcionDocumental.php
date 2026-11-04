<?php

namespace App\Enums;

/**
 * Regla (evento de origen) que genera lo esperado en Recepción documental
 * RH. Cada caso tiene su clase en App\Services\RecepcionDocumental\Reglas y
 * su activación en config/recepcion_documental.php. INFONAVIT, pensión y
 * listas de asistencia están registradas pero APAGADAS: no hay evidencia en
 * el sistema de qué documento, cuándo ni cuántos (RH debe definirlas).
 */
enum ReglaRecepcionDocumental: string
{
    case Altas = 'altas';
    case AltaImss = 'alta_imss';
    case ContratoIndeterminado = 'contrato_indeterminado';
    case Bajas = 'bajas';
    case Vacaciones = 'vacaciones';
    case Prestamos = 'prestamos';
    case RecibosNomina = 'recibos_nomina';
    case Infonavit = 'infonavit';
    case Pension = 'pension';
    case ListasAsistencia = 'listas_asistencia';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Altas => 'Altas',
            self::AltaImss => 'Alta IMSS',
            self::ContratoIndeterminado => 'Contrato indeterminado',
            self::Bajas => 'Bajas',
            self::Vacaciones => 'Vacaciones',
            self::Prestamos => 'Préstamos',
            self::RecibosNomina => 'Nómina',
            self::Infonavit => 'INFONAVIT',
            self::Pension => 'Pensión alimenticia',
            self::ListasAsistencia => 'Listas de asistencia',
        };
    }

    /** Orden de los bloques en pantalla y en el PDF (ALTAS → … → BAJAS). */
    public function orden(): int
    {
        return match ($this) {
            self::Altas => 1,
            self::AltaImss => 2,
            self::ContratoIndeterminado => 3,
            self::RecibosNomina => 4,
            self::Vacaciones => 5,
            self::Prestamos => 6,
            self::Bajas => 7,
            self::Infonavit => 8,
            self::Pension => 9,
            self::ListasAsistencia => 10,
        };
    }

    /** Texto del estado vacío: «No hubo altas durante noviembre 2026.» */
    public function sinEventos(string $periodo): string
    {
        return match ($this) {
            self::Altas => sprintf('No hubo altas durante %s.', $periodo),
            self::AltaImss => sprintf('No hubo altas ante el IMSS durante %s.', $periodo),
            self::ContratoIndeterminado => sprintf('No se generaron contratos indeterminados durante %s.', $periodo),
            self::Bajas => sprintf('No hubo bajas durante %s.', $periodo),
            self::Vacaciones => sprintf('No se aprobaron vacaciones durante %s.', $periodo),
            self::Prestamos => sprintf('No se autorizaron préstamos durante %s.', $periodo),
            self::RecibosNomina => sprintf('No se emitieron recibos de nómina con pago en %s.', $periodo),
            self::Infonavit, self::Pension, self::ListasAsistencia => sprintf('Regla pendiente de definir por RH: no genera esperados en %s.', $periodo),
        };
    }
}
