<?php

namespace App\Services\Expedientes\MigracionInicial\Contracts;

/**
 * Todo lector de un archivo de migración inicial (formato único BASE_GENERAL
 * o formato nuevo por sucursales SEMANA 41) produce la MISMA estructura
 * canónica que consume PlanificadorMigracion. Único punto acoplado al
 * formato: a partir de aquí nadie vuelve a saber de qué Excel vino.
 */
interface LectorMigracion
{
    /**
     * @return array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string, formato: string}
     */
    public function leer(string $ruta): array;
}
