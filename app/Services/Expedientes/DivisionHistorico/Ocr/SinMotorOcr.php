<?php

namespace App\Services\Expedientes\DivisionHistorico\Ocr;

/**
 * Driver «ninguno»: OCR deshabilitado por configuración. Las páginas sin
 * capa de texto se reportan como escaneadas sin OCR.
 */
class SinMotorOcr implements MotorOcr
{
    public function disponible(): bool
    {
        return false;
    }

    public function nombre(): string
    {
        return 'Sin OCR';
    }

    public function motivoNoDisponible(): ?string
    {
        return 'El OCR está deshabilitado (EXPEDIENTES_OCR_DRIVER=ninguno).';
    }

    public function textoDePagina(string $rutaPdf, int $pagina): ?string
    {
        return null;
    }
}
