<?php

namespace App\Services\Evaluacion;

use App\Models\BancoPregunta;
use App\Models\Pregunta;
use Illuminate\Support\Facades\DB;

/**
 * Gestion del banco de preguntas reutilizable entre cuestionarios. Las
 * opciones de una pregunta siempre se reemplazan por completo al editar
 * (no se intenta diffear una por una): es lo mas simple y estas preguntas
 * no tienen historial que preservar a nivel de opcion individual.
 */
class BancoPreguntaService
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crearPregunta(BancoPregunta $banco, array $datos): Pregunta
    {
        return DB::transaction(function () use ($banco, $datos) {
            $opciones = $datos['opciones'] ?? [];
            unset($datos['opciones']);

            $pregunta = $banco->preguntas()->create($datos);
            $this->sincronizarOpciones($pregunta, $opciones);

            return $pregunta->fresh('opciones');
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizarPregunta(Pregunta $pregunta, array $datos): Pregunta
    {
        return DB::transaction(function () use ($pregunta, $datos) {
            $opciones = $datos['opciones'] ?? [];
            unset($datos['opciones']);

            $pregunta->update($datos);
            $this->sincronizarOpciones($pregunta, $opciones);

            return $pregunta->fresh('opciones');
        });
    }

    public function eliminarPregunta(Pregunta $pregunta): void
    {
        $pregunta->delete();
    }

    /**
     * @param  array<int, array{texto: string, es_correcta?: bool}>  $opciones
     */
    private function sincronizarOpciones(Pregunta $pregunta, array $opciones): void
    {
        $pregunta->opciones()->delete();

        foreach ($opciones as $indice => $opcion) {
            $pregunta->opciones()->create([
                'texto' => $opcion['texto'],
                'es_correcta' => (bool) ($opcion['es_correcta'] ?? false),
                'orden' => $indice,
            ]);
        }
    }
}
