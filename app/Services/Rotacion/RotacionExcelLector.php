<?php

namespace App\Services\Rotacion;

use App\Enums\NivelHallazgoRotacion;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Lee el Excel «INDICE DE ROTACIÓN DE PERSONAL» de RH (formato real en
 * docs/rotacion/ y claude/rotacion/, leído con PhpSpreadsheet — no se
 * asume su forma). Estructura encontrada en el archivo real:
 *
 *  - Hojas «INDICADOR 2025» (consolidado sin nombre de sucursal +
 *    bloques sueltos), «INDICADOR X SUCURSAL 2025» y «SUCURSALES 2026».
 *    El año sale del nombre de la hoja.
 *  - Por sucursal, un BLOQUE: el nombre (celda combinada F:J) arriba de
 *    una fila de encabezados con los meses en B..M (ENERO … SEPT, OCT,
 *    NOV, DIC) y TOTALES en N; debajo, con la etiqueta en la columna A:
 *    ALTAS, BAJAS, PLANTILLA (= plantilla anterior + altas − bajas), en la
 *    fila siguiente la PLANTILLA INICIAL (número suelto en A) y ROTACIÓN
 *    (= bajas × 100 / plantilla del mes). En N: totales, «PROMEDIO
 *    MENSUAL» y «ROTACIÓN ANUAL» (etiquetas en O) — se recalculan.
 *  - Al pie de cada hoja, resúmenes («Bajas enero», «Rotación General»,
 *    «SUCURSAL / ROTACIÓN ANUAL», «ROTACIÓN MENSUAL SUCURSALES») que solo
 *    suman los bloques: no se importan y se reporta que se recalculan.
 *
 * El lector se guía por las ETIQUETAS (meses y columna A), no por
 * posiciones fijas, para que el formato actualizado (que agrega BAJAS
 * VOLUNTARIAS / BAJAS INVOLUNTARIAS) y el original se lean igual. Todo lo
 * que no cuadra se devuelve como hallazgo; nada se ignora en silencio.
 *
 * @phpstan-type Hallazgo array{nivel: string, hoja: string, celda: string|null, sucursal: string|null, mensaje: string}
 * @phpstan-type MesLeido array{mes: int, altas: int, bajas: int, bajas_voluntarias: int|null, bajas_involuntarias: int|null, plantilla_inicial: int, plantilla_final: int, indice: float|null, indice_excel: float|null}
 * @phpstan-type Bloque array{hoja: string, anio: int|null, sucursal: string|null, fila: int, meses: list<MesLeido>}
 */
class RotacionExcelLector
{
    /** @var array<string, int> */
    private const MESES = [
        'enero' => 1, 'ene' => 1,
        'febrero' => 2, 'feb' => 2,
        'marzo' => 3, 'mar' => 3,
        'abril' => 4, 'abr' => 4,
        'mayo' => 5, 'may' => 5,
        'junio' => 6, 'jun' => 6,
        'julio' => 7, 'jul' => 7,
        'agosto' => 8, 'ago' => 8,
        'septiembre' => 9, 'setiembre' => 9, 'sept' => 9, 'sep' => 9,
        'octubre' => 10, 'oct' => 10,
        'noviembre' => 11, 'nov' => 11,
        'diciembre' => 12, 'dic' => 12,
    ];

    public const NOMBRES_MES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /** Etiquetas de la columna A que forman un bloque. */
    private const ETIQUETAS = [
        'altas' => 'altas',
        'bajas' => 'bajas',
        'bajas voluntarias' => 'bajas_voluntarias',
        'bajas involuntarias' => 'bajas_involuntarias',
        'plantilla' => 'plantilla',
        'rotacion' => 'rotacion',
    ];

    /** Filas que se buscan arriba del encabezado para el nombre de la sucursal. */
    private const FILAS_NOMBRE = 6;

    /** Filas que se buscan debajo del encabezado para las etiquetas. */
    private const FILAS_BLOQUE = 12;

    /** @var list<Hallazgo> */
    private array $hallazgos = [];

    /** @var array<int, array<int, mixed>> fila => columna => valor */
    private array $celdas = [];

