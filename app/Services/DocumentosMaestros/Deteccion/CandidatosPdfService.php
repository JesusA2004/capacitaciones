<?php

namespace App\Services\DocumentosMaestros\Deteccion;

use App\Enums\ZonaCampoDocumento;
use App\Services\DocumentosMaestros\Calidad\RasterizadorPdf;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Espacios para datos en un PDF (cajas en mm, origen arriba-izquierda):
 *
 *  - PDF DIGITAL: palabras con posición (LectorTextoPdf, sin OCR) +
 *    rayas «____» escritas como texto + líneas dibujadas (raster) +
 *    casillas ☐.
 *  - PDF ESCANEADO: las palabras salen del OCR LOCAL (OcrLocalService) y las
 *    líneas, del mismo detector raster. Sin OCR instalado solo se detectan
 *    las líneas (RH coloca el resto a mano).
 *
 * Cada espacio propone el dato según el texto a su izquierda (o encima) y
 * se marca firma/huella si así lo indica el texto. Nunca decide: todo
 * llega AMARILLO (por confirmar) o ROJO (sin sugerencia) al editor.
 *
 * @phpstan-import-type Palabra from LectorTextoPdf
 *
 * @phpstan-type CandidatoPdf array{page: int, x: float, y: float, width: float, height: float, contexto: string, type: 'texto'|'casilla', zona: ZonaCampoDocumento, sugerencias: list<array{clave: string, etiqueta: string, confianza: float}>, detection_method: string, detection_confidence: float}
 */
class CandidatosPdfService
{
    private const DPI_LINEAS = 100;

    private const DPI_OCR = 200;

    private const ALTO_CAJA = 4.5;

    public function __construct(
        private readonly LectorTextoPdf $lector,
        private readonly OcrLocalService $ocr,
        private readonly DetectorLineasRaster $lineas,
        private readonly RasterizadorPdf $rasterizador,
        private readonly SugeridorVariables $sugeridor,
    ) {}

    /**
     * @param  'pdf_texto'|'pdf_escaneado'  $tipo
     * @return array{candidatos: list<CandidatoPdf>, ocr: bool, mensaje: string|null}
     */
    public function detectar(string $pdf, string $tipo): array
    {
        $paginasRaster = $this->raster($pdf, $tipo === 'pdf_escaneado' ? self::DPI_OCR : self::DPI_LINEAS);
        $mensaje = null;

        if ($tipo === 'pdf_escaneado') {
            $palabras = [];

            if ($this->ocr->disponible()) {
                foreach ($paginasRaster as $pagina) {
                    $palabras = [...$palabras, ...$this->ocr->palabras($pagina['png'], $pagina['pagina'], $pagina['dpi'])];
                }
            } else {
                $mensaje = 'PDF escaneado y el OCR local no está instalado: se detectaron solo las líneas. Coloca los demás espacios a mano.';
            }
        } else {
            $palabras = $this->lector->palabras($pdf);

            if ($palabras === [] && ! $this->lector->disponible()) {
                $mensaje = 'Poppler (pdftotext) no está instalado: se detectaron solo las líneas dibujadas.';
            }
        }

        $candidatos = [
            ...$this->desdeGuiones($palabras),
            ...$this->desdeCasillas($palabras),
        ];

        foreach ($paginasRaster as $pagina) {
            foreach ($this->lineas->detectar($pagina['png'], $pagina['dpi']) as $linea) {
                $caja = ['page' => $pagina['pagina'], 'x' => $linea['x'], 'y' => max(0.0, $linea['y'] - self::ALTO_CAJA), 'width' => $linea['ancho'], 'height' => self::ALTO_CAJA];

                if ($this->tieneTextoEncima($caja, $palabras) || $this->solapa($caja, $candidatos)) {
                    continue;
                }

                $candidatos[] = $this->candidato($caja, $this->etiquetaPara($caja, $palabras), 'texto', $tipo === 'pdf_escaneado' ? 'ocr_linea' : 'pdf_linea');
            }
        }

        usort($candidatos, fn (array $a, array $b): int => [$a['page'], round($a['y']), $a['x']] <=> [$b['page'], round($b['y']), $b['x']]);

        return ['candidatos' => $candidatos, 'ocr' => $tipo === 'pdf_escaneado' && $this->ocr->disponible(), 'mensaje' => $mensaje];
    }

