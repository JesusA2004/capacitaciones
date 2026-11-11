<?php

namespace App\Services\DocumentosMaestros\Pdf;

use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * Dibuja los datos del colaborador SOBRE el PDF original de Jurídico/RH
 * (overlay): cada página se importa tal cual como objeto vectorial — logo,
 * fondo, tipografía, líneas, cajas; nunca se rasteriza — y encima se
 * escribe cada campo en su caja (coordenadas en mm definidas una sola vez
 * en config/documentos_maestros.php).
 *
 *  - `paginas`: rango del original que forma este documento (un mismo PDF
 *    puede contener contrato + pagaré + carta).
 *  - `copias_offset_y`: formatos con varias copias en la misma hoja (el
 *    permiso MR. LANA trae dos): cada campo se repite desplazado.
 *  - Texto: SIEMPRE se dibuja dentro de su caja — se reduce hasta
 *    `tamano_minimo` (6 pt por defecto), luego se parte en los renglones
 *    que quepan en el alto de la caja y, al final, se condensa a lo ancho.
 *    Nunca texto encimado ni fuera de la caja, y nunca se bloquea la
 *    emisión: si hubo que condensarlo demasiado queda como DESBORDE en el
 *    reporte (prueba de diseño del master y log).
 *  - Casillas (`tipo` => 'check' o campos marca_*): una X vectorial dentro
 *    de un cuadrado centrado en la caja; jamás sale de ella, a cualquier
 *    resolución de impresión.
 *  - Las anotaciones del PDF original (comentarios de revisión) no se
 *    importan: FPDI solo copia el contenido de la página.
 *
 * Determinista: sin OCR ni IA; las coordenadas ya están definidas.
 */
class RenderizadorOverlayMaestro
{
    private const PT_A_MM = 25.4 / 72;

    private const FUENTE_MINIMA = 6.0;

    /** Interlineado (× tamaño de letra) al partir en renglones un campo de un renglón. */
    private const INTERLINEADO_AJUSTE = 1.15;

    /** Debajo de este ancho de letra el dato ya no se lee cómodo: se reporta. */
    private const CONDENSADO_LEGIBLE = 0.6;

    /** Un párrafo nunca se condensa más que esto; lo que sobre se recorta. */
    private const CONDENSADO_MINIMO = 0.3;

    /**
     * @param  list<array<string, mixed>>  $campos
     * @param  array<string, string>  $valores
     * @param  list<int>|null  $paginas
     * @param  list<float>  $copias
     */
    public function renderizar(string $pdfOriginal, array $campos, array $valores, ?array $paginas = null, array $copias = [0.0], ?string $leyenda = null): string
    {
        return $this->renderizarConReporte($pdfOriginal, $campos, $valores, $paginas, $copias, $leyenda)['pdf'];
    }

