<?php

namespace App\Services\DocumentosMaestros\Deteccion;

use DOMDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Palabras de un PDF DIGITAL con su caja en milímetros (origen arriba a la
 * izquierda), sin OCR: Poppler `pdftotext -bbox-layout` (herramienta local;
 * el original nunca se modifica). Sin Poppler se usa smalot/pdfparser para
 * saber al menos si el PDF tiene capa de texto (sin posiciones fiables).
 *
 * @phpstan-type Palabra array{pagina: int, texto: string, x: float, y: float, ancho: float, alto: float, confianza: float}
 */
class LectorTextoPdf
{
    private const PT_A_MM = 25.4 / 72;

    /** Binario resuelto (false = aún no se busca). */
    private string|false|null $binario = false;

    public function disponible(): bool
    {
        return $this->binario() !== null;
    }

    /**
     * @return list<Palabra>
     */
    public function palabras(string $pdf): array
    {
        $binario = $this->binario();

        if ($binario === null) {
            return [];
        }

        $carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf-texto-'.Str::uuid();
        @mkdir($carpeta);
        $origen = $carpeta.DIRECTORY_SEPARATOR.'documento.pdf';
        $destino = $carpeta.DIRECTORY_SEPARATOR.'texto.html';

        try {
            file_put_contents($origen, $pdf);
            $resultado = Process::timeout(60)->run([$binario, '-bbox-layout', '-enc', 'UTF-8', $origen, $destino]);

            if (! $resultado->successful() || ! is_file($destino)) {
                Log::warning('LectorTextoPdf: pdftotext falló.', ['salida' => mb_substr($resultado->errorOutput(), 0, 300)]);

                return [];
            }

            return $this->interpretar((string) file_get_contents($destino));
        } catch (Throwable $e) {
            Log::warning('LectorTextoPdf: no se pudo leer el texto del PDF.', ['error' => $e->getMessage()]);

            return [];
        } finally {
            foreach ([$origen, $destino] as $archivo) {
                if (is_file($archivo)) {
                    @unlink($archivo);
                }
            }

            @rmdir($carpeta);
        }
    }

    /**
     * Caracteres de texto real por página (para distinguir un PDF digital de
     * uno escaneado), con o sin Poppler.
     */
    public function caracteresDeTexto(string $pdf): int
    {
        $palabras = $this->palabras($pdf);

        if ($palabras !== []) {
            return array_sum(array_map(fn (array $p): int => mb_strlen($p['texto']), $palabras));
        }

        try {
            return mb_strlen(trim((new Parser)->parseContent($pdf)->getText()));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<Palabra>
     */
    private function interpretar(string $html): array
    {
        $dom = new DOMDocument;
        $limpio = (string) preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);

        if (! @$dom->loadXML($limpio, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }

        $palabras = [];
        $numero = 0;

        foreach ($dom->getElementsByTagName('page') as $pagina) {
            $numero++;

            foreach ($pagina->getElementsByTagName('word') as $palabra) {
                $texto = trim($palabra->textContent);

                if ($texto === '') {
                    continue;
                }

                $x0 = (float) $palabra->getAttribute('xMin');
                $y0 = (float) $palabra->getAttribute('yMin');
                $x1 = (float) $palabra->getAttribute('xMax');
                $y1 = (float) $palabra->getAttribute('yMax');

                $palabras[] = [
                    'pagina' => $numero,
                    'texto' => $texto,
                    'x' => round($x0 * self::PT_A_MM, 2),
                    'y' => round($y0 * self::PT_A_MM, 2),
                    'ancho' => round(($x1 - $x0) * self::PT_A_MM, 2),
                    'alto' => round(($y1 - $y0) * self::PT_A_MM, 2),
                    'confianza' => 1.0,
                ];
            }
        }

        return $palabras;
    }

    private function binario(): ?string
    {
        if ($this->binario !== false) {
            return $this->binario;
        }

        // Solo Poppler sirve (-bbox-layout): el pdftotext de xpdf que traen
        // algunos sistemas tiene el mismo nombre pero no da posiciones.
        $candidatos = [];
        $configurado = config('documentos_maestros.deteccion.pdftotext');

        if (is_string($configurado) && $configurado !== '') {
            $candidatos[] = $configurado;
        }

        // Junto al pdftoppm del QA visual (mismo paquete de Poppler).
        $pdftoppm = config('documentos_maestros.validacion_visual.pdftoppm');

        if (is_string($pdftoppm) && $pdftoppm !== '') {
            $candidatos[] = dirname($pdftoppm).DIRECTORY_SEPARATOR.'pdftotext'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        }

        $candidatos[] = 'pdftotext';

        foreach ($candidatos as $comando) {
            if ($this->esPoppler($comando)) {
                return $this->binario = $comando;
            }
        }

        return $this->binario = null;
    }

    private function esPoppler(string $comando): bool
    {
        try {
            $resultado = Process::timeout(10)->run([$comando, '-v']);

            // pdftotext -v escribe la versión en stderr y sale con 0 o 99.
            return str_contains(mb_strtolower($resultado->output().$resultado->errorOutput()), 'poppler');
        } catch (Throwable) {
            return false;
        }
    }
}
