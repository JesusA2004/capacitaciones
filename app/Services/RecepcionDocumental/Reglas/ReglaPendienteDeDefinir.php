<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Services\RecepcionDocumental\PeriodoRecepcion;

/**
 * Regla REGISTRADA pero sin definición del negocio: no hay en el sistema
 * evidencia de qué documento se firma, en qué evento ni con qué
 * granularidad. Nunca genera esperados (aunque se encienda por error en la
 * configuración): RH debe definir la regla/plantilla antes de implementarla
 * (docs/RECEPCION_DOCUMENTAL_RH.md → «Reglas pendientes de definir»).
 */
abstract class ReglaPendienteDeDefinir implements ReglaRecepcion
{
    /** Qué falta que RH defina (se muestra junto a la regla). */
    abstract public function pendiente(): string;

    public function esperados(PeriodoRecepcion $periodo): array
    {
        return [];
    }
}
