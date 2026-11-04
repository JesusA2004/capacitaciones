<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\ReglaRecepcionDocumental;

/** Listas de asistencia: registrada y apagada hasta que RH la defina. */
class ReglaListasAsistencia extends ReglaPendienteDeDefinir
{
    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::ListasAsistencia;
    }

    public function pendiente(): string
    {
        return 'Qué lista de asistencia se recibe en original (diaria, semanal o quincenal), si es por sucursal o por persona y desde qué registro del sistema se calcula.';
    }
}
