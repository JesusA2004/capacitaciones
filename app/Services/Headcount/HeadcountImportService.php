<?php

namespace App\Services\Headcount;

use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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
        'GESTOR VOLANTE' => 'Gestor Volante',
        'COORDINADORA DE SUCURSAL' => 'Coordinadora de Sucursal',
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
     *     filas_incompletas: array<int, string>,
     * }
     */
    public function importar(string $rutaArchivo, ?User $usuario, bool $soloFaltantes = false): array
    {
        $spreadsheet = IOFactory::load($rutaArchivo);

        $resultado = [
            'creados' => 0,
            'actualizados' => 0,
            'sin_cambio' => 0,
            'sucursales_sin_match' => [],
            'puestos_sin_match' => [],
            'conflictos' => [],
            'filas_incompletas' => [],
        ];

        $sucursalesPorNombre = Sucursal::query()->get(['id', 'nombre'])
            ->keyBy(fn (Sucursal $s) => $this->normalizar($s->nombre));

        // Nombres del Excel que el negocio confirmó como otra sucursal del
        // catálogo («TULA DE ALLENDE» = «Tula»). Lista cerrada en
        // config/headcount.php: nunca por prefijo ni parecido.
        $alias = [];
        foreach ((array) config('headcount.alias_sucursales', []) as $nombreExcel => $nombreCatalogo) {
            $alias[$this->normalizar((string) $nombreExcel)] = $this->normalizar((string) $nombreCatalogo);
        }

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
                $sucursal = $sucursalesPorNombre->get($sucursalNormalizada)
                    ?? $sucursalesPorNombre->get($alias[$sucursalNormalizada] ?? '');

                foreach ($bloque['incompletas'] as $incompleta) {
                    $resultado['filas_incompletas'][] = sprintf('%s (hoja %s, sucursal %s)', $incompleta, trim($nombreHoja), $sucursalNombre);
                }

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

                    // Seeder/arranque: nunca pisa una plantilla que ya existe
                    // (pudo capturarla RH después de la primera carga).
                    if ($existente !== null && ($soloFaltantes || (int) $existente->plantilla_autorizada === $fila['plantilla_autorizada'])) {
                        $resultado['sin_cambio']++;

                        continue;
                    }

                    DB::transaction(function () use ($sucursal, $puesto, $fila, $nombreHoja, $usuario, $existente): void {
                        $target = HeadcountTarget::query()->updateOrCreate(
                            ['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id],
                            [
                                'empresa_id' => $sucursal->empresa_id,
                                'plantilla_autorizada' => $fila['plantilla_autorizada'],
                                'fuente' => 'excel:'.Str::slug($nombreHoja),
                                'fecha_corte' => Carbon::today(),
                                'updated_by_id' => $usuario?->id,
                                'created_by_id' => $existente->created_by_id ?? $usuario?->id,
                            ],
                        );

                        HeadcountTargetHistorial::query()->create([
                            'headcount_target_id' => $target->id,
                            'sucursal_id' => $sucursal->id,
                            'puesto_id' => $puesto->id,
                            'valor_anterior' => $existente?->plantilla_autorizada,
                            'valor_nuevo' => $fila['plantilla_autorizada'],
                            'fuente' => 'excel:'.Str::slug($nombreHoja),
                            'motivo' => 'Importación del Excel de headcount',
                            'user_id' => $usuario?->id,
                        ]);
                    });

                    $existente === null ? $resultado['creados']++ : $resultado['actualizados']++;
                }
            }
        }

        return $resultado;
    }

    /**
     * Recorre la hoja y arma bloques {sucursal, filas[], incompletas[]}.
     * Cada hoja tiene DOS bloques por fila de sucursal (columnas A-D y
     * F-I), tratados de forma independiente.
     *
     * Un bloque empieza en la celda con el nombre de la sucursal cuya fila
     * siguiente dice MODALIDAD, y termina en la fila «Totales:» (que en el
     * Excel real cae en B, C o G, no en la columna de modalidad), en la
     * primera fila vacía, o justo antes del nombre de la sucursal siguiente
     * cuando el Excel no deja renglón en blanco entre bloques (hoja Q1 del
     * 16-10-2026: IXTLAHUACA pegado a ATLACOMULCO). Una fila con número
     * pero sin modalidad, o con modalidad pero sin número, no se adivina:
     * se devuelve en «incompletas» para reportarla.
     *
     * @return array<int, array{sucursal: string, filas: array<int, array{modalidad: string, plantilla_autorizada: int}>, incompletas: array<int, string>}>
     */
    private function bloquesDeSucursal(Worksheet $hoja): array
    {
        $bloques = [];
        $highestRow = $hoja->getHighestRow();

        // Primera columna de cada bloque: A (A-D) y F (F-I).
        foreach ([1, 6] as $columna) {
            $filaActual = 1;

            while ($filaActual < $highestRow) {
                $nombre = $this->textoCelda($hoja, $columna, $filaActual);

                if ($nombre === '' || $this->esEncabezado($nombre) || ! $this->esEncabezado($this->textoCelda($hoja, $columna, $filaActual + 1))) {
                    $filaActual++;

                    continue;
                }

                $bloque = ['sucursal' => $nombre, 'filas' => [], 'incompletas' => []];
                $filaDatos = $filaActual + 2;

                for (; $filaDatos <= $highestRow; $filaDatos++) {
                    $textos = array_map(fn (int $c): string => $this->textoCelda($hoja, $c, $filaDatos), range($columna, $columna + 3));

                    if (array_filter($textos, fn (string $t): bool => Str::startsWith(mb_strtoupper($t), 'TOTALES')) !== []) {
                        $filaDatos++;

                        break;
                    }

                    $esInicioDeOtroBloque = $textos[0] !== '' && $this->esEncabezado($this->textoCelda($hoja, $columna, $filaDatos + 1));

                    $filaVacia = array_filter(range($columna, $columna + 3), function (int $c) use ($hoja, $filaDatos): bool {
                        $valor = $hoja->getCellByColumnAndRow($c, $filaDatos)->getCalculatedValue();

                        return $valor !== null && trim(is_scalar($valor) ? (string) $valor : '') !== '';
                    }) === [];

                    if ($filaVacia || $esInicioDeOtroBloque) {
                        break;
                    }

                    $modalidad = $textos[0];
                    $autorizada = $hoja->getCellByColumnAndRow($columna + 1, $filaDatos)->getCalculatedValue();
                    $celda = Coordinate::stringFromColumnIndex($columna).$filaDatos;

                    if ($modalidad !== '' && is_numeric($autorizada)) {
                        $bloque['filas'][] = ['modalidad' => $modalidad, 'plantilla_autorizada' => (int) $autorizada];
                    } elseif ($modalidad === '' && is_numeric($autorizada)) {
                        $bloque['incompletas'][] = sprintf('Fila %d sin modalidad (celda %s vacía) con plantilla autorizada %d: no se importa', $filaDatos, $celda, (int) $autorizada);
                    } elseif ($modalidad !== '' && ! in_array(mb_strtoupper($modalidad), self::MODALIDADES_EXCLUIDAS, true)) {
                        $bloque['incompletas'][] = sprintf('%s (celda %s) sin número de plantilla autorizada: no se importa', $modalidad, $celda);
                    }
                }

                $bloques[] = $bloque;
                $filaActual = $filaDatos;
            }
        }

        return $bloques;
    }

    private function textoCelda(Worksheet $hoja, int $columna, int $fila): string
    {
        $valor = $hoja->getCellByColumnAndRow($columna, $fila)->getCalculatedValue();

        return is_string($valor) ? trim($valor) : '';
    }

    private function esEncabezado(string $texto): bool
    {
        return mb_strtoupper($texto) === 'MODALIDAD';
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = str_replace(['í', 'ó', 'á', 'é', 'ú'], ['i', 'o', 'a', 'e', 'u'], $texto);

        return preg_replace('/\s+/', ' ', $texto) ?? $texto;
    }
}
