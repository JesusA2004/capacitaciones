<?php

namespace App\Services\Puestos\Descriptivos;

use App\Services\DocumentosMaestros\Deteccion\LectorTextoPdf;
use Illuminate\Support\Str;

/**
 * Lee un DESCRIPTIVO DE PUESTO oficial (PDF con capa de texto) y devuelve su
 * estructura. Sin OCR ni servicios externos: Poppler `pdftotext -bbox-layout`
 * (LectorTextoPdf) da cada palabra con su posición y aquí se reconstruyen
 * las zonas del formato por GEOMETRÍA:
 *
 * - Encabezado: nombre, área, jefe inmediato, edad, sexo, formación,
 *   experiencia, tipo de empleado, personal a cargo.
 * - Tabla: conocimiento · función · años | competencia · función. Cada fila
 *   se ancla en el número de «Años»; cada competencia, en su nombre.
 * - Objetivo y funciones numeradas (a lo largo de las páginas).
 * - Actividades críticas por ausencia + responsable, facultades, plan de
 *   formación y compromisos culturales (columnas con viñetas o números).
 * - Pie: elaboró, revisó, autorizó y fechas.
 *
 * Nada se infiere: lo que el PDF no trae queda vacío. Si el PDF no tiene
 * capa de texto, `requiere_ocr` = true (revisión manual; nunca un servicio
 * pagado).
 */
class LectorDescriptivoPdf
{
    /** @var list<array{pagina: int, texto: string, x: float, y: float, ancho: float, alto: float}> */
    private array $palabras = [];

    public function __construct(private readonly LectorTextoPdf $lector) {}

    public function disponible(): bool
    {
        return $this->lector->disponible();
    }

    /**
     * @return array<string, mixed>
     */
    public function leer(string $pdf): array
    {
        $this->palabras = array_map(fn (array $p): array => [
            'pagina' => (int) $p['pagina'], 'texto' => (string) $p['texto'], 'x' => (float) $p['x'], 'y' => (float) $p['y'],
            'ancho' => (float) $p['ancho'], 'alto' => (float) $p['alto'],
        ], $this->lector->palabras($pdf));

        if (count($this->palabras) < 40) {
            return ['requiere_ocr' => true, 'paginas' => 0];
        }

        $this->quitarEncabezadosYPies();

        $datos = [
            'requiere_ocr' => false,
            'paginas' => max(1, ...array_column($this->palabras, 'pagina')),
            ...$this->encabezado(),
            ...$this->tabla(),
            'objetivo' => $this->objetivo(),
            'funciones' => $this->funciones(),
            ...$this->secciones(),
        ];

        return $datos;
    }

