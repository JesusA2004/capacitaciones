<?php

namespace App\Services\Formatos\Motor;

use App\Services\Formatos\FormatoPreviewService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Word → PDF. Con LibreOffice configurado (config('formatos_oficiales.libreoffice'))
 * la conversión respeta el documento; si no, se usa el convertidor PhpWord +
 * DomPDF que ya existe (FormatoPreviewService) y el resultado se marca como
 * fidelidad "aproximada" para que RH lo sepa antes de publicar.
 */
class ConversorDocxPdf
{
    public function __construct(private readonly FormatoPreviewService $phpWord) {}

    public function fiel(): bool
    {
        $ruta = config('formatos_oficiales.libreoffice');

        return is_string($ruta) && $ruta !== '';
    }

    /**
     * @return array{pdf: string, fidelidad: 'exacta'|'aproximada'}|null
     */
    public function convertir(string $contenidoDocx): ?array
    {
        if ($this->fiel()) {
            $pdf = $this->conLibreOffice($contenidoDocx);

            if ($pdf !== null) {
                return ['pdf' => $pdf, 'fidelidad' => 'exacta'];
            }
        }

        $pdf = $this->phpWord->aPdf($contenidoDocx);

        return $pdf !== null && $pdf !== '' ? ['pdf' => $pdf, 'fidelidad' => 'aproximada'] : null;
    }

    private function conLibreOffice(string $contenidoDocx): ?string
    {
        $carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'formato-'.Str::uuid();
        @mkdir($carpeta);
        $origen = $carpeta.DIRECTORY_SEPARATOR.'documento.docx';
        file_put_contents($origen, $contenidoDocx);

        try {
            $resultado = Process::timeout(120)->run([
                (string) config('formatos_oficiales.libreoffice'),
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $carpeta,
                $origen,
            ]);
            $salida = $carpeta.DIRECTORY_SEPARATOR.'documento.pdf';

            return $resultado->successful() && is_file($salida) ? (string) file_get_contents($salida) : null;
        } catch (Throwable) {
            return null;
        } finally {
            foreach (glob($carpeta.DIRECTORY_SEPARATOR.'*') ?: [] as $archivo) {
                @unlink($archivo);
            }

            @rmdir($carpeta);
        }
    }
}
