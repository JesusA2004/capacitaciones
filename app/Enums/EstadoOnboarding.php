<?php

namespace App\Enums;

/**
 * Onboarding (Etapa 3): inducción institucional → inducción al puesto →
 * entrega de activos y responsivas → completado. No es capacitación
 * continua (Etapa 5, fuera de este cierre).
 */
enum EstadoOnboarding: string
{
    case InduccionInstitucional = 'induccion_institucional';
    case InduccionPuesto = 'induccion_puesto';
    case EntregaActivos = 'entrega_activos';
    case Completado = 'completado';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::InduccionInstitucional => 'Inducción institucional',
            self::InduccionPuesto => 'Inducción al puesto',
            self::EntregaActivos => 'Entrega de activos y responsivas',
            self::Completado => 'Onboarding completado',
            self::Cancelado => 'Cancelado',
        };
    }

    public function orden(): int
    {
        return match ($this) {
            self::InduccionInstitucional => 1,
            self::InduccionPuesto => 2,
            self::EntregaActivos => 3,
            self::Completado => 4,
            self::Cancelado => 0,
        };
    }
}