    // ─────────────────────────── Encabezado ───────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function encabezado(): array
    {
        $pag1 = $this->dePagina(1);
        $tablaY = $this->buscar(['Conocimientos'], 1)['y'] ?? 80.0;
        $sup = array_values(array_filter($pag1, fn (array $p): bool => $p['y'] < $tablaY - 1));

        $nombreLbl = $this->buscar(['Nombre'], 1);
        $areaLbl = $this->buscar(['Área:'], 1);
        $jefeLbl = $this->buscar(['Jefe', 'Inmediato:'], 1);
        $edadLbl = $this->buscar(['Edad:'], 1);
        $sexoLbl = $this->buscar(['Sexo:'], 1);
        $formLbl = $this->buscar(['Formación'], 1);
        $expLbl = $this->buscar(['Experiencia'], 1);
        $tipoLbl = $this->buscar(['Tipo', 'de', 'empleado:'], 1);
        $cargoLbl = $this->buscar(['Personal', 'a', 'cargo:'], 1);

        // Borde de la columna derecha (Área/Edad/Experiencia), con holgura.
        $colDer = ($areaLbl['x'] ?? 100.0) - 3;
        $yJefe = $jefeLbl['y'] ?? ($edadLbl['y'] ?? 40.0);
        $yFin = $tipoLbl['y'] ?? ($cargoLbl['y'] ?? $tablaY);

        $nombre = $this->texto(array_filter($sup, fn ($p) => $p['x'] < $colDer && $p['y'] >= ($nombreLbl['y'] ?? 0) - 1 && $p['y'] < $yJefe - 1), ['Nombre', 'del', 'Puesto:', 'Puesto']);
        $area = $this->texto(array_filter($sup, fn ($p) => $p['x'] >= $colDer && $p['y'] >= ($areaLbl['y'] ?? 0) - 1 && $p['y'] < ($edadLbl['y'] ?? $yJefe) - 1), ['Área:']);

        $jefe = $jefeLbl !== null
            ? $this->texto(array_filter($sup, fn ($p) => $p['x'] < $colDer && abs($p['y'] - $jefeLbl['y']) < 1.5 && $p['x'] > $jefeLbl['x'] + 5), ['Jefe', 'Inmediato:'])
            : null;

        $edad = $edadLbl !== null
            ? $this->texto(array_filter($sup, fn ($p) => abs($p['y'] - $edadLbl['y']) < 1.5 && $p['x'] > $edadLbl['x'] && $p['x'] < ($sexoLbl['x'] ?? 999)), ['Edad:'])
            : null;
        $sexo = $sexoLbl !== null
            ? $this->texto(array_filter($sup, fn ($p) => abs($p['y'] - $sexoLbl['y']) < 1.5 && $p['x'] > $sexoLbl['x']), ['Sexo:'])
            : null;

        // Formación (columna izquierda) y experiencia (columna derecha).
        $formacion = $this->texto(array_filter($sup, fn ($p) => $p['x'] < $colDer && $p['y'] >= ($formLbl['y'] ?? $yJefe + 3) - 1 && $p['y'] < $yFin - 1));
        $formacion = (string) preg_replace('/^Formación\s+Académica\s*/u', '', $formacion);
        [$formMin, $formDes] = $this->minimaDeseable($formacion);

        $refX = null;
        foreach ($sup as $p) {
            if ($p['texto'] === 'Puesto:' && $p['x'] > $colDer + 20) {
                $refX = min($refX ?? 999, $p['x']);
            }
        }

        $experiencia = $this->texto(array_filter($sup, fn ($p) => $p['x'] >= $colDer && $p['x'] < ($refX ?? 999) - 1 && $p['y'] >= ($expLbl['y'] ?? $yJefe + 3) - 1 && $p['y'] < ($cargoLbl['y'] ?? $yFin) - 1));
        $experiencia = (string) preg_replace('/^Experiencia\s*/u', '', $experiencia);
        [$expMin, $expDes] = $this->minimaDeseable($experiencia);

        $referencias = [];
        if ($refX !== null) {
            foreach ($this->lineas(array_filter($sup, fn ($p) => $p['x'] >= $refX - 1 && $p['y'] >= ($expLbl['y'] ?? $yJefe + 3) - 1 && $p['y'] < ($cargoLbl['y'] ?? $yFin) - 1)) as $linea) {
                $t = trim((string) preg_replace('/^Puesto:\s*/u', '', $linea['texto']));
                if ($t !== '' && ! in_array($t, $referencias, true)) {
                    $referencias[] = $t;
                }
            }
        }

        $tipo = $tipoLbl !== null
            ? $this->texto(array_filter($pag1, fn ($p) => $p['x'] < $colDer && $p['y'] >= $tipoLbl['y'] - 1 && $p['y'] < $tablaY - 1), ['Tipo', 'de', 'empleado:'])
            : null;
        $inicioCabecera = $tablaY;
        foreach ($pag1 as $p) {
            if (in_array($p['texto'], ['Competencias', 'Habilidades:', 'Habilidades', 'Función:', 'Años:'], true) && $p['y'] < $tablaY + 1 && $p['y'] > $tablaY - 8) {
                $inicioCabecera = min($inicioCabecera, $p['y']);
            }
        }
        $aCargo = $cargoLbl !== null
            ? $this->texto(array_filter($pag1, fn ($p) => $p['x'] >= $colDer && $p['y'] >= $cargoLbl['y'] - 1 && $p['y'] < $inicioCabecera - 1), ['Personal', 'a', 'cargo:'])
            : null;

        return [
            'nombre_puesto' => $this->limpio($nombre),
            'area' => $this->limpio($area),
            'jefe_inmediato' => $this->limpio($jefe),
            'edad' => $this->limpio($edad),
            'sexo' => $this->limpio($sexo),
            'formacion' => ['minima' => $formMin, 'deseable' => $formDes],
            'experiencia' => ['minima' => $expMin, 'deseable' => $expDes, 'puestos_referencia' => $referencias],
            'tipo_empleado' => $this->limpio($tipo),
            'personal_a_cargo' => $this->limpio($aCargo),
        ];
    }

    /**
     * «Mínima: X Deseable: Y» → [X, Y].
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function minimaDeseable(string $texto): array
    {
        $partes = preg_split('/\s*Deseable:\s*/u', $texto, 2) ?: [$texto];
        $minima = trim((string) preg_replace('/^\s*Mínima:?\s*/u', '', $partes[0]));