    /**
     * Rayas escritas como texto («______»).
     *
     * @param  list<Palabra>  $palabras
     * @return list<CandidatoPdf>
     */
    private function desdeGuiones(array $palabras): array
    {
        $candidatos = [];

        foreach ($palabras as $palabra) {
            if (preg_match('/_{3,}/u', $palabra['texto']) !== 1) {
                continue;
            }

            // Un «Nombre:____» pegado: el espacio empieza donde empiezan los guiones.
            $prefijo = (string) preg_replace('/_{3,}.*$/u', '', $palabra['texto']);
            $proporcion = mb_strlen($prefijo) / max(1, mb_strlen($palabra['texto']));
            $x = $palabra['x'] + $palabra['ancho'] * $proporcion;
            $caja = [
                'page' => $palabra['pagina'],
                'x' => round($x, 2),
                'y' => round(max(0.0, $palabra['y'] + $palabra['alto'] - self::ALTO_CAJA - 0.6), 2),
                'width' => round(max(8.0, $palabra['ancho'] * (1 - $proporcion)), 2),
                'height' => self::ALTO_CAJA,
            ];
            $etiqueta = trim($prefijo) !== '' ? trim($prefijo) : $this->etiquetaPara($caja, $palabras);
            $candidatos[] = $this->candidato($caja, $etiqueta, 'texto', $palabra['confianza'] < 1 ? 'ocr_guiones' : 'pdf_guiones', $palabra['confianza']);
        }

        return $candidatos;
    }

    /**
     * @param  list<Palabra>  $palabras
     * @return list<CandidatoPdf>
     */
    private function desdeCasillas(array $palabras): array
    {
        $candidatos = [];

        foreach ($palabras as $palabra) {
            if (preg_match('/^[☐☒□■]$/u', $palabra['texto']) !== 1) {
                continue;
            }

            $caja = ['page' => $palabra['pagina'], 'x' => $palabra['x'], 'y' => $palabra['y'], 'width' => max(3.0, $palabra['ancho']), 'height' => max(3.0, $palabra['alto'])];
            $candidatos[] = $this->candidato($caja, $this->textoALaDerecha($caja, $palabras), 'casilla', 'pdf_casilla');
        }

        return $candidatos;
    }

    /**
     * @param  array{page: int, x: float, y: float, width: float, height: float}  $caja
     * @return CandidatoPdf
     */
    private function candidato(array $caja, string $etiqueta, string $tipo, string $metodo, float $confianzaLectura = 1.0): array
    {
        $zona = $tipo === 'texto' ? ($this->sugeridor->zona($etiqueta) ?? ZonaCampoDocumento::Dato) : ZonaCampoDocumento::Dato;
        $sugerencias = $zona === ZonaCampoDocumento::Dato && $tipo === 'texto' ? $this->sugeridor->sugerir($etiqueta) : [];

        return [
            ...$caja,
            'contexto' => $etiqueta,
            'type' => $tipo === 'casilla' ? 'casilla' : 'texto',
            'zona' => $zona,
            'sugerencias' => $sugerencias,
            'detection_method' => $metodo,
            'detection_confidence' => round(($sugerencias[0]['confianza'] ?? 0.3) * $confianzaLectura, 3),
        ];
    }

