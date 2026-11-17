<?php

namespace App\Enums;

/**
 * Regla (evento de origen) que genera lo esperado en Recepción documental
 * RH. Cada caso tiene su clase en App\Services\RecepcionDocumental\Reglas y
 * su entrada en el catálogo configurable (recepcion_documental_catalogo,
 * sembrado con los valores por defecto de config/recepcion_documental.php).
 * Solo existen reglas COMPLETAMENTE definidas: nada «pendiente de definir».
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
            self::ListasAsistencia => 'Listas de asistencia',
        };
    }

    /** Orden por defecto de los bloques (el catálogo puede reordenarlos). */
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
            self::ListasAsistencia => 8,
        };
    }

    /** Qué evento real del sistema dispara lo esperado (texto para la pantalla). */
    public function evento(): string
    {
        return match ($this) {
            self::Altas => 'Cada colaborador cuya fecha de ingreso cae en el mes: los contratos del paquete de alta de su puesto.',
            self::AltaImss => 'Cada colaborador cuya fecha de alta ante el IMSS cae en el mes.',
            self::ContratoIndeterminado => 'Cada renovación cuyo contrato indeterminado se generó por primera vez en el mes.',
            self::Bajas => 'Cada baja (en trámite o cerrada) cuya fecha de baja cae en el mes.',
            self::Vacaciones => 'Cada solicitud de vacaciones aprobada en el mes (aprobación final).',
            self::Prestamos => 'Cada préstamo autorizado en el mes que no se canceló.',
            self::RecibosNomina => 'Cada recibo de nómina emitido con fecha de pago en el mes.',
            self::ListasAsistencia => 'Un lote por sucursal y semana (lunes a domingo) con sus colaboradores activos.',
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
            self::ListasAsistencia => sprintf('No hubo colaboradores activos en sucursales durante las semanas de %s.', $periodo),
        };
    }
}
