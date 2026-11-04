<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\ReglaRecepcionDocumental;

/** INFONAVIT: registrada y apagada hasta que RH la defina. */
class ReglaInfonavit extends ReglaPendienteDeDefinir
{
    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::Infonavit;
    }

    public function pendiente(): string
    {
        return 'Qué documento se firma por crédito INFONAVIT (aviso de retención, alta o modificación del crédito), con qué evento del sistema se detecta y si se espera uno por crédito o por movimiento.';
    }
}
