<?php

namespace App\Services\Evaluacion;

use App\Models\Cuestionario;
use App\Models\Leccion;

/**
 * Configuracion de un cuestionario (1:1 con una Leccion de tipo
 * "cuestionario") y de las preguntas del banco que lo componen.
 */
class CuestionarioService
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(Leccion $leccion, array $datos): Cuestionario
    {
        return Cuestionario::create([...$datos, 'leccion_id' => $leccion->id]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Cuestionario $cuestionario, array $datos): Cuestionario
    {
        $cuestionario->update($datos);

        return $cuestionario->fresh();
    }

    /**
     * Reemplaza por completo el conjunto de preguntas del cuestionario y su
     * orden, a partir de la lista ordenada recibida del constructor.
     *
     * @param  array<int, array{pregunta_id: int, puntos?: int|null}>  $preguntas
     */
    public function actualizarPreguntas(Cuestionario $cuestionario, array $preguntas): void
    {
        $sincronizacion = [];

        foreach ($preguntas as $orden => $pregunta) {
            $sincronizacion[$pregunta['pregunta_id']] = [
                'orden' => $orden,
                'puntos' => $pregunta['puntos'] ?? null,
            ];
        }

        $cuestionario->preguntas()->sync($sincronizacion);
    }

    public function eliminar(Cuestionario $cuestionario): void
    {
        $cuestionario->delete();
    }
}
