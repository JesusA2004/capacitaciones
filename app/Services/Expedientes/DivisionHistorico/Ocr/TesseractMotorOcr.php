<?php

namespace App\Services\Expedientes\DivisionHistorico\Ocr;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * OCR local con Tesseract: rasteriza UNA página con pdftoppm (poppler-utils)
 * y la reconoce en español. Binarios configurables
 * (EXPEDIENTES_TESSERACT_PATH / EXPEDIENTES_PDFTOPPM_PATH); en el VPS:
 * `apt install tesseract-ocr tesseract-ocr-spa poppler-utils`.
 *
 * Archivo temporal único por página, borrado en finally; timeout por
 * página; nunca escribe en el log el texto reconocido (datos personales).
 */
class TesseractMotorOcr implements MotorOcr
{
    private ?bool $disponible = null;

    private ?string $version = null;

    private ?string $motivo = null;

    public function disponible(): bool
    {
        if ($this->disponible !== null) {
            return $this->disponible;
        }

        $tesseract = $this->ejecutar([$this->tesseract(), '--version']);

        if ($tesseract === null) {
            $this->motivo = 'Tesseract no está instalado en el servidor (apt install tesseract-ocr tesseract-ocr-spa).';

            return $this->disponible = false;
        }

        if ($this->ejecutar([$this->pdftoppm(), '-v']) === null) {
            $this->motivo = 'Falta pdftoppm para convertir la página escaneada en imagen (apt install poppler-utils).';

            return $this->disponible = false;
        }

        $linea = strtok($tesseract, "\n");
        $this->version = $linea !== false ? trim($linea) : 'tesseract';

        return $this->disponible = true;
    }

    public function nombre(): string
    {
        return $this->disponible()
            ? sprintf('%s (%s)', $this->version ?? 'tesseract', $this->idioma())
            : 'Tesseract (no disponible)';
    }

    public function motivoNoDisponible(): ?string
    {
        return $this->disponible() ? null : $this->motivo;
    }

    public function textoDePagina(string $rutaPdf, int $pagina): ?string
    {
        if (! $this->disponible()) {
            return null;
        }

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr-hist-'.Str::uuid();
        $png = $base.'.png';
        $timeout = max(5, (int) config('expedientes.division_historico.ocr.timeout_por_pagina', 60));

        try {
            $raster = Process::timeout($timeout)->run([
                $this->pdftoppm(), '-r', (string) max(72, (int) config('expedientes.division_historico.ocr.dpi', 200)),
                '-png', '-gray', '-f', (string) $pagina, '-l', (string) $pagina, '-singlefile', $rutaPdf, $base,
            ]);

            if (! $raster->successful() || ! is_file($png)) {
                Log::warning('division_historico: pdftoppm no pudo rasterizar la página.', ['pagina' => $pagina, 'codigo' => $raster->exitCode()]);

                return null;
            }

            $ocr = Process::timeout($timeout)->run([$this->tesseract(), $png, 'stdout', '-l', $this->idioma()]);

            if (! $ocr->successful()) {
                Log::warning('division_historico: Tesseract falló en la página.', ['pagina' => $pagina, 'codigo' => $ocr->exitCode()]);

                return null;
            }

            $texto = trim($ocr->output());

            return $texto !== '' ? $texto : null;
        } catch (Throwable $e) {
            Log::warning('division_historico: no se pudo ejecutar el OCR.', ['pagina' => $pagina, 'error' => class_basename($e)]);

            return null;
        } finally {
            if (is_file($png)) {
                @unlink($png);
            }
        }
    }

    /**
     * @param  list<string>  $comando
     */
    private function ejecutar(array $comando): ?string
    {
        try {
            $resultado = Process::timeout(15)->run($comando);

            return $resultado->successful() ? $resultado->output().$resultado->errorOutput() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function tesseract(): string
    {
        return (string) config('expedientes.division_historico.ocr.tesseract', 'tesseract');
    }

    private function pdftoppm(): string
    {
        return (string) config('expedientes.division_historico.ocr.pdftoppm', 'pdftoppm');
    }

    private function idioma(): string
    {
        return (string) config('expedientes.division_historico.ocr.idioma', 'spa');
    }
}