    /**
     * Igual que renderizar() pero devuelve también los campos que no
     * cupieron en su caja (el motor bloquea la emisión si hay alguno).
     *
     * @param  list<array<string, mixed>>  $campos
     * @param  array<string, string>  $valores
     * @param  list<int>|null  $paginas
     * @param  list<float>  $copias
     * @return array{pdf: string, desbordes: list<array{campo: string, valor: string, razon: string}>, paginas: int}
     */
    public function renderizarConReporte(string $pdfOriginal, array $campos, array $valores, ?array $paginas = null, array $copias = [0.0], ?string $leyenda = null): array
    {
        $temporal = tempnam(sys_get_temp_dir(), 'pdf');

        if ($temporal === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para el PDF.');
        }

        file_put_contents($temporal, $pdfOriginal);

        try {
            $pdf = new FpdiAjustable;
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetCreator('MR. LANA People');
            $total = $pdf->setSourceFile($temporal);
            $paginas = $paginas === null || $paginas === [] ? range(1, $total) : $paginas;
            $porPagina = [];
            $desbordes = [];

            foreach ($campos as $campo) {
                $porPagina[(int) ($campo['pagina'] ?? 1)][] = $campo;
            }

            foreach ($paginas as $numero) {
                if ($numero < 1 || $numero > $total) {
                    throw new RuntimeException("El PDF original no tiene la página {$numero}.");
                }

                $plantilla = $pdf->importPage($numero);
                $tamano = $pdf->getTemplateSize($plantilla);

                if (! is_array($tamano)) {
                    throw new RuntimeException("No se pudo leer el tamaño de la página {$numero}.");
                }

                $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
                $pdf->useTemplate($plantilla);

                if ($leyenda !== null) {
                    $pdf->SetFont('Helvetica', 'B', 7);
                    $pdf->SetTextColor(200, 30, 30);
                    $pdf->SetXY(4, 2);
                    $pdf->Cell((float) $tamano['width'] - 8, 4, $this->latin1($leyenda), 0, 0, 'R');
                }

                // Los campos se definen por número de página del ORIGINAL.
                foreach ($porPagina[$numero] ?? [] as $campo) {
                    $nombre = (string) $campo['campo'];
                    $valor = (string) ($valores[$nombre] ?? '');

                    if (trim($valor) === '') {
                        continue;
                    }

                    foreach ($copias as $desplazamiento) {
                        $razon = $this->dibujar($pdf, [...$campo, 'y' => (float) $campo['y'] + $desplazamiento], $valor);

                        if ($razon !== null) {
                            $desbordes[$nombre] = ['campo' => $nombre, 'valor' => $valor, 'razon' => $razon];
                        }
                    }
                }
            }

            return ['pdf' => (string) $pdf->Output('S'), 'desbordes' => array_values($desbordes), 'paginas' => count($paginas)];
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * Número de páginas y tamaño (mm) del PDF original (para el reporte del
     * importador).
     *
     * @return list<array{numero: int, ancho: float, alto: float}>
     */
    public function paginas(string $pdfOriginal): array
    {
        $temporal = tempnam(sys_get_temp_dir(), 'pdf');

        if ($temporal === false) {
            return [];
        }

        file_put_contents($temporal, $pdfOriginal);

        try {
            $pdf = new Fpdi;
            $total = $pdf->setSourceFile($temporal);
            $paginas = [];

            for ($i = 1; $i <= $total; $i++) {
                $tamano = $pdf->getTemplateSize($pdf->importPage($i));

                if (is_array($tamano)) {
                    $paginas[] = ['numero' => $i, 'ancho' => round((float) $tamano['width'], 1), 'alto' => round((float) $tamano['height'], 1)];
                }
            }

            return $paginas;
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * true si se dibuja como casilla (X vectorial): campo tipo check, o un
     * marca_* cuyo valor es solo la marca. "X  (3 días)" se escribe como
     * texto para no perder los días.
     *
     * @param  array<string, mixed>  $campo
     */
    public static function esCasilla(array $campo, string $valor): bool
    {
        $marca = in_array(trim($valor), ['X', 'x', '☒', '✓', '✔'], true);

        return ($campo['tipo'] ?? null) === 'check' || (str_starts_with((string) ($campo['campo'] ?? ''), 'marca_') && $marca);
    }

    /**
     * Dibuja el campo; devuelve la razón del desborde o null si cupo.
     *
     * @param  array<string, mixed>  $campo
     */
    private function dibujar(FpdiAjustable $pdf, array $campo, string $valor): ?string
    {
        $x = (float) $campo['x'];
        $y = (float) $campo['y'];
        $ancho = max(3.0, (float) ($campo['ancho'] ?? 60));
        $alto = max(3.0, (float) ($campo['alto'] ?? 5));

        if (self::esCasilla($campo, $valor)) {
            $this->casilla($pdf, $x, $y, $ancho, $alto, $valor);

            return null;
        }

        $texto = $this->latin1($valor);
        $alineacion = (string) ($campo['alineacion'] ?? 'L');
        $alineacion = in_array($alineacion, ['L', 'C', 'R'], true) ? $alineacion : 'L';
        $tamano = (float) ($campo['tamano'] ?? 9);
        $minimo = max(4.0, (float) ($campo['tamano_minimo'] ?? self::FUENTE_MINIMA));
        $estilo = ($campo['negrita'] ?? false) === true ? 'B' : '';

        // Dato de ejemplo impreso en el PDF original dentro de la caja (p. ej.
        // "20 semanas" en el plazo): se cubre con el color de fondo de la
        // caja antes de escribir el dato real.
        if (is_string($campo['fondo'] ?? null) && preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $campo['fondo'], $rgb) === 1) {
            $pdf->SetFillColor((int) hexdec($rgb[1]), (int) hexdec($rgb[2]), (int) hexdec($rgb[3]));
            $pdf->Rect($x, $y, $ancho, $alto, 'F');
        }

        $pdf->SetTextColor(15, 15, 15);

        return ($campo['multilinea'] ?? false) === true
            ? $this->parrafo($pdf, $campo, $texto, $x, $y, $ancho, $alto, $tamano, $minimo, $estilo, $alineacion)
            : $this->renglon($pdf, $texto, $x, $y, $ancho, $alto, $tamano, $minimo, $estilo, $alineacion);
    }

    /**
     * Campo de un renglón. El dato SIEMPRE se dibuja dentro de su caja:
     *  1. en un renglón, reduciendo la letra hasta el mínimo del campo;
     *  2. si no cabe, en varios renglones (los que quepan en el alto de la
     *     caja) con la letra más grande posible;
     *  3. si tampoco, en un renglón con la letra condensada a lo ancho.
     * Solo se reporta como desborde cuando hubo que condensar tanto que ya
     * no es cómodo de leer (la emisión no se bloquea: queda en el reporte).
     */
    private function renglon(FpdiAjustable $pdf, string $texto, float $x, float $y, float $ancho, float $alto, float $tamano, float $minimo, string $estilo, string $alineacion): ?string
    {
        $original = $tamano;
        $pdf->SetFont('Helvetica', $estilo, $tamano);

        while ($pdf->GetStringWidth($texto) > $ancho && $tamano - 0.5 >= $minimo) {
            $tamano -= 0.5;
            $pdf->SetFont('Helvetica', $estilo, $tamano);
        }

        if ($pdf->GetStringWidth($texto) <= $ancho) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($ancho, $alto, $texto, 0, 0, $alineacion);

            return null;
        }

        for ($prueba = $original; $prueba >= $minimo; $prueba -= 0.5) {
            $pdf->SetFont('Helvetica', $estilo, $prueba);
            $interlineado = $prueba * self::PT_A_MM * self::INTERLINEADO_AJUSTE;
            $lineas = $this->contarLineas($pdf, $texto, $ancho);

            if ($lineas * $interlineado <= $alto + 0.3 && ! $this->hayPalabraMasAncha($pdf, $texto, $ancho - 2)) {
                $pdf->SetXY($x, $y + ($alto - $lineas * $interlineado) / 2);
                $pdf->MultiCell($ancho, $interlineado, $texto, 0, $alineacion);

                return null;
            }
        }

        $pdf->SetFont('Helvetica', $estilo, $minimo);
        $medida = $pdf->GetStringWidth($texto);
        $escala = max(0.05, ($ancho - 1.0) / $medida);
        $pdf->escalaHorizontal($escala * 100);
        $pdf->SetXY($x, $y);
        $pdf->Cell($ancho, $alto, $texto, 0, 0, 'L');
        $pdf->escalaHorizontal(100);

        return $escala < self::CONDENSADO_LEGIBLE
            ? sprintf('Mide %.0f mm y la caja %.0f mm (aun a %.1f pt): se imprimió muy condensado.', $medida, $ancho, $minimo)
            : null;
    }

    /**
     * Campo de varios renglones. Igual que el renglón: siempre se dibuja
     * dentro de su caja — letra más chica hasta el mínimo y, si aun así no
     * cabe, condensada a lo ancho. Lo que ni así cabe se recorta al borde de
     * la caja (nunca encima del formato) y se reporta.
     *
     * @param  array<string, mixed>  $campo
     */
    private function parrafo(FpdiAjustable $pdf, array $campo, string $texto, float $x, float $y, float $ancho, float $alto, float $tamano, float $minimo, string $estilo, string $alineacion): ?string
    {
        $proporcion = isset($campo['interlineado']) ? (float) $campo['interlineado'] / ($tamano * self::PT_A_MM) : 1.25;

        while (true) {
            $pdf->SetFont('Helvetica', $estilo, $tamano);
            $interlineado = $tamano * self::PT_A_MM * $proporcion;
            $cabe = $this->contarLineas($pdf, $texto, $ancho) * $interlineado <= $alto + $interlineado * 0.35;

            if ($cabe || $tamano - 0.5 < $minimo) {
                break;
            }

            $tamano -= 0.5;
        }

        if ($cabe) {
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($ancho, $interlineado, $texto, 0, $alineacion);

            return null;
        }

        $escala = 1.0;

        do {
            $escala -= 0.05;
            // Con la letra al N % de ancho caben 1/N más caracteres por renglón.
            $anchoVirtual = ($ancho - 2) / $escala + 2;
            $cabe = $this->contarLineas($pdf, $texto, $anchoVirtual) * $interlineado <= $alto + $interlineado * 0.35;
        } while (! $cabe && $escala - 0.05 >= self::CONDENSADO_MINIMO);

        $renglones = max(1, (int) floor(($alto + $interlineado * 0.35) / $interlineado));

        // El recorte termina en renglón completo: nunca media letra cortada.
        $pdf->recortar($x, $y - 0.5, $ancho, $renglones * $interlineado + 0.5);
        $pdf->escalaHorizontal($escala * 100);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($anchoVirtual, $interlineado, $texto, 0, 'L');
        $pdf->escalaHorizontal(100);
        $pdf->finRecorte();

        if ($cabe && $escala >= self::CONDENSADO_LEGIBLE) {
            return null;
        }

        return $cabe
            ? sprintf('No cabe en %d renglón(es) a %.1f pt: se imprimió muy condensado.', $renglones, $minimo)
            : sprintf('No cabe en %d renglón(es) ni a %.1f pt: se imprimió hasta donde alcanza la caja.', $renglones, $minimo);
    }

    private function hayPalabraMasAncha(FpdiAjustable $pdf, string $texto, float $ancho): bool
    {
        foreach (preg_split('/\s+/', $texto) ?: [] as $palabra) {
            if ($pdf->GetStringWidth($palabra) > $ancho) {
                return true;
            }
        }

        return false;
    }

    /**
     * X vectorial centrada en la caja: el lado es el 70 % del lado menor
     * (máx. 4 mm), así nunca toca ni rebasa el borde del recuadro.
     */
    private function casilla(FpdiAjustable $pdf, float $x, float $y, float $ancho, float $alto, string $valor): void
    {
        $valor = trim($valor);

        if ($valor === '' || $valor === '☐') {
            return;
        }

        $lado = min(4.0, min($ancho, $alto) * 0.7);
        $cx = $x + $ancho / 2;
        $cy = $y + $alto / 2;
        $pdf->SetDrawColor(15, 15, 15);
        $pdf->SetLineWidth(max(0.3, $lado * 0.12));
        $pdf->Line($cx - $lado / 2, $cy - $lado / 2, $cx + $lado / 2, $cy + $lado / 2);
        $pdf->Line($cx - $lado / 2, $cy + $lado / 2, $cx + $lado / 2, $cy - $lado / 2);
    }

    private function contarLineas(FpdiAjustable $pdf, string $texto, float $ancho): int
    {
        $lineas = 0;

        foreach (explode("\n", $texto) as $parrafo) {
            $actual = '';
            $lineas++;

            foreach (explode(' ', $parrafo) as $palabra) {
                $prueba = $actual === '' ? $palabra : $actual.' '.$palabra;

                // Mismo margen interno que MultiCell (cMargin = 1 mm por lado).
                if ($pdf->GetStringWidth($prueba) > $ancho - 2 && $actual !== '') {
                    $lineas++;
                    $actual = $palabra;
                } else {
                    $actual = $prueba;
                }
            }
        }

        return $lineas;
    }

    /**
     * Las fuentes core de FPDF esperan Windows-1252: sin esto los acentos y
     * la ñ salen corruptos. ☒/☐ no existen en Latin-1: se usan "X" y "".
     */
    private function latin1(string $texto): string
    {
        $texto = str_replace(['☒', '☐', '✓', '✔'], ['X', '', 'X', 'X'], $texto);
        $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);

        return $convertido !== false ? $convertido : $texto;
    }
}
