<?php

namespace App\Enums;

/**
 * Clasificación de la incidencia del acta circunstanciada (sección III). Sirve
 * para organizar la información: NO es una calificación jurídica de la
 * conducta ni afirma responsabilidad.
 */
enum TipoIncidenciaActa: string
{
    case Inasistencia = 'inasistencia';
    case Retardo = 'retardo';
    case IncumplimientoInstrucciones = 'incumplimiento_instrucciones';
    case IncidenciaOperativa = 'incidencia_operativa';
    case IrregularidadBienes = 'irregularidad_bienes';
    case Otra = 'otra';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Inasistencia => 'Inasistencia',
            self::Retardo => 'Retardo',
            self::IncumplimientoInstrucciones => 'Incumplimiento de instrucciones',
            self::IncidenciaOperativa => 'Incidencia operativa',
            self::IrregularidadBienes => 'Irregularidad relacionada con bienes de la empresa',
            self::Otra => 'Otra situación laboral',
        };
    }
}
