<?php

namespace App\Services\Evaluacion;

use App\Models\Actividad;
use App\Models\Leccion;

class ActividadService
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(Leccion $leccion, array $datos): Actividad
    {
        return Actividad::create([...$datos, 'leccion_id' => $leccion->id]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Actividad $actividad, array $datos): Actividad
    {
        $actividad->update($datos);

        return $actividad->fresh();
    }

    public function eliminar(Actividad $actividad): void
    {
        $actividad->delete();
    }
}
