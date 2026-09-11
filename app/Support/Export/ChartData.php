<?php

namespace App\Support\Export;

/**
 * Normaliza una tabla genérica de reporte {columnas, filas} (la forma que ya
 * usan ReportesRhService/ReporteCumplimientoService) en series graficables,
 * con el mismo criterio para el PDF (App\Support\Export\Pdf\ChartRenderer) y
 * para Excel (App\Support\Export\Excel\ExcelChartFactory) — una tabla se
 * analiza una sola vez y ambos formatos dibujan exactamente lo mismo.
 *
 * Reglas:
 * - Sólo columnas 2+ que sean 100% numéricas (o vacías) se consideran series;
 *   si ninguna columna calza, no hay gráfica (fromTable() regresa null).
 * - Una fila cuya primera columna sea literalmente "Total" se excluye de la
 *   gráfica (ya es la suma de las demás; incluirla dobla el peso visual).
 * - Si `columnas[0]` luce como "Mes Año" (ej. "Enero 2025") se trata como
 *   serie de tiempo: nunca se recorta, se grafica completa y en orden.
 * - Si no es serie de tiempo y hay más de 15 categorías, se recortan a las
 *   15 con mayor valor en la primera serie (la mayoría de reportes ya vienen
 *   ordenados desc, ver ReportesRhService::empleadosAgrupados()/candidatosAgrupados()).
 */
final class ChartData
{
    private const MAX_CATEGORIAS = 15;

    private const MAX_CATEGORIAS_DONUT = 6;

    /**
     * @param  array<int, string>  $categorias
     * @param  array<int, array{nombre: string, valores: array<int, float>}>  $series
     */
    private function __construct(
        public readonly string $tipo,
        public readonly array $categorias,
        public readonly array $series,
        public readonly bool $recortado,
    ) {}

    /**
     * @param  array<int, string>  $columnas
     * @param  array<int, array<int, string|int|float|null>>  $filas
     */
    public static function fromTable(array $columnas, array $filas): ?self
    {
        if (count($columnas) < 2 || count($filas) === 0) {
            return null;
        }

        $filas = array_values(array_filter(
            $filas,
            fn (array $fila) => mb_strtolower(trim((string) ($fila[0] ?? ''))) !== 'total',
        ));

        if ($filas === []) {
            return null;
        }

        $indicesNumericos = self::columnasNumericas($columnas, $filas);

        if ($indicesNumericos === []) {
            return null;
        }

        $categorias = array_map(fn (array $fila) => (string) ($fila[0] ?? '—'), $filas);
        $esSerieTiempo = self::pareceSerieTiempo($categorias);

        $series = array_map(fn (int $indice) => [
            'nombre' => $columnas[$indice],
            'valores' => array_map(fn (array $fila) => self::aFloat($fila[$indice] ?? null), $filas),
        ], $indicesNumericos);

        $recortado = false;

        if (! $esSerieTiempo && count($categorias) > self::MAX_CATEGORIAS) {
            $orden = self::ordenPorPrimeraSerie($series[0]['valores']);
            $orden = array_slice($orden, 0, self::MAX_CATEGORIAS);

            $categorias = array_map(fn (int $i) => $categorias[$i], $orden);
            $series = array_map(fn (array $serie) => [
                'nombre' => $serie['nombre'],
                'valores' => array_map(fn (int $i) => $serie['valores'][$i], $orden),
            ], $series);
            $recortado = true;
        }

        $tipo = match (true) {
            count($indicesNumericos) > 1 => 'multi-serie',
            $esSerieTiempo => 'tiempo',
            count($categorias) <= self::MAX_CATEGORIAS_DONUT => 'distribucion',
            default => 'barras',
        };

        return new self($tipo, $categorias, $series, $recortado);
    }

    /**
     * @param  array<int, float>  $valores
     * @return array<int, int> índices ordenados desc por valor
     */
    private static function ordenPorPrimeraSerie(array $valores): array
    {
        $indices = array_keys($valores);
        usort($indices, fn (int $a, int $b) => $valores[$b] <=> $valores[$a]);

        return $indices;
    }

    /**
     * @param  array<int, string>  $columnas
     * @param  array<int, array<int, string|int|float|null>>  $filas
     * @return array<int, int>
     */
    private static function columnasNumericas(array $columnas, array $filas): array
    {
        $indices = [];

        for ($i = 1; $i < count($columnas); $i++) {
            $esNumerica = true;

            foreach ($filas as $fila) {
                $valor = $fila[$i] ?? null;

                if ($valor !== null && $valor !== '' && ! is_numeric($valor)) {
                    $esNumerica = false;
                    break;
                }
            }

            if ($esNumerica) {
                $indices[] = $i;
            }
        }

        return $indices;
    }

    /**
     * @param  array<int, string>  $categorias
     */
    private static function pareceSerieTiempo(array $categorias): bool
    {
        if (count($categorias) < 3) {
            return false;
        }

        $coincidencias = 0;

        foreach ($categorias as $categoria) {
            if (preg_match('/^[\p{L}]+\.?\s+\d{4}$/u', trim($categoria)) === 1) {
                $coincidencias++;
            }
        }

        return $coincidencias === count($categorias);
    }

    private static function aFloat(string|int|float|null $valor): float
    {
        return $valor === null || $valor === '' ? 0.0 : (float) $valor;
    }
}
