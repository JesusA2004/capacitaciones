<?php

namespace App\Console\Commands;

use App\Models\Sucursal;
use App\Services\Headcount\CuadrePlantillaService;
use Illuminate\Console\Command;

/**
 * SOLO LECTURA: imprime el cuadre de plantilla (mismas cifras que el Inicio
 * de RH, HeadcountService + CuadrePlantillaService) por sucursal, la fila de
 * totales con la verificación «suma de filas = total», las invariantes y la
 * lista nominal de quién causa cada diferencia. No escribe nada.
 *
 *   php artisan headcount:diagnosticar-cuadre
 *   php artisan headcount:diagnosticar-cuadre --sucursal=Cuernavaca
 */
class DiagnosticarCuadreHeadcountCommand extends Command
{
    protected $signature = 'headcount:diagnosticar-cuadre
        {--sucursal= : Nombre, clave o id de una sucursal (sin opción: toda la empresa)}
        {--sin-nombres : No listar personas (solo cifras)}';

    protected $description = 'Solo lectura: cuadre de plantilla por sucursal (autorizadas = ocupadas + vacantes) y quién explica cada diferencia.';

    public function handle(CuadrePlantillaService $servicio): int
    {
        $alcance = null;
        $filtro = $this->option('sucursal');

        if (is_string($filtro) && trim($filtro) !== '') {
            $filtro = trim($filtro);
            $sucursal = Sucursal::query()
                ->where(fn ($q) => $q->where('nombre', $filtro)->orWhere('clave', $filtro)->orWhere('id', ctype_digit($filtro) ? (int) $filtro : 0))
                ->first() ?? Sucursal::query()->where('nombre', 'like', sprintf('%%%s%%', $filtro))->first();

            if ($sucursal === null) {
                $this->error(sprintf('No existe la sucursal «%s».', $filtro));

                return self::FAILURE;
            }

            $alcance = collect([(int) $sucursal->id]);
            $this->info(sprintf('Sucursal: %s (id %d)', $sucursal->nombre, $sucursal->id));
        }

        $cuadre = $servicio->cuadre($alcance);
        $this->info(sprintf('Cuadre de plantilla al %s (solo lectura, nada se modifica).', now()->format('d/m/Y H:i')));

        $columnas = ['Sucursal', 'Autor.', 'Ocup.', 'Vac.', 'Activos', 'Incorp.', 'Vigentes', 'Fuera pl.', 'Exced.', 'Sin ubic.', '2ª plaza', 'Bajas trám.', 'Bajas prog.', 'Cob. %', '¿Cuadra?'];
        $fila = fn (string $nombre, array $f): array => [
            $nombre, $f['autorizadas'], $f['ocupadas'], $f['vacantes'], $f['activos'], $f['en_incorporacion'], $f['vigentes'],
            $f['fuera_de_plantilla'], $f['excedentes'], $f['sin_ubicacion'], $f['segundas_plazas'], $f['bajas_en_tramite'], $f['bajas_programadas'], $f['cobertura'],
            $f['autorizadas'] === $f['ocupadas'] + $f['vacantes'] && $f['vigentes'] + $f['segundas_plazas'] === $f['ocupadas'] + $f['excedentes'] + $f['fuera_de_plantilla'] + $f['sin_ubicacion'] ? 'sí' : 'NO',
        ];

        $filas = array_map(fn (array $f): array => $fila(($f['es_corporativo'] ? '[Corp] ' : '').$f['sucursal'], $f), $cuadre['filas']);
        $filas[] = $fila('SUBTOTAL Corporativo', $cuadre['corporativo']);
        $filas[] = $fila('SUBTOTAL Sucursales', $cuadre['sucursales']);
        $filas[] = $fila('TOTAL', $cuadre['total']);
        $this->table($columnas, $filas);

        $t = $cuadre['total'];
        $this->line(sprintf('Autorizadas = Ocupadas + Vacantes: %d = %d + %d', $t['autorizadas'], $t['ocupadas'], $t['vacantes']));
        $this->line(sprintf('Vigentes (%d laborando + %d en incorporación) + %d segundas plazas = %d ocupan plaza + %d fuera de plantilla + %d excedentes + %d sin sucursal/puesto', $t['activos'], $t['en_incorporacion'], $t['segundas_plazas'], $t['ocupadas'], $t['fuera_de_plantilla'], $t['excedentes'], $t['sin_ubicacion']));
        $this->line(sprintf('Autorizadas − Activos (solo laborando) = %d; Vacantes = %d; diferencia = %d (en incorporación %d, fuera/excedentes/sin ubicación %d, segundas plazas −%d).',
            $t['autorizadas'] - $t['activos'], $t['vacantes'], $t['autorizadas'] - $t['activos'] - $t['vacantes'], $t['en_incorporacion'], $t['fuera_de_plantilla'] + $t['excedentes'] + $t['sin_ubicacion'], $t['segundas_plazas']));
        $this->line(sprintf('Bajas en trámite con último día pasado (ya NO cuentan): %d · bajas programadas (aún cuentan): %d', $t['bajas_en_tramite'], $t['bajas_programadas']));

        if ($cuadre['problemas'] === []) {
            $this->info('Invariantes y suma de filas = total: TODO CUADRA.');
        } else {
            $this->error('NO CUADRA:');

            foreach ($cuadre['problemas'] as $problema) {
                $this->line('  - '.$problema);
            }
        }

        $this->newLine();
        $this->info(sprintf('Diferencias explicadas (%d):', count($cuadre['diferencias'])));

        foreach ($cuadre['diferencias'] as $d) {
            $this->line(sprintf('[%s] %s%s · %d — %s', $d['motivo_etiqueta'], $d['sucursal'], $d['puesto'] !== null ? ' · '.$d['puesto'] : '', $d['cantidad'], $d['detalle']));

            if ($this->option('sin-nombres')) {
                continue;
            }

            foreach ($d['personas'] as $p) {
                $this->line(sprintf('    · %s | nº %s | %s | %s | registrada en %s', $p['nombre'], $p['numero_empleado'] ?? 's/n', $p['estatus'], $p['puesto'] ?? 'sin puesto', $p['sucursal_registrada'] ?? 'sin sucursal'));
            }
        }

        return $cuadre['problemas'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
