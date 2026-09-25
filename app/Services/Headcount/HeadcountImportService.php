<?php

namespace App\Services\Headcount;

use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lee el Excel de headcount (hojas "Q1" y "SUCURSALES", layout confirmado a
 * mano sobre el archivo real — ver docs/HEADCOUNT_Y_VACANTES.md) y
 * actualiza `headcount_targets`. Nunca importa "plantilla actual" (esa
 * siempre se calcula en vivo, ver HeadcountService) ni crea sucursales o
 * puestos que no existan — un par sin match se reporta como pendiente,
 * mismo criterio que ImportarFormatosOriginalesCommand: no adivinar.
 *
 * Layout de cada hoja: bloques de sucursal en pares de columnas (A-D y
 * F-I), cada bloque tiene una fila con el nombre de la sucursal, una fila
 * de encabezados (MODALIDAD/PLANTILLA AUTORIZADA/PLANTILLA ACTUAL/VACANTES)
 * y varias filas de modalidad hasta una fila "Totales:". La columna B (o G)
 * es la única que se importa (PLANTILLA AUTORIZADA); C/D (o H/I) se
 * ignoran — son "plantilla actual"/"vacantes" del propio Excel, que este
 * sistema recalcula en vivo y nunca copia.
 */
class HeadcountImportService
{
    /**
     * Alias de modalidad del Excel -> nombre exacto de Puesto en el
     * sistema. INCAPACITADOS se excluye a propósito (no es una modalidad
     * operativa para efectos de vacante).
     */
    private const MAPA_PUESTOS = [
        'GESTOR DE RUTA' => 'Gestor',
        'GESTORES DE RUTA' => 'Gestor',
        'GESTOR VOLANTE' => 'Gestor volante',
        'COORDINADORA DE SUCURSAL' => 'Coordinadora',
        // "Gerente" era un duplicado de "Gerente de Sucursal" (ver
        // PuestoJerarquiaSeeder): el gerente del Excel es el de sucursal.
        'GERENTE' => 'Gerente de Sucursal',
        'SUBGERENTE' => 'Subgerente',
        'GESTOR GRUPAL' => 'Gestor grupal',
    ];

    private const MODALIDADES_EXCLUIDAS = ['INCAPACITADOS'];

