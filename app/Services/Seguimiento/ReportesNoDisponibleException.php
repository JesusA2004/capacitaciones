<?php

namespace App\Services\Seguimiento;

use RuntimeException;

/**
 * Reportes no contestó lo esperado (sin configurar, caído, token inválido,
 * sin permiso, parámetros rechazados…). `getMessage()` es un texto en
 * español listo para mostrarse en pantalla; `$estado` es el código HTTP de
 * Reportes (0 = sin conexión / sin configurar).
 */
class ReportesNoDisponibleException extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $estado = 0)
    {
        parent::__construct($mensaje);
    }
}
