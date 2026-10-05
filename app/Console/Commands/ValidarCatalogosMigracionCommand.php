<?php

namespace App\Console\Commands;

use App\Services\Expedientes\MigracionInicial\InventarioNasHistorico;
use App\Services\Expedientes\MigracionInicial\LectorExcelMigracion;
use App\Services\Expedientes\MigracionInicial\ResolutorCatalogoMigracion;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan expedientes:validar-catalogos MR_LANA_PEOPLE_BASE_GENERAL_MIGRACION_FINAL.xlsx
 *
 * SOLO LECTURA. Recorre TODAS las filas de BASE_GENERAL y, por cada valor
 * único de Empresa / Sucursal oficial / Departamento / Puesto, dice a qué
 * catálogo canónico se traduce (exacto, alias o regla de contexto) o por qué
 * no. Sale con error si queda algún valor sin catálogo. Mismo resolutor que
 * el análisis de la migración (no duplica reglas).
 */
class ValidarCatalogosMigracionCommand extends Command
{
    protected $signature = 'expedientes:validar-catalogos {archivo : Ruta del Excel (hoja BASE_GENERAL)}';

    protected $description = 'Valida que Empresa/Sucursal/Departamento/Puesto de BASE_GENERAL tengan catálogo (sin escribir nada)';

    public function handle(LectorExcelMigracion $lector, ResolutorCatalogoMigracion $resolutor, InventarioNasHistorico $inventario): int
    {
        try {
            $excel = $lector->leer((string) $this->argument('archivo'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $resolutor->recargar();
        $filas = $excel['filas'];
        $unicos = ['empresa' => [], 'sucursal' => [], 'departamento' => [], 'puesto' => []];
        $fallas = ['empresa' => 0, 'sucursal' => 0, 'departamento' => 0, 'puesto' => 0];
        $ambiguos = [];

        foreach ($filas as $f) {
            if ($inventario->esSucursalExcluida($f['sucursal'])) {
                continue;
            }

            $sucursal = $inventario->resolverSucursal($f['sucursal']);
            $departamento = $resolutor->departamento($f['departamento']);
            $puesto = $resolutor->puesto($f['puesto'], $departamento, $sucursal);

            $unicos['empresa'][(string) $f['empresa']] = $resolutor->empresa($f['empresa'])->nombre ?? null;
            $unicos['sucursal'][(string) $f['sucursal']] = $sucursal?->nombre;
            $unicos['departamento'][(string) $f['departamento']] = $departamento?->nombre;
            $clavePuesto = sprintf('%s @ %s', $f['puesto'], $sucursal->nombre ?? $f['sucursal']);
            $unicos['puesto'][$clavePuesto] = $puesto['puesto'] !== null ? sprintf('%s (%s)', $puesto['puesto']->nombre, $puesto['regla']) : null;

            if ($puesto['puesto'] === null && str_contains((string) $puesto['motivo'], 'NO ENCONTRADO') === false) {
                $ambiguos[] = sprintf('Fila %d %s: %s', $f['fila'], $f['nombre_completo'], $puesto['motivo']);
            }
        }

        foreach ($unicos as $campo => $valores) {
            ksort($valores);
            $this->table([ucfirst($campo).' (Excel)', 'Catálogo'], array_map(fn ($origen, $destino) => [$origen, $destino ?? '— SIN CATÁLOGO —'], array_keys($valores), array_values($valores)));
            $fallas[$campo] = count(array_filter($valores, fn ($d) => $d === null));
        }

        foreach ($ambiguos as $linea) {
            $this->warn(' - '.$linea);
        }

        $this->line(sprintf('Filas: %d · Empresa desconocida: %d · Sucursal desconocida: %d · Departamento desconocido: %d · Puesto sin catálogo/ambiguo: %d', count($filas), $fallas['empresa'], $fallas['sucursal'], $fallas['departamento'], $fallas['puesto']));
        $this->line(sprintf('Origen NAS (%s): %s — desde ESTE proceso (%s); la pantalla usa el del servidor web.', $inventario->raiz(), $inventario->origenExiste() ? 'existe' : 'NO se ve', PHP_SAPI));

        return array_sum($fallas) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
