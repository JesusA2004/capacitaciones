<?php

namespace App\Services\Formatos\Motor;

use App\Services\Formatos\FormatoPreviewService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Word → PDF conservando el diseño del documento original.
 *
 * Conversores, en orden (config('formatos_oficiales.conversor') = auto):
 *  1. LibreOffice headless (FORMATOS_LIBREOFFICE_PATH) — servidores Linux.
 *  2. Microsoft Word por automatización COM (resources/scripts/docx-a-pdf-word.ps1)
 *     — servidores/equipos Windows con Office.
 *  3. PhpWord + DomPDF (FormatoPreviewService) — último recurso; NO respeta
 *     el diseño fielmente y el resultado se marca fidelidad "aproximada".
 *
 * Ninguno usa servicios externos ni IA: la conversión es local y
 * determinista.
 */
class ConversorDocxPdf
{
    public function __construct(private readonly FormatoPreviewService $phpWord) {}

    /**
     * true si hay un conversor fiel disponible (LibreOffice o Word).
     */
    public function fiel(): bool
    {
        return $this->conversorFiel() !== null;
    }

    /**
     * @return array{pdf: string, fidelidad: 'exacta'|'aproximada', conversor: string}|null
     */
    public function convertir(string $contenidoDocx): ?array
    {
        $preferido = (string) config('formatos_oficiales.conversor', 'auto');

        foreach ($this->orden($preferido) as $conversor) {
            $pdf = match ($conversor) {
                'libreoffice' => $this->conLibreOffice($contenidoDocx),
                'word' => $this->conWord($contenidoDocx),
                default => null,
            };

            if ($pdf !== null && $pdf !== '') {
                return ['pdf' => $pdf, 'fidelidad' => 'exacta', 'conversor' => $conversor];
            }
        }

        $pdf = $this->phpWord->aPdf($contenidoDocx);

        return $pdf !== null && $pdf !== '' ? ['pdf' => $pdf, 'fidelidad' => 'aproximada', 'conversor' => 'phpword'] : null;
    }

    /**
     * @return list<string>
     */
    private function orden(string $preferido): array
    {
        return match ($preferido) {
            // Solo si de verdad está disponible: si no, cae a la salida aproximada.
            'libreoffice' => $this->libreOfficeConfigurado() ? ['libreoffice'] : [],
            'word' => PHP_OS_FAMILY === 'Windows' ? ['word'] : [],
            'phpword' => [],
            default => array_values(array_filter([
                $this->libreOfficeConfigurado() ? 'libreoffice' : null,
                PHP_OS_FAMILY === 'Windows' ? 'word' : null,
            ])),
        };
    }

    private function conversorFiel(): ?string
    {
        return $this->orden((string) config('formatos_oficiales.conversor', 'auto'))[0] ?? null;
    }

    private function libreOfficeConfigurado(): bool
    {
        $ruta = config('formatos_oficiales.libreoffice');

        return is_string($ruta) && $ruta !== '';
    }

    private function conLibreOffice(string $contenidoDocx): ?string
    {
        if (! $this->libreOfficeConfigurado()) {
            return null;
        }

        return $this->enCarpetaTemporal($contenidoDocx, function (string $carpeta, string $origen): ?string {
            $resultado = Process::timeout((int) config('formatos_oficiales.timeout_segundos', 180))->run([
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
        });
    }

    private function conWord(string $contenidoDocx): ?string
    {
        $script = (string) config('formatos_oficiales.word_script');

        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($script)) {
            return null;
        }

        return $this->enCarpetaTemporal($contenidoDocx, function (string $carpeta, string $origen) use ($script): ?string {
            $salida = $carpeta.DIRECTORY_SEPARATOR.'documento.pdf';
            $resultado = Process::timeout((int) config('formatos_oficiales.timeout_segundos', 180))->run([
                'powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script, '-Origen', $origen, '-Destino', $salida,
            ]);

            if (! $resultado->successful() || ! is_file($salida)) {
                Log::warning('ConversorDocxPdf: Word no pudo convertir el documento.', ['salida' => mb_substr($resultado->errorOutput(), 0, 500)]);

                return null;
            }

            return (string) file_get_contents($salida);
        });
    }

    /**
     * @param  callable(string, string): ?string  $convertir
     */
    private function enCarpetaTemporal(string $contenidoDocx, callable $convertir): ?string
    {
        $carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'formato-'.Str::uuid();
        @mkdir($carpeta);
        $origen = $carpeta.DIRECTORY_SEPARATOR.'documento.docx';
        file_put_contents($origen, $contenidoDocx);

        try {
            return $convertir($carpeta, $origen);
        } catch (Throwable $e) {
            Log::warning('ConversorDocxPdf: error al convertir.', ['error' => $e->getMessage()]);

            return null;
        } finally {
            foreach (glob($carpeta.DIRECTORY_SEPARATOR.'*') ?: [] as $archivo) {
                @unlink($archivo);
            }

            @rmdir($carpeta);
        }
    }
}
