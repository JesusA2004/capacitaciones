<?php

namespace App\Services\Nomina;

use App\Models\ReciboNomina;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Une en un solo PDF los PDFs CONGELADOS de varios recibos (los que
 * ReciboNominaService::generarPdf guardó al emitir, con el formato oficial
 * vigente), página por página y sin regenerar nada — cada recibo conserva
 * su formato individual. FPDI (setasign/fpdi-fpdf), ya usado por el motor
 * de formatos.
 *
 * Dos modos:
 *  - tolerante (centro de descarga quincenal, NominaQuincenalService): un
 *    recibo sin PDF o ilegible se omite y se registra en el log;
 *  - estricto (impresión del lote, LoteNominaService::pdfImpresion): un
 *    recibo que no se pudo unir aborta todo — nunca se entrega un paquete
 *    de impresión incompleto sin avisar.
 */
class CombinadorPdfRecibos
{
    /**
     * @param  Collection<int, ReciboNomina>  $recibos  ya en el orden de impresión
     *
     * @throws RuntimeException en modo estricto, si algún recibo no se pudo unir
     */
    public function combinar(Collection $recibos, bool $estricto = false): string
    {
        $pdf = new Fpdi;

        foreach ($recibos as $recibo) {
            $contenido = $this->contenido($recibo);

            if ($contenido === null) {
                if ($estricto) {
                    throw new RuntimeException(sprintf('El recibo %s no tiene PDF generado.', $recibo->folio ?? $recibo->id));
                }

                continue;
            }

            try {
                $paginas = $pdf->setSourceFile(StreamReader::createByString($contenido));

                for ($i = 1; $i <= $paginas; $i++) {
                    $plantilla = $pdf->importPage($i);
                    $tamano = $pdf->getTemplateSize($plantilla);

                    if (! is_array($tamano)) {
                        continue;
                    }

                    $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
                    $pdf->useTemplate($plantilla);
                }
            } catch (Throwable $e) {
                Log::warning('CombinadorPdfRecibos: no se pudo unir el PDF de un recibo.', ['recibo_id' => $recibo->id, 'error' => $e->getMessage()]);

                if ($estricto) {
                    throw new RuntimeException(sprintf('No se pudo unir el PDF del recibo %s.', $recibo->folio ?? $recibo->id), 0, $e);
                }
            }
        }

        return $pdf->Output('S');
    }

    /**
     * Si el PDF congelado del recibo existe en su disco.
     */
    public function tienePdf(ReciboNomina $recibo): bool
    {
        return $recibo->pdf_disk !== null
            && $recibo->pdf_path !== null
            && Storage::disk($recibo->pdf_disk)->exists($recibo->pdf_path);
    }

    /**
     * Bytes del PDF congelado del recibo, o null si no está.
     */
    public function contenido(ReciboNomina $recibo): ?string
    {
        if ($recibo->pdf_disk === null || $recibo->pdf_path === null) {
            return null;
        }

        $contenido = Storage::disk($recibo->pdf_disk)->get($recibo->pdf_path);

        if ($contenido === null) {
            Log::warning('CombinadorPdfRecibos: el PDF del recibo no está en el almacenamiento.', ['recibo_id' => $recibo->id]);
        }

        return $contenido;
    }
}