    /**
     * @return array{
     *     creados: int, actualizados: int, sin_cambio: int,
     *     sucursales_sin_match: array<int, string>,
     *     puestos_sin_match: array<int, string>,
     *     conflictos: array<int, string>,
     * }
     */
    public function importar(string $rutaArchivo, User $usuario): array
    {
        $spreadsheet = IOFactory::load($rutaArchivo);

        $resultado = [
            'creados' => 0,
            'actualizados' => 0,
            'sin_cambio' => 0,
            'sucursales_sin_match' => [],
            'puestos_sin_match' => [],
            'conflictos' => [],
        ];

        $sucursalesPorNombre = Sucursal::query()->get(['id', 'nombre'])
            ->keyBy(fn (Sucursal $s) => $this->normalizar($s->nombre));

        $puestosPorNombre = Puesto::query()->get(['id', 'nombre'])
            ->keyBy(fn (Puesto $p) => $this->normalizar($p->nombre));

        $vistoEnEsteImport = []; // "sucursal_id:puesto_id" => autorizada, para detectar conflictos entre hojas/bloques.

        foreach ($spreadsheet->getSheetNames() as $nombreHoja) {
            $hoja = $spreadsheet->getSheetByName($nombreHoja);

            if ($hoja === null) {
                continue;
            }

            foreach ($this->bloquesDeSucursal($hoja) as $bloque) {
                $sucursalNombre = $bloque['sucursal'];
                $sucursalNormalizada = $this->normalizar($sucursalNombre);
                $sucursal = $sucursalesPorNombre->get($sucursalNormalizada);

                if ($sucursal === null) {
                    if (! in_array($sucursalNombre, $resultado['sucursales_sin_match'], true)) {
                        $resultado['sucursales_sin_match'][] = $sucursalNombre;
                    }

                    continue;
                }

                foreach ($bloque['filas'] as $fila) {
                    $modalidad = mb_strtoupper(trim($fila['modalidad']));

                    if ($modalidad === '' || in_array($modalidad, self::MODALIDADES_EXCLUIDAS, true)) {
                        continue;
                    }

                    $nombrePuesto = self::MAPA_PUESTOS[$modalidad] ?? null;
                    $puesto = $nombrePuesto !== null ? $puestosPorNombre->get($this->normalizar($nombrePuesto)) : null;

                    if ($puesto === null) {
                        $etiqueta = "{$fila['modalidad']} (hoja {$nombreHoja}, sucursal {$sucursalNombre})";

                        if (! in_array($etiqueta, $resultado['puestos_sin_match'], true)) {
                            $resultado['puestos_sin_match'][] = $etiqueta;
                        }

                        continue;
                    }

                    $clave = "{$sucursal->id}:{$puesto->id}";

                    if (isset($vistoEnEsteImport[$clave]) && $vistoEnEsteImport[$clave] !== $fila['plantilla_autorizada']) {
                        $resultado['conflictos'][] = "{$sucursalNombre} / {$puesto->nombre}: {$vistoEnEsteImport[$clave]} vs {$fila['plantilla_autorizada']} (se conserva el primer valor leído)";

                        continue;
                    }

                    $vistoEnEsteImport[$clave] = $fila['plantilla_autorizada'];

                    $existente = HeadcountTarget::query()
                        ->where('sucursal_id', $sucursal->id)
                        ->where('puesto_id', $puesto->id)
                        ->first();

                    if ($existente !== null && (int) $existente->plantilla_autorizada === $fila['plantilla_autorizada']) {
                        $resultado['sin_cambio']++;

                        continue;
                    }

                    HeadcountTarget::query()->updateOrCreate(
                        ['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id],
                        [
                            'empresa_id' => $sucursal->empresa_id,
                            'plantilla_autorizada' => $fila['plantilla_autorizada'],
                            'fuente' => 'excel:'.Str::slug($nombreHoja),
                            'fecha_corte' => Carbon::today(),
                            'updated_by_id' => $usuario->id,
                            'created_by_id' => $existente->created_by_id ?? $usuario->id,
                        ],
                    );

                    $existente === null ? $resultado['creados']++ : $resultado['actualizados']++;
                }
            }
        }

        return $resultado;
    }

    /**
     * Recorre la hoja fila por fila y arma bloques {sucursal, filas[]}.
     * Cada hoja tiene DOS bloques por fila de sucursal (columnas A-D y
     * F-I), tratados de forma independiente.
     *
     * @return array<int, array{sucursal: string, filas: array<int, array{modalidad: string, plantilla_autorizada: int}>}>
     */
    private function bloquesDeSucursal(Worksheet $hoja): array
    {
        $bloques = [];
        $highestRow = $hoja->getHighestRow();

        foreach ([['nombre' => 1, 'modalidad' => 1, 'autorizada' => 2], ['nombre' => 6, 'modalidad' => 6, 'autorizada' => 7]] as $columnas) {
            $filaActual = 1;

            while ($filaActual <= $highestRow) {
                $celdaNombre = $hoja->getCellByColumnAndRow($columnas['nombre'], $filaActual)->getCalculatedValue();

                if (! is_string($celdaNombre) || trim($celdaNombre) === '' || mb_strtoupper(trim($celdaNombre)) === 'MODALIDAD') {
                    $filaActual++;

                    continue;
                }

                $siguienteEsEncabezado = mb_strtoupper(trim((string) ($hoja->getCellByColumnAndRow($columnas['modalidad'], $filaActual + 1)->getCalculatedValue() ?? ''))) === 'MODALIDAD';

                if (! $siguienteEsEncabezado) {
                    $filaActual++;

                    continue;
                }

                $bloque = ['sucursal' => trim($celdaNombre), 'filas' => []];
                $filaDatos = $filaActual + 2;

                while ($filaDatos <= $highestRow) {
                    $modalidad = $hoja->getCellByColumnAndRow($columnas['modalidad'], $filaDatos)->getCalculatedValue();
                    $primerCelda = $hoja->getCellByColumnAndRow(1, $filaDatos)->getCalculatedValue();

                    if (is_string($primerCelda) && Str::startsWith(mb_strtoupper(trim((string) $primerCelda)), 'TOTALES')) {
                        break;
                    }

                    if (is_string($hoja->getCellByColumnAndRow($columnas['modalidad'], $filaDatos)->getCalculatedValue() ?? null)
                        && Str::startsWith(mb_strtoupper(trim((string) $modalidad)), 'TOTALES')) {
                        break;
                    }

                    if (is_string($modalidad) && trim($modalidad) !== '') {
                        $autorizada = $hoja->getCellByColumnAndRow($columnas['autorizada'], $filaDatos)->getCalculatedValue();

                        if (is_numeric($autorizada)) {
                            $bloque['filas'][] = [
                                'modalidad' => trim($modalidad),
                                'plantilla_autorizada' => (int) $autorizada,
                            ];
                        }
                    }

                    $filaDatos++;

                    // Fin defensivo: una fila totalmente vacía en ambas
                    // columnas de nombre marca el fin del bloque aunque no
                    // haya "Totales:" explícito.
                    $nombreVacio = trim((string) ($hoja->getCellByColumnAndRow(1, $filaDatos)->getCalculatedValue() ?? '')) === ''
                        && trim((string) ($hoja->getCellByColumnAndRow(6, $filaDatos)->getCalculatedValue() ?? '')) === '';

                    if ($nombreVacio && $filaDatos > $filaActual + 8) {
                        break;
                    }
                }

                $bloques[] = $bloque;
                $filaActual = $filaDatos;
            }
        }

        return $bloques;
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = str_replace(['í', 'ó', 'á', 'é', 'ú'], ['i', 'o', 'a', 'e', 'u'], $texto);

        return preg_replace('/\s+/', ' ', $texto) ?? $texto;
    }
}
