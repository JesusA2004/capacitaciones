<?php

namespace App\Services\Expedientes\DivisionHistorico;

use App\Services\DocumentosMaestros\Pdf\NormalizadorPdf;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Copia páginas de un PDF a un PDF nuevo con FPDI (setasign/fpdi-fpdf), sin
 * tocar el original. Si el PDF usa «cross-reference streams» (PDF 1.5+), que
 * el parser libre de FPDI no lee, primero se reescribe en memoria con
 * NormalizadorPdf (mismo contenido, tabla xref clásica).
 */
class CortadorPdf
{
    public function __construct(private readonly NormalizadorPdf $normalizador) {}

    /**
     * Bytes listos para FPDI (normalizados si hace falta).
     *
     * @throws RuntimeException Si el PDF está cifrado o no se puede leer.
     */
    public function preparar(string $pdf): string
    {
        try {
            return $this->normalizador->necesitaNormalizar($pdf) ? $this->normalizador->normalizar($pdf) : $pdf;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo preparar el PDF para dividirlo: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws RuntimeException Si FPDI no puede leer el PDF.
     */
    public function contarPaginas(string $pdfPreparado): int
    {
        try {
            return (new Fpdi)->setSourceFile(StreamReader::createByString($pdfPreparado));
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo leer el PDF para dividirlo: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * PDF nuevo solo con $paginas (1-based, en el orden dado), cada una con
     * su tamaño y orientación originales.
     *
     * @param  list<int>  $paginas
     *
     * @throws RuntimeException Si alguna página no se pudo copiar.
     */
    public function extraer(string $pdfPreparado, array $paginas): string
    {
        try {
            $pdf = new Fpdi;
            $pdf->SetAutoPageBreak(false);
            $pdf->setSourceFile(StreamReader::createByString($pdfPreparado));

            foreach ($paginas as $numero) {
                $plantilla = $pdf->importPage($numero);
                $tamano = $pdf->getTemplateSize($plantilla);

                if (! is_array($tamano)) {
                    throw new RuntimeException(sprintf('No se pudo leer el tamaño de la página %d.', $numero));
                }

                $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
                $pdf->useTemplate($plantilla);
            }

            return $pdf->Output('S');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudieron copiar las páginas del PDF: '.$e->getMessage(), 0, $e);
        }
    }
}
