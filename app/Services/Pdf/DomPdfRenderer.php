<?php

namespace App\Services\Pdf;

use App\Enums\MotorPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Throwable;

/**
 * DomPDF: sin dependencias del servidor, CSS limitado (sin flex/grid, sin
 * object-fit). La numeración de páginas se dibuja en el lienzo al final.
 */
class DomPdfRenderer implements PdfRendererInterface
{
    public function motor(): MotorPdf
    {
        return MotorPdf::DomPdf;
    }

    public function renderizar(string $html, OpcionesPdf $opciones): string
    {
        try {
            $pdf = Pdf::loadHTML($html)->setPaper($opciones->tamano === 'a4' ? 'a4' : 'letter', $opciones->orientacion);

            if (! $opciones->numerarPaginas) {
                return $pdf->output();
            }

            $dompdf = $pdf->getDomPDF();
            $dompdf->render();
            $lienzo = $dompdf->getCanvas();
            $fuente = $dompdf->getFontMetrics()->getFont('helvetica');
            [$r, $g, $b] = sscanf(ltrim($opciones->colorPie, '#'), '%02x%02x%02x') ?? [107, 114, 128];
            $puntosMm = 72 / 25.4;
            $lienzo->page_text(
                $lienzo->get_width() / 2 - 30,
                $lienzo->get_height() - ($opciones->distanciaPieMm * $puntosMm),
                'Página {PAGE_NUM} de {PAGE_COUNT}',
                $fuente,
                8,
                [(int) $r / 255, (int) $g / 255, (int) $b / 255],
            );

            return (string) $dompdf->output();
        } catch (Throwable $e) {
            throw new PdfRendererException('DomPDF no pudo generar el documento: '.$e->getMessage(), 0, $e);
        }
    }
}