    private string $hoja = '';

    /**
     * @return array{bloques: list<Bloque>, hallazgos: list<Hallazgo>, hojas: list<string>}
     */
    public function leer(string $ruta): array
    {
        try {
            $libro = IOFactory::load($ruta);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo abrir el archivo como Excel: '.$e->getMessage(), 0, $e);
        }

        $this->hallazgos = [];
        $bloques = [];
        $hojas = [];

        foreach ($libro->getAllSheets() as $hoja) {
            $hojas[] = $hoja->getTitle();
            foreach ($this->leerHoja($hoja) as $bloque) {
                $bloques[] = $bloque;
            }
        }

        return ['bloques' => $bloques, 'hallazgos' => $this->hallazgos, 'hojas' => $hojas];
    }

    /**
     * @return list<Bloque>
     */
    private function leerHoja(Worksheet $hoja): array
    {
        $this->hoja = $hoja->getTitle();
        $this->cargarCeldas($hoja);

        $encabezados = [];
        foreach (array_keys($this->celdas) as $fila) {
            $meses = $this->mesesDeFila($fila);
            if (count($meses) >= 6) {
                $encabezados[$fila] = $meses;
            }
        }

        if ($encabezados === []) {
            $this->hallazgo(NivelHallazgoRotacion::Informativo, null, null, 'La hoja no tiene bloques de rotación (fila de meses ENERO…DIC): no se importa nada de ella.');

            return [];
        }

        $anio = $this->anioDeHoja($hoja->getTitle());
        if ($anio === null) {
            $this->hallazgo(NivelHallazgoRotacion::Error, null, null, 'No se encontró el año (p. ej. «2026») en el nombre de la hoja ni en su título: sus bloques no se importan.');
        }

        $filasEncabezado = array_keys($encabezados);
        $ocupadas = [];
        $bloques = [];
        $finAnterior = 0;

        foreach ($filasEncabezado as $i => $filaEncabezado) {
            $siguiente = $filasEncabezado[$i + 1] ?? PHP_INT_MAX;
            [$bloque, $inicio, $fin] = $this->leerBloque($filaEncabezado, $encabezados[$filaEncabezado], $anio, $finAnterior, $siguiente);
            for ($f = $inicio; $f <= $fin; $f++) {
                $ocupadas[$f] = true;
            }
            $finAnterior = $fin;

            if ($bloque !== null) {
                $bloques[] = $bloque;
            }
        }

        $this->reportarFueraDeBloques($ocupadas);

        return $bloques;
    }

    private function cargarCeldas(Worksheet $hoja): void
    {
        $this->celdas = [];

        foreach ($hoja->getCoordinates() as $coordenada) {
            [$columna, $fila] = Coordinate::coordinateFromString($coordenada);
            $indice = Coordinate::columnIndexFromString($columna);
            $celda = $hoja->getCell($coordenada);
            $valor = $celda->getValue();

            if ($valor instanceof RichText) {
                $valor = $valor->getPlainText();
            } elseif ($celda->isFormula()) {
                try {
                    $valor = $celda->getCalculatedValue();
                } catch (Throwable) {
                    $valor = $celda->getOldCalculatedValue();
                }
            }

            if ($valor === null || (is_string($valor) && trim($valor) === '')) {
                continue;
            }

            $this->celdas[(int) $fila][$indice] = $valor;
        }

        ksort($this->celdas);
    }

    /**
     * @return array<int, int> columna => mes
     */
    private function mesesDeFila(int $fila): array
    {
        $meses = [];
        foreach ($this->celdas[$fila] ?? [] as $columna => $valor) {
            if (! is_string($valor)) {
                continue;
            }
            $mes = self::MESES[$this->normalizar($valor)] ?? null;
            if ($mes !== null) {
                $meses[$columna] = $mes;
            }
        }

        return $meses;
    }

