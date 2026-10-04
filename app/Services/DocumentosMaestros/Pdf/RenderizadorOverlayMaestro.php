<?php

namespace App\Services\DocumentosMaestros\Pdf;

use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * Dibuja los datos del colaborador SOBRE el PDF original de Jurídico/RH
 * (overlay): cada página se importa tal cual — logo, fondo, tipografía,
 * líneas, cajas — y encima se escribe cada campo en su caja (coordenadas en
 * mm definidas una sola vez en config/documentos_maestros.php).
 *
 *  - `paginas`: rango del original que forma este documento (un mismo PDF
 *    puede contener contrato + pagaré + carta).
 *  - `copias_offset_y`: formatos con varias copias en la misma hoja (el
 *    permiso MR. LANA trae dos): cada campo se repite desplazado.
 *  - El texto que no cabe se reduce (hasta 6 pt) antes que salirse de su
 *    caja. Las anotaciones del PDF original (comentarios de revisión) no se
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
                    $valor = (string) ($valores[(string) $campo['campo']] ?? '');

                    if (trim($valor) === '') {
                        continue;
                    }

                    foreach ($copias as $desplazamiento) {
                        $this->texto($pdf, [...$campo, 'y' => (float) $campo['y'] + $desplazamiento], $valor);
                    }
                }
            }

            return (string) $pdf->Output('S');
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
     * @param  array<string, mixed>  $campo
     */
    private function texto(Fpdi $pdf, array $campo, string $valor): void
    {
        $texto = $this->latin1($valor);
        $x = (float) $campo['x'];
        $y = (float) $campo['y'];
        $ancho = max(3.0, (float) ($campo['ancho'] ?? 60));
        $alto = max(3.0, (float) ($campo['alto'] ?? 5));
        $alineacion = (string) ($campo['alineacion'] ?? 'L');
        $alineacion = in_array($alineacion, ['L', 'C', 'R'], true) ? $alineacion : 'L';
        $tamano = (float) ($campo['tamano'] ?? 9);
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
            $interlineado = (float) ($campo['interlineado'] ?? $tamano * self::PT_A_MM * 1.25);

            do {
                $pdf->SetFont('Helvetica', $estilo, $tamano);
                $cabe = $this->contarLineas($pdf, $texto, $ancho) * $interlineado <= $alto + $interlineado * 0.5;
                $tamano -= 0.5;
            } while (! $cabe && $tamano >= self::FUENTE_MINIMA);

            $pdf->SetXY($x, $y);
            $pdf->MultiCell($ancho, $interlineado, $texto, 0, $alineacion);

            return;
        }

        $pdf->SetFont('Helvetica', $estilo, $tamano);

        while ($pdf->GetStringWidth($texto) > $ancho && $tamano > self::FUENTE_MINIMA) {
            $tamano -= 0.5;
            $pdf->SetFont('Helvetica', $estilo, $tamano);
        }

        $pdf->SetXY($x, $y);
        $pdf->Cell($ancho, $alto, $texto, 0, 0, $alineacion);
    }

    private function contarLineas(Fpdi $pdf, string $texto, float $ancho): int
    {
        $lineas = 0;

        foreach (explode("\n", $texto) as $parrafo) {
            $actual = '';
            $lineas++;

            foreach (explode(' ', $parrafo) as $palabra) {
                $prueba = $actual === '' ? $palabra : $actual.' '.$palabra;

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
        $texto = str_replace(['☒', '☐'], ['X', ''], $texto);
        $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);

        return $convertido !== false ? $convertido : $texto;
    }
}
