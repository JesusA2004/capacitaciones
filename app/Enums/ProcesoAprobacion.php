<?php

namespace App\Enums;

/**
 * Qué se está aprobando. La infraestructura es genérica: agregar un proceso
 * (p. ej. vacaciones cuando se abra la Etapa 5) es agregar un caso aquí,
 * sin tabla nueva.
 */
enum ProcesoAprobacion: string
{
    case SeleccionCandidato = 'seleccion_candidato';
    case PeriodoPrueba = 'periodo_prueba';
    case CierreLaboral = 'cierre_laboral';
    case Reingreso = 'reingreso';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SeleccionCandidato => 'Selección y contratación',
            self::PeriodoPrueba => 'Renovación / no renovación del periodo de prueba',
            self::CierreLaboral => 'Cierre laboral',
            self::Reingreso => 'Reingreso',
        };
    }
}