    /**
     * Texto a la izquierda en el mismo renglón; si no hay, el renglón de
     * arriba (etiqueta encima de la línea).
     *
     * @param  array{page: int, x: float, y: float, width: float, height: float}  $caja
     * @param  list<Palabra>  $palabras
     */
    private function etiquetaPara(array $caja, array $palabras): string
    {
        $centro = $caja['y'] + $caja['height'] / 2;
        $mismaLinea = array_filter($palabras, fn (array $p): bool => $p['pagina'] === $caja['page']
            && abs(($p['y'] + $p['alto'] / 2) - $centro) < 3.5
            && $p['x'] + $p['ancho'] <= $caja['x'] + 1
            && $caja['x'] - ($p['x'] + $p['ancho']) < 70
            && preg_match('/^_+$/u', $p['texto']) !== 1);

        if ($mismaLinea !== []) {
            usort($mismaLinea, fn (array $a, array $b): int => $a['x'] <=> $b['x']);

            return trim(implode(' ', array_column($mismaLinea, 'texto')));
        }

        $arriba = array_filter($palabras, fn (array $p): bool => $p['pagina'] === $caja['page']
            && $p['y'] + $p['alto'] <= $caja['y'] + 0.5
            && $caja['y'] - ($p['y'] + $p['alto']) < 6
            && $p['x'] < $caja['x'] + $caja['width'] && $caja['x'] < $p['x'] + $p['ancho']);
        usort($arriba, fn (array $a, array $b): int => $a['x'] <=> $b['x']);

        // Debajo de la línea (firmas: «Firma del trabajador» bajo la raya).
        $abajo = array_filter($palabras, fn (array $p): bool => $p['pagina'] === $caja['page']
            && $p['y'] >= $caja['y'] + $caja['height'] - 0.5
            && $p['y'] - ($caja['y'] + $caja['height']) < 6
            && $p['x'] < $caja['x'] + $caja['width'] && $caja['x'] < $p['x'] + $p['ancho']);
        usort($abajo, fn (array $a, array $b): int => $a['x'] <=> $b['x']);

        return trim(implode(' ', [...array_column($arriba, 'texto'), ...array_column($abajo, 'texto')]));
    }

    /**
     * @param  array{page: int, x: float, y: float, width: float, height: float}  $caja
     * @param  list<Palabra>  $palabras
     */
    private function textoALaDerecha(array $caja, array $palabras): string
    {
        $centro = $caja['y'] + $caja['height'] / 2;
        $derecha = array_filter($palabras, fn (array $p): bool => $p['pagina'] === $caja['page']
            && abs(($p['y'] + $p['alto'] / 2) - $centro) < 3
            && $p['x'] >= $caja['x'] + $caja['width'] - 0.5
            && $p['x'] - ($caja['x'] + $caja['width']) < 40);
        usort($derecha, fn (array $a, array $b): int => $a['x'] <=> $b['x']);

        return trim(implode(' ', array_slice(array_column($derecha, 'texto'), 0, 6)));
    }

    /**
     * Una línea con texto encima es un subrayado o el borde de una tabla
     * llena, no un espacio para escribir.
     *
     * @param  array{page: int, x: float, y: float, width: float, height: float}  $caja
     * @param  list<Palabra>  $palabras
     */
    private function tieneTextoEncima(array $caja, array $palabras): bool
    {
        foreach ($palabras as $p) {
            if ($p['pagina'] === $caja['page'] && preg_match('/^_+$/u', $p['texto']) !== 1
                && $p['y'] < $caja['y'] + $caja['height'] && $p['y'] + $p['alto'] > $caja['y'] + 0.8
                && $p['x'] < $caja['x'] + $caja['width'] - 1 && $p['x'] + $p['ancho'] > $caja['x'] + 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{page: int, x: float, y: float, width: float, height: float}  $caja
     * @param  list<CandidatoPdf>  $candidatos
     */
    private function solapa(array $caja, array $candidatos): bool
    {
        foreach ($candidatos as $c) {
            if ($c['page'] === $caja['page'] && abs($c['y'] - $caja['y']) < 3
                && $c['x'] < $caja['x'] + $caja['width'] && $caja['x'] < $c['x'] + $c['width']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{pagina: int, dpi: float, png: string}>
     */
    private function raster(string $pdf, int $dpi): array
    {
        if (! $this->rasterizador->disponible()) {
            return [];
        }

        try {
            return array_map(
                fn (array $p): array => ['pagina' => $p['pagina'], 'dpi' => $p['dpi'], 'png' => $p['png']],
                array_slice($this->rasterizador->rasterizar($pdf, $dpi), 0, OcrLocalService::MAX_PAGINAS),
            );
        } catch (Throwable $e) {
            Log::warning('CandidatosPdfService: no se pudo rasterizar el PDF para detectar líneas.', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
