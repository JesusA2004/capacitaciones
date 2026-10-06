<?php

namespace App\Services\Pdf;

use App\Enums\MotorPdf;

/**
 * Convierte un HTML completo (con su CSS de impresión: @page, thead,
 * break-inside…) en los bytes de un PDF.
 */
interface PdfRendererInterface
{
    public function motor(): MotorPdf;

    /**
     * @throws PdfRendererException si el motor no está disponible o falla.
     */
    public function renderizar(string $html, OpcionesPdf $opciones): string;
}
