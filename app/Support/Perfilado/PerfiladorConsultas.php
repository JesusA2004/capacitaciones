<?php

namespace App\Support\Perfilado;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta las consultas SQL de una petición: número, tiempo acumulado y
 * consultas repetidas (mismo SQL + mismos bindings). Solo se usa en
 * local/testing (`people:perfilar`, middleware PerfilarPeticion) — nunca en
 * producción: DB::listen tiene costo y guarda SQL en memoria.
 */
class PerfiladorConsultas
{
    private bool $escuchando = false;

    private bool $activo = false;

    /**
     * @var list<array{sql: string, ms: float, firma: string}>
     */
    private array $consultas = [];

    public function iniciar(): void
    {
        $this->consultas = [];
        $this->activo = true;

        if ($this->escuchando) {
            return;
        }

        $this->escuchando = true;

        DB::listen(function (QueryExecuted $consulta): void {
            if (! $this->activo) {
                return;
            }

            $this->consultas[] = [
                'sql' => $consulta->sql,
                'ms' => $consulta->time,
                'firma' => $consulta->sql.'|'.json_encode($consulta->bindings),
            ];
        });
    }

    public function detener(): void
    {
        $this->activo = false;
    }

    /**
     * @return array{consultas: int, sql_ms: float, duplicadas: int, top_duplicadas: array<string, int>}
     */
    public function resumen(): array
    {
        $porFirma = [];
        $porSql = [];

        foreach ($this->consultas as $consulta) {
            $porFirma[$consulta['firma']] = ($porFirma[$consulta['firma']] ?? 0) + 1;
            $porSql[$consulta['sql']] = ($porSql[$consulta['sql']] ?? 0) + 1;
        }

        $duplicadas = array_sum(array_map(fn (int $n): int => $n - 1, array_filter($porFirma, fn (int $n): bool => $n > 1)));

        arsort($porSql);

        return [
            'consultas' => count($this->consultas),
            'sql_ms' => round(array_sum(array_column($this->consultas, 'ms')), 1),
            'duplicadas' => $duplicadas,
            'top_duplicadas' => array_slice(array_filter($porSql, fn (int $n): bool => $n > 1), 0, 5, true),
        ];
    }
}
