<?php

namespace App\Services\EvaluacionesTecnicas;

use App\Models\EvaluacionTecnica;
use Carbon\CarbonInterface;

/**
 * Lectura de resultados de las evaluaciones técnicas («Seguimiento N»)
 * para otros módulos (p. ej. Nine Box). Solo cuenta evaluaciones
 * CONTESTADAS (con calificación 0-100); las pendientes o vencidas no
 * influyen en el promedio.
 */
class ResultadosEvaluacionTecnicaService
{
    /**
     * Promedio 0-100 de las evaluaciones técnicas contestadas de cada
     * colaborador dentro del rango [$desde, $hasta] (por fecha en que se
     * contestaron). Quien no tenga ninguna en el rango NO aparece.
     *
     * @param  array<int, int>  $colaboradorIds
     * @return array<int, float> colaborador_id => promedio (redondeado a 2 decimales)
     */
    public function promedioPorColaborador(array $colaboradorIds, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $ids = array_values(array_unique(array_map('intval', $colaboradorIds)));

        if ($ids === []) {
            return [];
        }

        $promedios = [];

        $filas = EvaluacionTecnica::query()
            ->whereIn('colaborador_id', $ids)
            ->whereNotNull('contestada_en')
            ->whereNotNull('calificacion')
            ->whereBetween('contestada_en', [$desde, $hasta])
            ->groupBy('colaborador_id')
            ->selectRaw('colaborador_id, AVG(calificacion) as promedio')
            ->toBase()
            ->get();

        foreach ($filas as $fila) {
            $promedios[(int) $fila->colaborador_id] = round((float) $fila->promedio, 2);
        }

        return $promedios;
    }
}