    private function anioDeHoja(string $titulo): ?int
    {
        if (preg_match('/(20\d{2})/', $titulo, $m) === 1) {
            return (int) $m[1];
        }

        foreach (array_slice($this->celdas, 0, 10, true) as $columnas) {
            foreach ($columnas as $valor) {
                if (is_string($valor) && str_contains($this->normalizar($valor), 'rotacion') && preg_match('/(20\d{2})/', $valor, $m) === 1) {
                    return (int) $m[1];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $meses  columna => mes
     * @return array{0: Bloque|null, 1: int, 2: int} bloque, fila inicial y final que ocupa
     */
    private function leerBloque(int $filaEncabezado, array $meses, ?int $anio, int $finAnterior, int $siguienteEncabezado): array
    {
        [$sucursal, $filaNombre] = $this->nombreDelBloque($filaEncabezado, $finAnterior);
        $inicio = $filaNombre ?? $filaEncabezado;

        if ($sucursal === null) {
            // Sin nombre no hay a qué sucursal asignarlo (en el Excel real:
            // el consolidado general de «INDICADOR 2025» y dos bloques
            // sueltos de esa hoja). Se reporta y se salta completo.
            $fin = min($filaEncabezado + 7, $siguienteEncabezado - 1);
            $this->hallazgo(NivelHallazgoRotacion::Error, 'B'.$filaEncabezado, null, sprintf('Bloque de meses en la fila %d sin nombre de sucursal arriba del encabezado: no se importa. Si es el consolidado general, PEOPLE lo recalcula; si es una sucursal, escribe su nombre arriba de la fila de meses.', $filaEncabezado));

            return [null, $inicio, $fin];
        }

        $this->validarEncabezado($filaEncabezado, $meses, $sucursal);

        // Etiquetas de la columna A debajo del encabezado.
        $filas = [];
        $filaInicial = null;
        $fin = $filaEncabezado;
        $limite = min($filaEncabezado + self::FILAS_BLOQUE, $siguienteEncabezado - 1);

        for ($f = $filaEncabezado + 1; $f <= $limite; $f++) {
            $etiqueta = $this->celdas[$f][1] ?? null;

            if (is_string($etiqueta)) {
                $clave = self::ETIQUETAS[$this->normalizar($etiqueta)] ?? null;
                if ($clave === null) {
                    // Etiqueta desconocida dentro del bloque: se reporta y el bloque sigue.
                    if ($filas !== []) {
                        $this->hallazgo(NivelHallazgoRotacion::Advertencia, 'A'.$f, $sucursal, sprintf('Fila «%s» no reconocida dentro del bloque: no se importa.', trim($etiqueta)));
                        $fin = $f;
                    }

                    continue;
                }
                if (isset($filas[$clave])) {
                    $this->hallazgo(NivelHallazgoRotacion::Advertencia, 'A'.$f, $sucursal, sprintf('La fila «%s» aparece dos veces en el bloque: se usa la primera (fila %d).', trim($etiqueta), $filas[$clave]));

                    continue;
                }
                $filas[$clave] = $f;
                $fin = $f;

                if ($clave === 'plantilla' && is_numeric($this->celdas[$f + 1][1] ?? null)) {
                    $filaInicial = $f + 1;
                    $fin = max($fin, $f + 1);
                }

                continue;
            }

            // La fila de «ROTACIÓN ANUAL» (N + etiqueta en O) cierra el bloque.
            if (isset($filas['rotacion']) && $f === $filas['rotacion'] + 1 && isset($this->celdas[$f])) {
                $fin = $f;
            }
        }

        if (! isset($filas['altas'], $filas['bajas'])) {
            $this->hallazgo(NivelHallazgoRotacion::Error, 'A'.$filaEncabezado, $sucursal, 'El bloque no tiene las filas ALTAS y BAJAS en la columna A: no se importa.');

            return [null, $inicio, $fin];
        }

        $plantillaInicial = null;
        if ($filaInicial !== null) {
            $valor = $this->entero($this->celdas[$filaInicial][1] ?? null);
            $plantillaInicial = is_int($valor) ? $valor : null;
        }

        $mesesLeidos = $this->leerMeses($meses, $filas, $plantillaInicial, $sucursal, $filaInicial);

        if ($mesesLeidos === null) {
            return [null, $inicio, $fin];
        }

        $this->validarTotales($filaEncabezado, $filas, $mesesLeidos, $sucursal);

        return [[
            'hoja' => $this->hoja,
            'anio' => $anio,
            'sucursal' => $sucursal,
            'fila' => $filaEncabezado,
            'meses' => $mesesLeidos,
        ], $inicio, $fin];
    }

    /**
     * Nombre de la sucursal: el texto más cercano arriba del encabezado que
     * no sea un título («ROTACIÓN DE PERSONAL 2025»…).
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function nombreDelBloque(int $filaEncabezado, int $finAnterior): array
    {
        for ($f = $filaEncabezado - 1; $f >= max($finAnterior + 1, $filaEncabezado - self::FILAS_NOMBRE); $f--) {
            foreach ($this->celdas[$f] ?? [] as $valor) {
                if (! is_string($valor)) {
                    continue;
                }
                $texto = trim($valor);
                $normal = $this->normalizar($texto);
                if ($normal === '' || str_contains($normal, 'rotacion') || preg_match('/20\d{2}/', $normal) === 1) {
                    continue;
                }

                return [$texto, $f];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<int, int>  $meses
     */
    private function validarEncabezado(int $fila, array $meses, ?string $sucursal): void
    {
        $vistos = [];
        foreach ($meses as $columna => $mes) {
            if (isset($vistos[$mes])) {
                $this->hallazgo(NivelHallazgoRotacion::Advertencia, $this->coordenada($columna, $fila), $sucursal, sprintf('El mes %s aparece dos veces en el encabezado: se usa la primera columna.', self::NOMBRES_MES[$mes]));
            }
            $vistos[$mes] = true;
        }

        $faltan = array_diff(array_keys(self::NOMBRES_MES), array_keys($vistos));
        if ($faltan !== []) {
            $this->hallazgo(NivelHallazgoRotacion::Advertencia, 'B'.$fila, $sucursal, sprintf('El encabezado no trae: %s.', implode(', ', array_map(fn (int $m): string => self::NOMBRES_MES[$m], $faltan))));
        }

        foreach ($this->celdas[$fila] ?? [] as $columna => $valor) {
            if (isset($meses[$columna]) || $columna === 1) {
                continue;
            }
            $normal = is_string($valor) ? $this->normalizar($valor) : '';
            if ($normal !== 'totales' && $normal !== 'total') {
                $this->hallazgo(NivelHallazgoRotacion::Advertencia, $this->coordenada($columna, $fila), $sucursal, sprintf('Columna «%s» no reconocida en el encabezado: no se importa.', is_scalar($valor) ? (string) $valor : '?'));
            }
        }
    }

    /**
     * @param  array<int, int>  $meses  columna => mes
     * @param  array<string, int>  $filas  etiqueta => fila
     * @return list<MesLeido>|null
     */
    private function leerMeses(array $meses, array $filas, ?int $plantillaInicial, ?string $sucursal, ?int $filaInicial): ?array
    {
        $porMes = [];
        foreach ($meses as $columna => $mes) {
            $porMes[$mes] ??= $columna;
        }
        ksort($porMes);

        $anterior = $plantillaInicial;
        $leidos = [];
        $sinCaptura = [];
        $tolerancia = (float) config('rotacion.tolerancia_indice', 0.05);

        foreach ($porMes as $mes => $columna) {
            $altas = $this->entero($this->celdas[$filas['altas']][$columna] ?? null);
            $bajas = $this->entero($this->celdas[$filas['bajas']][$columna] ?? null);
            $celdaAltas = $this->coordenada($columna, $filas['altas']);
            $celdaBajas = $this->coordenada($columna, $filas['bajas']);
            $plantillaExcel = isset($filas['plantilla']) ? $this->entero($this->celdas[$filas['plantilla']][$columna] ?? null) : null;

            if ($altas === null && $bajas === null) {
                $sinCaptura[] = $mes;
                if (is_int($plantillaExcel) && $plantillaExcel > 0 && $leidos === [] && $anterior === null) {
                    $anterior = $plantillaExcel;
                }

                continue;
            }

            if ($altas === false || $bajas === false) {
                $this->hallazgo(NivelHallazgoRotacion::Error, $altas === false ? $celdaAltas : $celdaBajas, $sucursal, sprintf('%s: ALTAS/BAJAS deben ser números enteros ≥ 0 (se encontró «%s»): el mes no se importa.', self::NOMBRES_MES[$mes], $this->texto($this->celdas[$altas === false ? $filas['altas'] : $filas['bajas']][$columna] ?? null)));
                if (is_int($plantillaExcel)) {
                    $anterior = $plantillaExcel;
                }

                continue;
            }

            if ($altas === null || $bajas === null) {
                $this->hallazgo(NivelHallazgoRotacion::Advertencia, $altas === null ? $celdaAltas : $celdaBajas, $sucursal, sprintf('%s: la celda de %s está vacía; se toma como 0.', self::NOMBRES_MES[$mes], $altas === null ? 'ALTAS' : 'BAJAS'));
                $altas ??= 0;
                $bajas ??= 0;
            }

            $voluntarias = $this->opcional($filas, 'bajas_voluntarias', $columna, $mes, $sucursal);
            $involuntarias = $this->opcional($filas, 'bajas_involuntarias', $columna, $mes, $sucursal);
            if ($voluntarias !== null && $involuntarias !== null && $voluntarias + $involuntarias !== $bajas) {
                $this->hallazgo(NivelHallazgoRotacion::Advertencia, $celdaBajas, $sucursal, sprintf('%s: voluntarias (%d) + involuntarias (%d) no suman las BAJAS (%d).', self::NOMBRES_MES[$mes], $voluntarias, $involuntarias, $bajas));
            }

            if ($plantillaExcel === false) {
                $this->hallazgo(NivelHallazgoRotacion::Advertencia, $this->coordenada($columna, (int) $filas['plantilla']), $sucursal, sprintf('%s: la PLANTILLA no es un número entero; se recalcula.', self::NOMBRES_MES[$mes]));
                $plantillaExcel = null;
            }

            if ($anterior === null) {
                if (! is_int($plantillaExcel)) {
                    $this->hallazgo(NivelHallazgoRotacion::Error, $filaInicial !== null ? 'A'.$filaInicial : $celdaAltas, $sucursal, sprintf('%s: no hay PLANTILLA inicial (número en la columna A debajo de PLANTILLA) ni plantilla del mes: el mes no se importa.', self::NOMBRES_MES[$mes]));

                    continue;
                }
                $anterior = $plantillaExcel - $altas + $bajas;
                $this->hallazgo(NivelHallazgoRotacion::Advertencia, $celdaAltas, $sucursal, sprintf('%s: sin PLANTILLA inicial; se deduce %d (plantilla del mes − altas + bajas).', self::NOMBRES_MES[$mes], $anterior));
            }

            $esperada = $anterior + $altas - $bajas;
            if (is_int($plantillaExcel)) {
                $final = $plantillaExcel;
                if ($plantillaExcel !== $esperada) {
                    $this->hallazgo(NivelHallazgoRotacion::Advertencia, $this->coordenada($columna, (int) $filas['plantilla']), $sucursal, sprintf('%s: la PLANTILLA del Excel (%d) no es la anterior + altas − bajas (%d); se guarda la del Excel.', self::NOMBRES_MES[$mes], $plantillaExcel, $esperada));
                }
            } else {
                $final = $esperada;
                if (isset($filas['plantilla'])) {
                    $this->hallazgo(NivelHallazgoRotacion::Informativo, $this->coordenada($columna, $filas['plantilla']), $sucursal, sprintf('%s: PLANTILLA vacía; se calcula %d.', self::NOMBRES_MES[$mes], $final));
                }
            }

            $indice = $final > 0 ? round($bajas * 100 / $final, 2) : null;
            if ($indice === null) {
                $this->hallazgo(NivelHallazgoRotacion::Advertencia, $celdaBajas, $sucursal, sprintf('%s: plantilla 0, el índice no se puede calcular (en el Excel sale #DIV/0!).', self::NOMBRES_MES[$mes]));
            }

            $indiceExcel = null;
            if (isset($filas['rotacion'])) {
                $valor = $this->celdas[$filas['rotacion']][$columna] ?? null;
                if (is_int($valor) || is_float($valor)) {
                    $indiceExcel = round((float) $valor, 2);
                    if ($indice !== null && abs($indiceExcel - $indice) > $tolerancia) {
                        $this->hallazgo(NivelHallazgoRotacion::Advertencia, $this->coordenada($columna, $filas['rotacion']), $sucursal, sprintf('%s: la ROTACIÓN del Excel (%s %%) no es bajas × 100 / plantilla (%s %%); se guarda la recalculada.', self::NOMBRES_MES[$mes], $this->porcentaje($indiceExcel), $this->porcentaje($indice)));
                    }
                } elseif ($valor !== null && $indice !== null) {
                    $this->hallazgo(NivelHallazgoRotacion::Advertencia, $this->coordenada($columna, $filas['rotacion']), $sucursal, sprintf('%s: la ROTACIÓN del Excel es «%s»; se guarda la recalculada (%s %%).', self::NOMBRES_MES[$mes], $this->texto($valor), $this->porcentaje($indice)));
                }
            }

            $leidos[] = [
                'mes' => $mes,
                'altas' => $altas,
                'bajas' => $bajas,
                'bajas_voluntarias' => $voluntarias,
                'bajas_involuntarias' => $involuntarias,
                'plantilla_inicial' => $anterior,
                'plantilla_final' => $final,
                'indice' => $indice,
                'indice_excel' => $indiceExcel,
            ];
            $anterior = $final;
        }

        if ($leidos === []) {
            $this->hallazgo(NivelHallazgoRotacion::Informativo, 'B'.$filas['altas'], $sucursal, 'El bloque no tiene ningún mes capturado.');

            return null;
        }

        $despues = array_filter($sinCaptura, fn (int $m): bool => $m > $leidos[count($leidos) - 1]['mes']);
        $huecos = array_diff($sinCaptura, $despues);
        if ($despues !== []) {
            $rango = min($despues) === max($despues)
                ? mb_strtolower(self::NOMBRES_MES[min($despues)])
                : sprintf('%s a %s', mb_strtolower(self::NOMBRES_MES[min($despues)]), mb_strtolower(self::NOMBRES_MES[max($despues)]));
            $this->hallazgo(NivelHallazgoRotacion::Informativo, null, $sucursal, sprintf('Sin captura de %s: no se importa(n) todavía.', $rango));
        }
        if ($huecos !== []) {
            $this->hallazgo(NivelHallazgoRotacion::Advertencia, null, $sucursal, sprintf('Meses sin captura entre meses capturados (%s): no se importan; la plantilla sigue desde el último mes capturado.', implode(', ', array_map(fn (int $m): string => self::NOMBRES_MES[$m], $huecos))));
        }

        return $leidos;
    }

    /**
     * @param  array<string, int>  $filas
     */
    private function opcional(array $filas, string $clave, int $columna, int $mes, ?string $sucursal): ?int
    {
        if (! isset($filas[$clave])) {
            return null;
        }

        $valor = $this->entero($this->celdas[$filas[$clave]][$columna] ?? null);
        if ($valor === false) {
            $this->hallazgo(NivelHallazgoRotacion::Advertencia, $this->coordenada($columna, $filas[$clave]), $sucursal, sprintf('%s: %s no es un número entero; se deja vacío.', self::NOMBRES_MES[$mes], $clave === 'bajas_voluntarias' ? 'BAJAS VOLUNTARIAS' : 'BAJAS INVOLUNTARIAS'));

            return null;
        }

        return $valor;
    }

    /**
     * La columna TOTALES del Excel se recalcula; si su número no cuadra con
     * la suma de los meses importados, se avisa.
     *
     * @param  array<string, int>  $filas
     * @param  list<MesLeido>  $leidos
     */
    private function validarTotales(int $filaEncabezado, array $filas, array $leidos, ?string $sucursal): void
    {
        $columnaTotal = null;
        foreach ($this->celdas[$filaEncabezado] ?? [] as $columna => $valor) {
            if (is_string($valor) && in_array($this->normalizar($valor), ['totales', 'total'], true)) {
                $columnaTotal = $columna;
            }
        }

        if ($columnaTotal === null) {
            return;
        }

        foreach (['altas' => 'ALTAS', 'bajas' => 'BAJAS'] as $clave => $nombre) {
            $total = $this->entero($this->celdas[$filas[$clave]][$columnaTotal] ?? null);
            $suma = array_sum(array_column($leidos, $clave));
            if (is_int($total) && $total !== $suma) {
                $this->hallazgo(NivelHallazgoRotacion::Advertencia, $this->coordenada($columnaTotal, $filas[$clave]), $sucursal, sprintf('El TOTAL de %s del Excel (%d) no es la suma de los meses (%d); PEOPLE usa la suma.', $nombre, $total, $suma));
            }
        }
    }

    /**
     * Lo que queda fuera de los bloques (resúmenes al pie) no se importa:
     * se informa una vez por hoja, y los errores de fórmula (#REF!…) se
     * listan con su celda.
     *
     * @param  array<int, true>  $ocupadas
     */
    private function reportarFueraDeBloques(array $ocupadas): void
    {
        $filas = [];
        $errores = [];

        $primera = $ocupadas === [] ? 0 : min(array_keys($ocupadas));

        foreach ($this->celdas as $fila => $columnas) {
            // Lo de arriba del primer bloque es el título/notas de la hoja.
            if (isset($ocupadas[$fila]) || $fila < $primera) {
                continue;
            }
            foreach ($columnas as $columna => $valor) {
                if (is_string($valor) && str_starts_with(trim($valor), '#')) {
                    $errores[] = $this->coordenada($columna, $fila);
                }
                if (is_string($valor) && (str_contains($this->normalizar($valor), 'rotacion') && preg_match('/20\d{2}/', $valor) === 1)) {
                    continue; // título de la hoja
                }
                $filas[$fila] = true;
            }
        }

        if ($filas !== []) {
            $numeros = array_keys($filas);
            $this->hallazgo(NivelHallazgoRotacion::Informativo, null, null, sprintf('Filas %d a %d fuera de los bloques por sucursal (resúmenes como «Bajas enero», «Rotación General» o «ROTACIÓN ANUAL» por sucursal): no se importan, PEOPLE los recalcula.', min($numeros), max($numeros)));
        }

        if ($errores !== []) {
            $this->hallazgo(NivelHallazgoRotacion::Advertencia, implode(', ', array_slice($errores, 0, 12)).(count($errores) > 12 ? '…' : ''), null, sprintf('%d celda(s) del resumen con error de fórmula (#REF!, #DIV/0!…). No afecta lo importado, pero el resumen del Excel no es confiable.', count($errores)));
        }
    }

    /**
     * null = vacío; false = no es un entero ≥ 0.
     */
    private function entero(mixed $valor): int|false|null
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }

        if (is_string($valor) && is_numeric(trim($valor))) {
            $valor = (float) trim($valor);
        }

        if (is_int($valor)) {
            return $valor >= 0 ? $valor : false;
        }

        if (is_float($valor) && floor($valor) === $valor && $valor >= 0) {
            return (int) $valor;
        }

        return false;
    }

    private function normalizar(string $texto): string
    {
        $texto = Str::lower(Str::ascii(trim($texto)));
        $texto = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $texto);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    private function coordenada(int $columna, int $fila): string
    {
        return Coordinate::stringFromColumnIndex($columna).$fila;
    }

    private function texto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }

    private function porcentaje(float $valor): string
    {
        return number_format($valor, 2);
    }

    private function hallazgo(NivelHallazgoRotacion $nivel, ?string $celda, ?string $sucursal, string $mensaje): void
    {
        $this->hallazgos[] = [
            'nivel' => $nivel->value,
            'hoja' => $this->hoja,
            'celda' => $celda,
            'sucursal' => $sucursal,
            'mensaje' => $mensaje,
        ];
    }
}