        return [$this->limpio($minima), $this->limpio($partes[1] ?? null)];
    }

    // ─────────────────────────────── Tabla ───────────────────────────────

    /**
     * @return array{conocimientos: list<array{conocimiento: string, funcion: string|null, anios: string|null}>, competencias: list<array{competencia: string, funcion: string|null}>}
     */
    private function tabla(): array
    {
        $cab = $this->buscar(['Conocimientos'], 1);
        $obj = $this->buscar(['Objetivo'], 1);

        if ($cab === null) {
            return ['conocimientos' => [], 'competencias' => []];
        }

        $pag1 = $this->dePagina(1);
        $yIni = $cab['y'] + 2;
        $yFin = ($this->bloqueObjetivo()['desde'] ?? ($obj['y'] ?? 200.0) - 4) - 0.5;

        // Palabras de la cabecera (pueden quedar justo debajo de «Conocimientos»).
        $cabecera = ['requeridos', 'requeridos:', 'requeridas', 'requeridas:', 'Habilidades:', 'Habilidades', 'Competencias', 'Función:', 'Años:'];
        $celdas = array_values(array_filter($pag1, fn ($p) => $p['y'] > $yIni - 2 && $p['y'] < $yFin && ! ($p['y'] < $cab['y'] + 6 && in_array(mb_strtolower($p['texto']), array_map('mb_strtolower', $cabecera), true)) && ! (abs($p['y'] - $cab['y']) < 1.5)));

        // Columnas por DATOS (las etiquetas de la cabecera van centradas):
        // dónde empiezan los segmentos de cada renglón.
        $inicios = [];
        foreach ($this->lineas($celdas, true) as $l) {
            $previo = null;
            foreach ($l['palabras'] as $w) {
                if ($previo === null || $w['x'] - $previo > 2.5) {
                    $inicios[] = ['x' => $w['x'], 'numero' => preg_match('/^\d+(\.\d+)?$/', $w['texto']) === 1];
                }
                $previo = $w['x'] + $w['ancho'];
            }
        }
        usort($inicios, fn ($a, $b) => $a['x'] <=> $b['x']);
        $grupos = [];
        foreach ($inicios as $ini) {
            $ultimo = array_key_last($grupos);
            if ($ultimo !== null && $ini['x'] - $grupos[$ultimo]['max'] <= 5) {
                $grupos[$ultimo]['max'] = $ini['x'];
                $grupos[$ultimo]['n']++;
                $grupos[$ultimo]['numeros'] += $ini['numero'] ? 1 : 0;

                continue;
            }
            $grupos[] = ['x' => $ini['x'], 'max' => $ini['x'], 'n' => 1, 'numeros' => $ini['numero'] ? 1 : 0];
        }
        $grupos = array_values(array_filter($grupos, fn ($g) => $g['n'] >= 2));

        $columnas = [];
        $nombres = ['con', 'fcon'];
        foreach ($grupos as $g) {
            if ($g['numeros'] >= $g['n'] * 0.6 && ! in_array('anios', $columnas, true) && count($columnas) >= 2) {
                $columnas[] = 'anios';

                continue;
            }
            $columnas[] = array_shift($nombres) ?? (in_array('comp', $columnas, true) ? 'fcomp' : 'comp');
        }
        $limites = array_map(fn ($g) => $g['x'] - 2, $grupos);

        $col = function (array $p) use ($limites, $columnas): string {
            $indice = 0;
            foreach ($limites as $i => $x) {
                if ($p['x'] >= $x) {
                    $indice = $i;
                }
            }

            return $columnas[$indice] ?? 'fcomp';
        };

        $porCol = ['con' => [], 'fcon' => [], 'anios' => [], 'comp' => [], 'fcomp' => []];
        foreach ($celdas as $p) {
            $porCol[$col($p)][] = $p;
        }

        // Conocimientos: filas ancladas en «Años» (o en el propio conocimiento).
        $anclasCon = $porCol['anios'] !== []
            ? array_map(fn ($p) => $p['y'], $this->ordenarY($porCol['anios']))
            : array_map(fn ($b) => $b['centro'], $this->bloques($porCol['con'], 5.5));
        $filasCon = $this->asignar($anclasCon, ['con' => $porCol['con'], 'fcon' => $porCol['fcon'], 'anios' => $porCol['anios']]);

        $conocimientos = [];
        foreach ($filasCon as $f) {
            $nombre = $this->limpio($this->texto($f['con']));
            if ($nombre === null) {
                continue;
            }
            $conocimientos[] = ['conocimiento' => $nombre, 'funcion' => $this->limpio($this->texto($f['fcon'])), 'anios' => $this->limpio($this->texto($f['anios']))];
        }

        // Competencias: ancladas en su nombre.
        sort($anclasCon);
        // Misma tabla: las competencias comparten las filas de los conocimientos.
        $anclasComp = $porCol['anios'] !== [] ? $anclasCon : array_map(fn ($b) => $b['centro'], $this->bloques($porCol['comp'], 5.0));
        $competencias = [];
        $filasComp = $this->asignar($anclasComp, ['comp' => $porCol['comp'], 'fcomp' => []]);
        // Función de cada competencia, renglón por renglón: el que empieza
        // con minúscula (o va pegado al anterior) continúa la misma celda;
        // los demás van a la fila más cercana.
        $previo = null;
        foreach ($this->lineas($porCol['fcomp'], true) as $l) {
            $continua = $previo !== null && ($l['y'] - $previo['y'] <= 3.6 || preg_match('/^\p{Ll}/u', $l['texto']) === 1);
            if ($continua) {
                $fila = $previo['fila'];
            } else {
                $fila = 0;
                foreach ($anclasComp as $k => $ancla) {
                    if (abs($l['y'] + 1.5 - $ancla) < abs($l['y'] + 1.5 - $anclasComp[$fila])) {
                        $fila = $k;
                    }
                }
            }
            if (isset($filasComp[$fila])) {
                $filasComp[$fila]['fcomp'] = [...$filasComp[$fila]['fcomp'], ...$l['palabras']];
            }
            $previo = ['y' => $l['y'], 'fila' => $fila];
        }
        foreach ($filasComp as $f) {
            $nombre = $this->limpio((string) preg_replace('/^Habilidades:?\s*/u', '', $this->texto($f['comp'])));
            if ($nombre !== null) {
                $competencias[] = ['competencia' => $nombre, 'funcion' => $this->limpio($this->texto($f['fcomp']))];
            }
        }

        return ['conocimientos' => $conocimientos, 'competencias' => $competencias];
    }

    /**
     * Reparte palabras entre anclas verticales: cada una pertenece a la fila
     * cuya franja (punto medio entre anclas vecinas) la contiene.
     *
     * @param  list<float>  $anclas
     * @param  array<string, list<array<string, mixed>>>  $columnas
     * @return list<array<string, list<array<string, mixed>>>>
     */
    private function asignar(array $anclas, array $columnas): array
    {
        sort($anclas);
        $filas = array_map(fn () => array_fill_keys(array_keys($columnas), []), $anclas);

        if ($anclas === []) {
            return [];
        }

        foreach ($columnas as $clave => $palabras) {
            foreach ($palabras as $p) {
                $centro = $p['y'] + $p['alto'] / 2;
                $mejor = 0;
                foreach ($anclas as $i => $a) {
                    if (abs($centro - $a) < abs($centro - $anclas[$mejor])) {
                        $mejor = $i;
                    }
                }
                $filas[$mejor][$clave][] = $p;
            }
        }

        return $filas;
    }

    /**
     * Agrupa palabras de una columna en bloques separados por huecos
     * verticales mayores a $hueco (mm).
     *
     * @param  list<array<string, mixed>>  $palabras
     * @return list<array{centro: float}>
     */
    private function bloques(array $palabras, float $hueco): array
    {
        $lineas = $this->lineas($palabras);
        $bloques = [];
        $actual = null;

        foreach ($lineas as $l) {
            if ($actual !== null && $hueco >= $l['y'] - $actual['fin']) {
                $actual['fin'] = $l['y'];
                $actual['ys'][] = $l['y'];

                continue;
            }
            if ($actual !== null) {
                $bloques[] = $actual;
            }
            $actual = ['inicio' => $l['y'], 'fin' => $l['y'], 'ys' => [$l['y']]];
        }
        if ($actual !== null) {
            $bloques[] = $actual;
        }

        return array_map(fn ($b) => ['centro' => ($b['inicio'] + $b['fin']) / 2 + 1.5], $bloques);
    }

    // ───────────────────────── Objetivo y funciones ─────────────────────────

    /**
     * Objetivo: el párrafo que rodea la etiqueta «Objetivo del puesto»
     * (renglones contiguos, hueco ≤ 5.5 mm) hasta «Funciones…».
     *
     * @return array{texto: string|null, desde: float|null}
     */
    private function bloqueObjetivo(): array
    {
        $obj = $this->buscar(['Objetivo'], null);

        if ($obj === null) {
            return ['texto' => null, 'desde' => null];
        }

        $fun = $this->etiquetaFunciones();
        $finY = ($fun !== null && $fun['pagina'] === $obj['pagina']) ? $fun['y'] - 1 : $obj['y'] + 30;
        $lineas = $this->lineas(array_filter($this->dePagina($obj['pagina']), fn ($p) => $p['y'] < $finY), true);
        usort($lineas, fn ($a, $b) => $a['y'] <=> $b['y']);

        $indice = null;
        foreach ($lineas as $i => $l) {
            if (abs($l['y'] - $obj['y']) < 1.5) {
                $indice = $i;
            }
        }
        if ($indice === null) {
            return ['texto' => null, 'desde' => null];
        }

        $desde = $indice;
        while ($desde > 0 && $lineas[$desde]['y'] - $lineas[$desde - 1]['y'] <= 5.5) {
            $desde--;
        }

        $palabras = [];
        foreach (array_slice($lineas, $desde) as $l) {
            // Sin título «Funciones…», la lista numerada empieza justo después.
            if ($l['y'] > $obj['y'] + 1 && preg_match('/^\d{1,2}\.(\s|$)/u', $l['texto']) === 1) {
                break;
            }
            foreach ($l['palabras'] as $p) {
                if (! ($p['x'] <= $obj['x'] + 25 && in_array($p['texto'], ['Objetivo', 'del', 'puesto', 'puesto:'], true))) {
                    $palabras[] = $p;
                }
            }
        }

        return ['texto' => $this->limpio($this->texto($palabras)), 'desde' => $lineas[$desde]['y']];
    }

    private function objetivo(): ?string
    {
        return $this->bloqueObjetivo()['texto'];
    }

    /**
     * @return array{pagina: int, x: float, y: float}|null
     */
    private function etiquetaFunciones(): ?array
    {
        return $this->buscar(['Funciones', 'y', 'responsabilidades'], null) ?? $this->buscar(['Funciones', 'del', 'puesto'], null);
    }

    /**
     * @return list<string>
     */
    private function funciones(): array
    {
        // Sin título «Funciones…»: empiezan después del objetivo.
        $sinTitulo = $this->etiquetaFunciones() === null;
        $fun = $this->etiquetaFunciones() ?? $this->buscar(['Objetivo'], null);

        if ($fun === null) {
            return [];
        }

        $fin = $this->inicioSecciones();
        $items = [];
        $actual = null;

        foreach ($this->lineasOrdenadas() as $l) {
            if ([$l['pagina'], $l['y']] <= [$fun['pagina'], $fun['y'] + 1]) {
                continue;
            }
            if ($fin !== null && [$l['pagina'], $l['y']] >= [$fin['pagina'], $fin['y'] - 1]) {
                break;
            }
            if (preg_match('/^De manera Enunciativa/iu', $l['texto']) === 1) {
                continue;
            }
            if (preg_match('/^(\d+)\.\s*(.*)$/u', $l['texto'], $m) === 1) {
                if ($actual !== null) {
                    $items[] = $actual;
                }
                $actual = $m[2];

                continue;
            }
            if (str_starts_with($l['texto'], '•')) {
                break;
            }
            if ($actual === null && $sinTitulo) {
                continue;
            }
            $actual = $actual === null ? $l['texto'] : $actual.' '.$l['texto'];
        }
        if ($actual !== null) {
            $items[] = $actual;
        }

        return array_values(array_filter(array_map(fn ($i) => $this->limpio($i), $items)));
    }

    // ─────────────────── Secciones finales (por regiones) ───────────────────

    /**
     * @return array{pagina: int, y: float}|null
     */
    private function inicioSecciones(): ?array
    {
        $fun = $this->etiquetaFunciones();
        $candidatos = [];

        foreach ([['Actividades', 'criticas'], ['Actividades', 'críticas'], ['Responsable'], ['Responsable:'], ['Facultades:'], ['Plan', 'de', 'formación:'], ['Compromisos', 'culturales']] as $etiqueta) {
            foreach ($this->buscarTodas($etiqueta) as $e) {
                if ($fun === null || [$e['pagina'], $e['y']] > [$fun['pagina'], $fun['y']]) {
                    $candidatos[] = $e;
                }
            }
        }

        // Viñeta suelta tras las funciones (actividades críticas sin título).
        foreach ($this->lineasOrdenadas() as $l) {
            if ($fun !== null && [$l['pagina'], $l['y']] > [$fun['pagina'], $fun['y']] && str_starts_with($l['texto'], '•')) {
                $candidatos[] = ['pagina' => $l['pagina'], 'y' => $l['y'], 'x' => $l['x']];
                break;
            }
        }

        usort($candidatos, fn ($a, $b) => [$a['pagina'], $a['y']] <=> [$b['pagina'], $b['y']]);

        return $candidatos[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function secciones(): array
    {
        $etiquetas = [];
        $definiciones = [
            'actividades_criticas' => [['Actividades', 'criticas'], ['Actividades', 'críticas']],
            'responsables_ausencia' => [['Responsable:'], ['Responsable']],
            'facultades' => [['Facultades:']],
            'plan_formacion' => [['Plan', 'de', 'formación:']],
            'compromisos_culturales' => [['Compromisos', 'culturales']],
        ];
        $inicio = $this->inicioSecciones();

        foreach ($definiciones as $clave => $variantes) {
            foreach ($variantes as $v) {
                foreach ($this->buscarTodas($v) as $e) {
                    if ($inicio === null || [$e['pagina'], $e['y']] >= [$inicio['pagina'], $inicio['y'] - 1]) {
                        $etiquetas[$clave] ??= $e;
                    }
                }
            }
        }

        // Actividades críticas sin título: van a la izquierda del «Responsable».
        if (! isset($etiquetas['actividades_criticas']) && isset($etiquetas['responsables_ausencia'])) {
            $r = $etiquetas['responsables_ausencia'];
            $etiquetas['actividades_criticas'] = ['pagina' => $r['pagina'], 'y' => $r['y'], 'x' => 0.0, 'implicita' => true];
        }

        $resultado = array_fill_keys(array_keys($definiciones), []);

        foreach ($etiquetas as $clave => $e) {
            $resultado[$clave] = $this->itemsRegion($e, $etiquetas);
        }

        return $resultado;
    }

    /**
     * Ítems de la región de una etiqueta: a la derecha hasta la siguiente
     * etiqueta de su banda; hacia abajo hasta la siguiente banda o el pie.
     *
     * @param  array<string, mixed>  $e
     * @param  array<string, array<string, mixed>>  $todas
     * @return list<string>
     */
    private function itemsRegion(array $e, array $todas): array
    {
        $pagina = $e['pagina'];
        $banda = array_filter($todas, fn ($o) => $o['pagina'] === $pagina && abs($o['y'] - $e['y']) < 8);
        $xs = array_map(fn ($o) => $o['x'], $banda);
        sort($xs);

        $abajo = array_filter($todas, fn ($o) => $o['pagina'] === $pagina && $o['y'] > $e['y'] + 8);
        $yFin = $abajo !== [] ? min(array_map(fn ($o) => $o['y'], $abajo)) - 0.5 : 999.0;

        $palabras = array_values(array_filter($this->dePagina($pagina), fn ($p) => $p['y'] >= $e['y'] - 0.5 && $p['y'] < $yFin));
        $palabras = array_values(array_filter($palabras, fn ($p) => ! $this->esPalabraDeEtiqueta($p, $todas)));

        // Límites de columna: cada etiqueta de la banda reclama sus viñetas
        // (que pueden empezar antes que la etiqueta).
        $marcas = array_values(array_filter($palabras, fn ($p) => $this->esMarca($p['texto'])));
        $inicios = [];
        foreach ($xs as $i => $x) {
            $siguiente = $xs[$i + 1] ?? 999.0;
            $previo = $i > 0 ? end($inicios) : -1.0;
            // Viñetas propias: cerca de la etiqueta (pueden empezar hasta
            // 30 mm antes), después de la columna previa y antes de la siguiente.
            $propias = array_filter($marcas, fn ($m) => $m['x'] > $previo + 5 && $m['x'] >= $x - 30 && $m['x'] < $siguiente - 2);
            $inicios[] = min($x, $propias !== [] ? min(array_map(fn ($m) => $m['x'], $propias)) : $x);
        }

        $indice = array_search($e['x'], $xs, true);
        $desde = $inicios[(int) $indice] - 1.5;
        $hasta = isset($inicios[(int) $indice + 1]) ? $inicios[(int) $indice + 1] - 1.5 : 999.0;
        $region = array_values(array_filter($palabras, fn ($p) => $p['x'] >= $desde && $p['x'] < $hasta));

        return $this->itemsDe($region);
    }

    /**
     * Ítems de una región: separados por viñetas (•, -, «1.»); subcolumnas
     * de viñetas (p. ej. dos columnas de plan de formación) se leen en orden.
     *
     * @param  list<array<string, mixed>>  $region
     * @return list<string>
     */
    private function itemsDe(array $region): array
    {
        $marcas = array_values(array_filter($region, fn ($p) => $this->esMarca($p['texto'])));
        $columnas = [];
        foreach ($marcas as $m) {
            $encontrada = false;
            foreach ($columnas as $c) {
                if (abs($c - $m['x']) < 15) {
                    $encontrada = true;
                }
            }
            if (! $encontrada) {
                $columnas[] = $m['x'];
            }
        }
        sort($columnas);

        if ($columnas === []) {
            $texto = $this->limpio($this->texto($region));

            return $texto !== null ? [$texto] : [];
        }

        $items = [];
        foreach ($columnas as $i => $cx) {
            $hasta = $columnas[$i + 1] ?? 999.0;
            $sub = array_filter($region, fn ($p) => $p['x'] >= $cx - 1.5 && $p['x'] < $hasta - 1.5);
            if ($i === 0) {
                $sub = array_filter($region, fn ($p) => $p['x'] < $hasta - 1.5);
            }
            $actual = null;
            foreach ($this->lineas($sub) as $l) {
                if (preg_match('/^(?:•|-|–|\d+\.)\s*(.*)$/u', $l['texto'], $m) === 1) {
                    if ($actual !== null) {
                        $items[] = $actual;
                    }
                    $actual = $m[1];

                    continue;
                }
                $actual = $actual === null ? $l['texto'] : $actual.' '.$l['texto'];
            }
            if ($actual !== null) {
                $items[] = $actual;
            }
        }

        return array_values(array_filter(array_map(fn ($i) => $this->limpio($i), $items)));
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, array<string, mixed>>  $etiquetas
     */
    private function esPalabraDeEtiqueta(array $p, array $etiquetas): bool
    {
        $palabrasEtiqueta = ['Actividades', 'criticas', 'críticas', 'asociadas', 'al', 'puesto', 'sustituidas', 'por', 'ausencia:', 'Responsable', 'Responsable:', 'Facultades:', 'Plan', 'de', 'formación:', 'Compromisos', 'culturales', 'y', 'imagen'];

        foreach ($etiquetas as $e) {
            if (($e['implicita'] ?? false) || $e['pagina'] !== $p['pagina']) {
                continue;
            }
            // La etiqueta y su segunda línea («ausencia:», «de imagen»).
            if ($p['y'] >= $e['y'] - 0.5 && $p['y'] <= $e['y'] + 5 && $p['x'] >= $e['x'] - 1 && $p['x'] <= $e['x'] + 95 && in_array(mb_strtolower($p['texto']), array_map('mb_strtolower', $palabrasEtiqueta), true)) {
                if ($p['y'] <= $e['y'] + 1 || in_array($p['texto'], ['ausencia:', 'de', 'imagen'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function esMarca(string $texto): bool
    {
        return in_array($texto, ['•', '-', '–', '●', '▪'], true) || preg_match('/^\d{1,2}\.$/', $texto) === 1;
    }

    // ───────────────────────────── Pie (metadatos) ─────────────────────────────

    /**
     * @return array<string, string|null>
     */
    public function metadatos(string $pdf): array
    {
        $todas = array_map(fn (array $p): array => [
            'pagina' => (int) $p['pagina'], 'texto' => (string) $p['texto'], 'x' => (float) $p['x'], 'y' => (float) $p['y'],
            'ancho' => (float) $p['ancho'], 'alto' => (float) $p['alto'],
        ], $this->lector->palabras($pdf));
        $pag1 = array_values(array_filter($todas, fn ($p) => $p['pagina'] === 1));
        $buscar = function (string $t) use ($pag1): ?array {
            foreach ($pag1 as $p) {
                if ($p['texto'] === $t) {
                    return $p;
                }
            }

            return null;
        };
        $elaboro = $buscar('Elaboró:');
        $reviso = $buscar('Revisó:');
        $autorizo = $buscar('Autorizó') ?? $buscar('Autorizó:');
        $fechaElab = $buscar('elaboración:');
        $fechaRev = $buscar('revisión:');

        if ($elaboro === null) {
            return [];
        }

        $yFechas = $fechaElab['y'] ?? ($elaboro['y'] + 15);
        $bloque = fn (float $desde, float $hasta) => $this->limpio($this->texto(array_filter($pag1, fn ($p) => $p['y'] >= $elaboro['y'] - 0.5 && $p['y'] < $yFechas - 1 && $p['x'] >= $desde && $p['x'] < $hasta), ['Elaboró:', 'Revisó:', 'Autorizó', 'Autorizó:']));

        return [
            'elaboro' => $bloque($elaboro['x'], ($reviso['x'] ?? 999) - 1),
            'reviso' => $reviso !== null ? $bloque($reviso['x'], ($autorizo['x'] ?? 999) - 1) : null,
            'autorizo' => $autorizo !== null ? $bloque($autorizo['x'], 999) : null,
            'fecha_elaboracion' => $fechaElab !== null ? $this->limpio($this->texto(array_filter($pag1, fn ($p) => abs($p['y'] - $fechaElab['y']) < 1.5 && $p['x'] > $fechaElab['x'] && $p['x'] < (($fechaRev['x'] ?? 999) - 25)))) : null,
            'fecha_revision' => $fechaRev !== null ? $this->limpio($this->texto(array_filter($pag1, fn ($p) => abs($p['y'] - $fechaRev['y']) < 1.5 && $p['x'] > $fechaRev['x']))) : null,
        ];
    }

    // ───────────────────────────────── Utilidades ─────────────────────────────────

    private function quitarEncabezadosYPies(): void
    {
        $pies = [];
        foreach ($this->palabras as $p) {
            if ($p['texto'] === 'Elaboró:') {
                $pies[$p['pagina']] = min($pies[$p['pagina']] ?? 999.0, $p['y']);
            }
        }

        $this->palabras = array_values(array_filter($this->palabras, function (array $p) use ($pies): bool {
            if ($p['y'] < 23 && in_array($p['texto'], ['DESCRIPCIÓN', 'DE', 'PUESTO'], true)) {
                return false;
            }

            return $p['y'] < ($pies[$p['pagina']] ?? 999.0) - 1;
        }));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dePagina(int $pagina): array
    {
        return array_values(array_filter($this->palabras, fn ($p) => $p['pagina'] === $pagina));
    }

    /**
     * Primera aparición de una secuencia de palabras (misma línea).
     *
     * @param  list<string>  $secuencia
     * @return array{pagina: int, x: float, y: float}|null
     */
    private function buscar(array $secuencia, ?int $pagina): ?array
    {
        foreach ($this->buscarTodas($secuencia) as $e) {
            if ($pagina === null || $e['pagina'] === $pagina) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $secuencia
     * @return list<array{pagina: int, x: float, y: float}>
     */
    private function buscarTodas(array $secuencia): array
    {
        $resultado = [];

        foreach ($this->lineasOrdenadas(conPalabras: true) as $l) {
            $ws = $l['palabras'];
            for ($i = 0; $i + count($secuencia) <= count($ws); $i++) {
                $ok = true;
                foreach ($secuencia as $k => $s) {
                    $t = $ws[$i + $k]['texto'];
                    // Etiqueta: primera letra exacta (las del formato van con
                    // mayúscula), el resto sin distinguir mayúsculas.
                    if (mb_substr($t, 0, 1) !== mb_substr($s, 0, 1) || mb_strtolower($t) !== mb_strtolower($s)) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $resultado[] = ['pagina' => $l['pagina'], 'x' => $ws[$i]['x'], 'y' => $ws[$i]['y']];
                }
            }
        }

        return $resultado;
    }

    /**
     * Líneas de todo el documento en orden de lectura simple.
     *
     * @return list<array<string, mixed>>
     */
    private function lineasOrdenadas(bool $conPalabras = false): array
    {
        $porPagina = [];
        foreach ($this->palabras as $p) {
            $porPagina[$p['pagina']][] = $p;
        }
        ksort($porPagina);

        $todas = [];
        foreach ($porPagina as $pagina => $ps) {
            foreach ($this->lineas($ps, $conPalabras) as $l) {
                $todas[] = ['pagina' => $pagina, ...$l];
            }
        }

        return $todas;
    }

    /**
     * Agrupa palabras en líneas (misma altura ±1.2 mm).
     *
     * @param  iterable<array<string, mixed>>  $palabras
     * @return list<array<string, mixed>>
     */
    private function lineas(iterable $palabras, bool $conPalabras = false): array
    {
        $ps = is_array($palabras) ? array_values($palabras) : iterator_to_array($palabras, false);
        usort($ps, fn ($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
        $lineas = [];

        foreach ($ps as $p) {
            $k = null;
            foreach ($lineas as $i => $l) {
                if (abs($l['y'] - $p['y']) < 1.2) {
                    $k = $i;
                    break;
                }
            }
            if ($k === null) {
                $lineas[] = ['y' => $p['y'], 'palabras' => []];
                $k = count($lineas) - 1;
            }
            $lineas[$k]['palabras'][] = $p;
        }

        return array_values(array_map(function ($l) use ($conPalabras) {
            usort($l['palabras'], fn ($a, $b) => $a['x'] <=> $b['x']);
            $salida = ['y' => $l['y'], 'x' => $l['palabras'][0]['x'], 'texto' => implode(' ', array_map(fn ($w) => $w['texto'], $l['palabras']))];

            return $conPalabras ? [...$salida, 'palabras' => $l['palabras']] : $salida;
        }, $lineas));
    }

    /**
     * @param  iterable<array<string, mixed>>  $palabras
     * @return list<array<string, mixed>>
     */
    private function ordenarY(iterable $palabras): array
    {
        $ps = is_array($palabras) ? array_values($palabras) : iterator_to_array($palabras, false);
        usort($ps, fn ($a, $b) => $a['y'] <=> $b['y']);

        return $ps;
    }

    /**
     * @param  iterable<array<string, mixed>>  $palabras
     * @param  list<string>  $quitar  Palabras de etiqueta a omitir.
     */
    private function texto(iterable $palabras, array $quitar = []): string
    {
        $lineas = $this->lineas($palabras);

        return trim(implode(' ', array_map(function ($l) use ($quitar) {
            $t = $l['texto'];
            foreach ($quitar as $q) {
                $t = (string) preg_replace('/(^|\s)'.preg_quote($q, '/').'(?=\s|$)/u', ' ', $t);
            }

            return trim($t);
        }, $lineas)));
    }

    private function limpio(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $t = trim((string) preg_replace('/\s+/u', ' ', $texto));
        $t = trim($t, " \t\n\r\0\x0B:");

        return $t === '' ? null : Str::ucfirst($t);
    }
}
