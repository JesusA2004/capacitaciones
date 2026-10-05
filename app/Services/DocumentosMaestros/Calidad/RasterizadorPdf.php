<?php

namespace App\Services\DocumentosMaestros\Calidad;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Convierte cada página de un PDF en una imagen PNG (escala de grises
 * después, en ComparadorVisual) para el QA visual de documentos maestros.
 * Nunca se usa para producir documentos: el PDF oficial se queda vectorial.
 *
 *  - Windows: motor PDF nativo del sistema (Windows.Data.Pdf vía
 *    resources/scripts/pdf-a-png-windows.ps1). Sin dependencias.
 *  - Linux:   pdftoppm de poppler (DOCUMENTOS_QA_PDFTOPPM_PATH).
 *
 * Sin ninguno de los dos, disponible() = false y el QA queda "pendiente"
 * (no se puede activar la versión salvo excepción auditada).
 */
class RasterizadorPdf
{
    public function disponible(): bool
    {
        return $this->motor() !== null;
    }

    /**
     * windows | pdftoppm | null
     */
    public function motor(): ?string
    {
        $pdftoppm = config('documentos_maestros.validacion_visual.pdftoppm');

        if (is_string($pdftoppm) && $pdftoppm !== '') {
            return 'pdftoppm';
        }

        $script = (string) config('documentos_maestros.validacion_visual.script_windows');

        return PHP_OS_FAMILY === 'Windows' && is_file($script) ? 'windows' : null;
    }

    /**
     * @return list<array{pagina: int, ancho_pt: float, alto_pt: float, dpi: float, png: string}>
     *
     * @throws RuntimeException Si no hay motor o el PDF no se puede rasterizar.
     */
    public function rasterizar(string $pdf, int $dpi): array
    {
        $motor = $this->motor();

        if ($motor === null) {
            throw new RuntimeException('No hay un rasterizador de PDF disponible en este servidor.');
        }

        $carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qa-visual-'.Str::uuid();
        @mkdir($carpeta);
        $origen = $carpeta.DIRECTORY_SEPARATOR.'documento.pdf';
        file_put_contents($origen, $pdf);

        try {
            return $motor === 'windows' ? $this->conWindows($origen, $carpeta, $dpi) : $this->conPdftoppm($origen, $carpeta, $dpi);
        } finally {
            foreach (scandir($carpeta) ?: [] as $nombre) {
                if ($nombre !== '.' && $nombre !== '..') {
                    @unlink($carpeta.DIRECTORY_SEPARATOR.$nombre);
                }
            }

            @rmdir($carpeta);
        }
    }

    /**
     * @return list<array{pagina: int, ancho_pt: float, alto_pt: float, dpi: float, png: string}>
     */
    private function conWindows(string $origen, string $carpeta, int $dpi): array
    {
        $resultado = Process::timeout(180)->run([
            'powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', (string) config('documentos_maestros.validacion_visual.script_windows'),
            '-Origen', $origen, '-Carpeta', $carpeta, '-Dpi', (string) $dpi,
        ]);

        if (! $resultado->successful()) {
            throw new RuntimeException('El rasterizador de Windows falló: '.mb_substr($resultado->errorOutput(), 0, 300));
        }

        $paginas = [];

        foreach (preg_split('/\R/', trim($resultado->output())) ?: [] as $linea) {
            $datos = json_decode($linea, true);

            if (! is_array($datos) || ! isset($datos['pagina'], $datos['archivo'])) {
                continue;
            }

            $png = @file_get_contents((string) $datos['archivo']);

            if ($png === false || $png === '') {
                throw new RuntimeException("No se pudo leer la página {$datos['pagina']} rasterizada.");
            }

            $paginas[] = ['pagina' => (int) $datos['pagina'], 'ancho_pt' => (float) $datos['ancho_pt'], 'alto_pt' => (float) $datos['alto_pt'], 'dpi' => $this->dpiReal($png, (float) $datos['ancho_pt'], $dpi), 'png' => $png];
        }

        if ($paginas === []) {
            throw new RuntimeException('El rasterizador no devolvió páginas.');
        }

        return $paginas;
    }

    /**
     * DPI real de la imagen: Windows aplica la escala de pantalla (125 %,
     * 150 %…) al tamaño pedido, así que se mide sobre el PNG resultante.
     */
    private function dpiReal(string $png, float $anchoPt, int $pedido): float
    {
        $tamano = @getimagesizefromstring($png);

        if ($tamano === false || $anchoPt <= 0) {
            return (float) $pedido;
        }

        return round($tamano[0] / ($anchoPt / 72), 3);
    }

    /**
     * @return list<array{pagina: int, ancho_pt: float, alto_pt: float, dpi: float, png: string}>
     */
    private function conPdftoppm(string $origen, string $carpeta, int $dpi): array
    {
        $prefijo = $carpeta.DIRECTORY_SEPARATOR.'pagina';
        $resultado = Process::timeout(180)->run([
            (string) config('documentos_maestros.validacion_visual.pdftoppm'), '-png', '-r', (string) $dpi, $origen, $prefijo,
        ]);

        if (! $resultado->successful()) {
            throw new RuntimeException('pdftoppm falló: '.mb_substr($resultado->errorOutput(), 0, 300));
        }

        $archivos = glob($prefijo.'-*.png') ?: [];
        natsort($archivos);
        $paginas = [];

        foreach (array_values($archivos) as $i => $archivo) {
            $png = (string) file_get_contents($archivo);

            try {
                $tamano = getimagesizefromstring($png);
            } catch (Throwable $e) {
                Log::warning('RasterizadorPdf: página ilegible.', ['error' => $e->getMessage()]);
                $tamano = false;
            }

            $paginas[] = [
                'pagina' => $i + 1,
                'ancho_pt' => $tamano !== false ? round($tamano[0] * 72 / $dpi, 2) : 0.0,
                'alto_pt' => $tamano !== false ? round($tamano[1] * 72 / $dpi, 2) : 0.0,
                'dpi' => (float) $dpi,
                'png' => $png,
            ];
        }

        if ($paginas === []) {
            throw new RuntimeException('pdftoppm no devolvió páginas.');
        }

        return $paginas;
    }
}
