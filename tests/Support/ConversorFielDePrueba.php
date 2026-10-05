<?php

namespace Tests\Support;

use App\Services\Formatos\Motor\ConversorDocxPdf;
use setasign\Fpdi\Fpdi;

/**
 * Conversor "fiel" de PRUEBA: la suite nunca abre Microsoft Word ni
 * LibreOffice (phpunit.xml: FORMATOS_CONVERSOR=phpword). Las pruebas que
 * necesitan emitir un documento definitivo lo registran explícitamente con
 * dmMotorFielDePrueba(). Produce un PDF mínimo y determinista del número de
 * páginas indicado; se identifica como 'word' (nativa) para ejercitar la
 * ruta definitiva del motor. La fidelidad REAL se prueba aparte, en el
 * grupo "fidelidad" (tests/Feature/DocumentosMaestros/FidelidadRealTest.php).
 */
class ConversorFielDePrueba extends ConversorDocxPdf
{
    public int $paginas = 1;

    public int $conversiones = 0;

    /**
     * @return list<'word'|'libreoffice'>
     */
    public function conversoresFieles(): array
    {
        return ['word'];
    }

    /**
     * @param  list<string>|null  $soloCon
     * @return array{pdf: string, fidelidad: string, conversor: string}|null
     */
    public function convertirFiel(string $contenidoDocx, ?array $soloCon = null): ?array
    {
        if ($soloCon !== null && ! in_array('word', $soloCon, true)) {
            return null;
        }

        $this->conversiones++;
        $pdf = new Fpdi;
        $pdf->SetCreator('prueba');

        for ($i = 1; $i <= max(1, $this->paginas); $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(0, 10, sprintf('Documento de prueba %s · página %d', substr(hash('sha256', $contenidoDocx), 0, 8), $i));
        }

        return ['pdf' => (string) $pdf->Output('S'), 'fidelidad' => 'nativa', 'conversor' => 'word'];
    }

    public function wordDisponible(): bool
    {
        return true;
    }
}
