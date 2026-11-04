<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\ReglaRecepcionDocumental;

/** Pensión alimenticia: registrada y apagada hasta que RH la defina. */
class ReglaPension extends ReglaPendienteDeDefinir
{
    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::Pension;
    }

    public function pendiente(): string
    {
        return 'Qué documento firma el colaborador por pensión alimenticia (oficio, constancia de descuento), en qué evento se genera y si se espera por resolución o por cada recibo.';
    }
}
