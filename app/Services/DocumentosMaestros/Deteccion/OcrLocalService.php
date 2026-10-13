<?php

namespace App\Services\DocumentosMaestros\Deteccion;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * OCR LOCAL y OPCIONAL (Tesseract) — SOLO para PDFs escaneados (sin capa de
 * texto). Nunca un servicio externo ni de pago: el documento no sale del
 * servidor. Sin Tesseract instalado el editor funciona igual: RH coloca
 * los espacios a mano sobre la página.
 *
 * El OCR solo PROPONE (palabras con caja y confianza); el mapa definitivo
 * siempre lo confirma RH en el editor.
 *
 * Seguridad: archivo temporal único por página, borrado en finally, sin
 * URL pública, timeout por página, límite de páginas, y nunca se escribe
 * en el log el texto reconocido (solo conteos y errores).
 *
 * @phpstan-type Palabra array{pagina: int, texto: string, x: float, y: float, ancho: float, alto: float, confianza: float}
 */
class OcrLocalService
{
    public const MAX_PAGINAS = 10;

    public function disponible(): bool
    {
        return $this->binario() !== null;
    }

    public function version(): ?string
    {
        $binario = $this->binario();

        if ($binario === null) {
            return null;
        }

        try {
            $salida = Process::timeout(10)->run([$binario, '--version']);
            $linea = strtok($salida->output().$salida->errorOutput(), "\n");

            return $linea !== false ? trim($linea) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Palabras reconocidas en la imagen PNG de una página.
     *
     * @return list<Palabra>
     */
    public function palabras(string $png, int $pagina, float $dpi): array
    {
        $binario = $this->binario();

        if ($binario === null || $pagina > self::MAX_PAGINAS) {
            return [];
        }

        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr-'.Str::uuid().'.png';

        try {
            file_put_contents($ruta, $png);

            // MIME real (no la extensión): solo una imagen PNG llega a Tesseract.
            if (mime_content_type($ruta) !== 'image/png') {
                return [];
            }

            $resultado = Process::timeout((int) config('documentos_maestros.deteccion.ocr_timeout', 60))->run([
                $binario,
                $ruta,
                'stdout',
                '-l',
                (string) config('documentos_maestros.deteccion.ocr_idioma', 'spa'),
                '--psm',
                '11',
                'tsv',
            ]);

            if (! $resultado->successful()) {
                Log::warning('OcrLocalService: Tesseract falló.', ['pagina' => $pagina, 'codigo' => $resultado->exitCode()]);

                return [];
            }

            $palabras = $this->interpretarTsv($resultado->output(), $pagina, $dpi);
            Log::info('OcrLocalService: página reconocida.', ['pagina' => $pagina, 'palabras' => count($palabras)]);

            return $palabras;
        } catch (Throwable $e) {
            Log::warning('OcrLocalService: no se pudo ejecutar el OCR.', ['pagina' => $pagina, 'error' => class_basename($e)]);

            return [];
        } finally {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }

    /**
     * @return list<Palabra>
     */
    public function interpretarTsv(string $tsv, int $pagina, float $dpi): array
    {
        $mmPorPx = 25.4 / max(1.0, $dpi);
        $palabras = [];

        foreach (preg_split('/\R/', $tsv) ?: [] as $i => $linea) {
            $columnas = explode("\t", $linea);

            // level page_num block par line word left top width height conf text
            if ($i === 0 || count($columnas) < 12 || (int) $columnas[0] !== 5) {
                continue;
            }

            $texto = trim($columnas[11]);
            $confianza = (float) $columnas[10];

            if ($texto === '' || $confianza < 0) {
                continue;
            }

            $palabras[] = [
                'pagina' => $pagina,
                'texto' => $texto,
                'x' => round((float) $columnas[6] * $mmPorPx, 2),
                'y' => round((float) $columnas[7] * $mmPorPx, 2),
                'ancho' => round((float) $columnas[8] * $mmPorPx, 2),
                'alto' => round((float) $columnas[9] * $mmPorPx, 2),
                'confianza' => round($confianza / 100, 3),
            ];
        }

        return $palabras;
    }

    private function binario(): ?string
    {
        $ruta = config('documentos_maestros.deteccion.tesseract') ?? config('formatos_oficiales.ocr.tesseract');

        return is_string($ruta) && $ruta !== '' ? $ruta : null;
    }
}
