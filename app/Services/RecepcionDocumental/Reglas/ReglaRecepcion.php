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
 *
 * La regla devuelve TODO lo que sabe producir; el catálogo configurable
 * (CatalogoReglasRecepcion / ReglaConfigurada) filtra después por los
 * documentos, sucursales y empresas elegidos por RH.
 */
interface ReglaRecepcion
{
    public function regla(): ReglaRecepcionDocumental;

    /**
     * Documentos que esta regla puede esperar por evento (clave => nombre
     * visible). Es la lista que ofrece el catálogo al configurar la entrada.
     *
     * @return array<string, string>
     */
    public function documentosPosibles(): array;

    /**
     * @return list<EsperadoRecepcion>
     */
    public function esperados(PeriodoRecepcion $periodo): array;
}
