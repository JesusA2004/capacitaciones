<?php

namespace App\Services\Expedientes\DivisionHistorico;

use App\Enums\MetodoLecturaPagina;
use App\Services\Expedientes\DivisionHistorico\Ocr\MotorOcr;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Texto de CADA página del PDF histórico: primero la capa de texto embebida
 * (smalot/pdfparser); si la página no la tiene (escaneo), OCR local
 * (MotorOcr). Sin OCR disponible la página queda `sin_texto` — se reporta,
 * nunca se adivina su contenido.
 */
class LectorPaginasPdf
{
    public function __construct(private readonly MotorOcr $ocr) {}

    public function ocr(): MotorOcr
    {
        return $this->ocr;
    }

    /**
     * @return list<array{pagina: int, texto: string, metodo: MetodoLecturaPagina}>
     */
    public function leer(string $rutaPdf, int $totalPaginas): array
    {
        $embebidos = $this->textosEmbebidos($rutaPdf);
        $minimo = max(1, (int) config('expedientes.division_historico.min_caracteres_texto', 25));
        $paginas = [];

        for ($numero = 1; $numero <= $totalPaginas; $numero++) {
            $texto = trim($embebidos[$numero - 1] ?? '');

            if ($this->caracteresUtiles($texto) >= $minimo) {
                $paginas[] = ['pagina' => $numero, 'texto' => $texto, 'metodo' => MetodoLecturaPagina::Texto];

                continue;
            }

            $reconocido = $this->ocr->disponible() ? $this->ocr->textoDePagina($rutaPdf, $numero) : null;

            if ($reconocido !== null && $this->caracteresUtiles($reconocido) > 0) {
                $paginas[] = ['pagina' => $numero, 'texto' => trim($reconocido), 'metodo' => MetodoLecturaPagina::Ocr];

                continue;
            }

            $paginas[] = [
                'pagina' => $numero,
                'texto' => $texto,
                // Con OCR disponible pero sin texto reconocido la página se leyó (p. ej. una fotografía).
                'metodo' => $this->ocr->disponible() ? MetodoLecturaPagina::Ocr : MetodoLecturaPagina::SinTexto,
            ];
        }

        return $paginas;
    }

    /**
     * @return list<string> Texto embebido por página (vacío si el PDF no se pudo leer).
     */
    private function textosEmbebidos(string $rutaPdf): array
    {
        try {
            $documento = (new PdfParser)->parseFile($rutaPdf);
            $textos = [];

            foreach ($documento->getPages() as $pagina) {
                try {
                    $textos[] = $pagina->getText();
                } catch (Throwable) {
                    $textos[] = '';
                }
            }

            return $textos;
        } catch (Throwable $e) {
            Log::warning('division_historico: no se pudo leer la capa de texto del PDF.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function caracteresUtiles(string $texto): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}]/u', $texto);
    }
}
