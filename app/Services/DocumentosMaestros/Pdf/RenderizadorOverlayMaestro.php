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
 *  - Texto: se reduce hasta `tamano_minimo` (6 pt por defecto) antes de
 *    salirse de su caja; `multilinea` hace wrap dentro del alto de la caja.
 *    Si aun así no cabe, es un DESBORDE: se reporta (vista previa) o se
 *    bloquea la emisión (documento definitivo) — nunca texto encimado.
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
            $pdf = new Fpdi;
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
    private function dibujar(Fpdi $pdf, array $campo, string $valor): ?string
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

        if (($campo['multilinea'] ?? false) === true) {
            $proporcion = isset($campo['interlineado']) ? (float) $campo['interlineado'] / ($tamano * self::PT_A_MM) : 1.25;
            $cabe = false;

            while (true) {
                $pdf->SetFont('Helvetica', $estilo, $tamano);
                $interlineado = $tamano * self::PT_A_MM * $proporcion;
                $cabe = $this->contarLineas($pdf, $texto, $ancho) * $interlineado <= $alto + $interlineado * 0.35;

                if ($cabe || $tamano - 0.5 < $minimo) {
                    break;
                }

                $tamano -= 0.5;
            }

            if (! $cabe) {
                return sprintf('No cabe en %d renglón(es) ni a %.1f pt.', max(1, (int) floor(($alto + $interlineado * 0.35) / $interlineado)), $minimo);
            }

            $pdf->SetXY($x, $y);
            $pdf->MultiCell($ancho, $interlineado, $texto, 0, $alineacion);

            return null;
        }

        $pdf->SetFont('Helvetica', $estilo, $tamano);

        while ($pdf->GetStringWidth($texto) > $ancho && $tamano - 0.5 >= $minimo) {
            $tamano -= 0.5;
            $pdf->SetFont('Helvetica', $estilo, $tamano);
        }

        if ($pdf->GetStringWidth($texto) > $ancho) {
            return sprintf('Mide %.0f mm y la caja %.0f mm (aun a %.1f pt).', $pdf->GetStringWidth($texto), $ancho, $minimo);
        }

        $pdf->SetXY($x, $y);
        $pdf->Cell($ancho, $alto, $texto, 0, 0, $alineacion);

        return null;
    }

    /**
     * X vectorial centrada en la caja: el lado es el 70 % del lado menor
     * (máx. 4 mm), así nunca toca ni rebasa el borde del recuadro.
     */
    private function casilla(Fpdi $pdf, float $x, float $y, float $ancho, float $alto, string $valor): void
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

    private function contarLineas(Fpdi $pdf, string $texto, float $ancho): int
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
