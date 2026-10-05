<?php

namespace App\Enums;

/**
 * Estado de la cuenta de acceso propuesta en el dry-run de la migración
 * inicial (columna «Estado cuenta», ver PlanificadorMigracion).
 */
enum EstadoCuentaMigracion: string
{
    case Nueva = 'nueva';
    case Existente = 'existente';
    case ColisionResuelta = 'colision_resuelta';
    case Baja = 'baja';
    case NoAplica = 'no_aplica';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Nueva => 'NUEVA',
            self::Existente => 'YA EXISTE',
            self::ColisionResuelta => 'COLISIÓN RESUELTA',
            self::Baja => 'SIN CUENTA (BAJA)',
            self::NoAplica => 'NO APLICA',
        };
    }
}
