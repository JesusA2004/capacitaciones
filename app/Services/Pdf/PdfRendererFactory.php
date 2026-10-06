<?php

namespace App\Services\Pdf;

use App\Enums\MotorPdf;
use Illuminate\Support\Facades\Log;

/**
 * Elige el motor de una versión de plantilla y, si Chrome falla, cae a
 * DomPDF SOLO cuando config('pdf.fallback_dompdf') lo permite. Devuelve
 * también qué motor imprimió realmente (va al snapshot del documento).
 */
class PdfRendererFactory
{
    public function __construct(
        private readonly BrowsershotRenderer $browsershot,
        private readonly DomPdfRenderer $dompdf,
    ) {}

    public function para(MotorPdf $motor): PdfRendererInterface
    {
        return match ($motor) {
            MotorPdf::Browsershot => $this->browsershot,
            MotorPdf::DomPdf => $this->dompdf,
        };
    }

    /**
     * Igual que renderizar(), pero el HTML y las opciones se arman para el
     * motor que finalmente imprime (si Chrome cae a DomPDF, se rearman).
     *
     * @param  callable(MotorPdf): string  $html
     * @param  callable(MotorPdf): OpcionesPdf  $opciones
     * @return array{pdf: string, motor: MotorPdf, respaldo: bool}
     *
     * @throws PdfRendererException
     */
    public function renderizarCon(MotorPdf $motor, callable $html, callable $opciones): array
    {
        try {
            return ['pdf' => $this->para($motor)->renderizar($html($motor), $opciones($motor)), 'motor' => $motor, 'respaldo' => false];
        } catch (PdfRendererException $e) {
            if ($motor !== MotorPdf::Browsershot || ! (bool) config('pdf.fallback_dompdf', false)) {
                throw $e;
            }

            Log::warning('PDF: Chrome falló; se usa DomPDF por PDF_FALLBACK_DOMPDF=true.', ['error' => $e->getMessage()]);

            return ['pdf' => $this->dompdf->renderizar($html(MotorPdf::DomPdf), $opciones(MotorPdf::DomPdf)), 'motor' => MotorPdf::DomPdf, 'respaldo' => true];
        }
    }

    /**
     * @return array{pdf: string, motor: MotorPdf, respaldo: bool}
     *
     * @throws PdfRendererException
     */
    public function renderizar(MotorPdf $motor, string $html, OpcionesPdf $opciones): array
    {
        try {
            return ['pdf' => $this->para($motor)->renderizar($html, $opciones), 'motor' => $motor, 'respaldo' => false];
        } catch (PdfRendererException $e) {
            if ($motor !== MotorPdf::Browsershot || ! (bool) config('pdf.fallback_dompdf', false)) {
                throw $e;
            }

            Log::warning('PDF: Chrome falló; se usa DomPDF por PDF_FALLBACK_DOMPDF=true.', ['error' => $e->getMessage()]);

            return ['pdf' => $this->dompdf->renderizar($html, $opciones), 'motor' => MotorPdf::DomPdf, 'respaldo' => true];
        }
    }
}
