<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\ReglaRecepcionDocumental;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;

/**
 * Regla de Recepción documental: dado un mes, ¿qué originales firmados
 * debe recibir RH por los eventos reales de ese mes? Cada implementación
 * hace UNA consulta acotada al rango del periodo (con eager loading), nunca
 * recorre colaboradores × meses. Lo que devuelve es la verdad del momento:
 * un renglón pendiente que la regla ya no devuelve (evento cancelado o
 * movido de mes) pasa a «No aplica» justificado automáticamente.
 */
interface ReglaRecepcion
{
    public function regla(): ReglaRecepcionDocumental;

    /**
     * @return list<EsperadoRecepcion>
     */
    public function esperados(PeriodoRecepcion $periodo): array;
}
