<?php

namespace App\Services\Formatos\Analisis;

use App\Enums\TipoArchivoFormato;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Imagen (formato escaneado o fotografiado): OCR con Tesseract SOLO si el
 * servidor lo tiene configurado (config('formatos_oficiales.ocr.tesseract')).
 * No se agrega ninguna dependencia ni servicio externo: sin Tesseract, el
 * análisis regresa cero bloques con un mensaje claro y RH coloca los
 * campos a mano en el editor (el flujo completo funciona igual).
 */
class AnalizadorImagenOcr implements AnalizadorPlantilla
{
    public function soporta(TipoArchivoFormato $tipo): bool
    {
        return $tipo === TipoArchivoFormato::Imagen;
    }

    public function disponible(): bool
    {
        $ruta = config('formatos_oficiales.ocr.tesseract');

        return is_string($ruta) && $ruta !== '';
    }

    public function extraer(string $rutaLocal, array $paginas): array
    {
        if (! $this->disponible()) {
            return $this->sinOcr('El OCR no está configurado en este servidor (FORMATOS_TESSERACT_PATH). Coloca los campos manualmente sobre la imagen.');
        }

        $dimensiones = @getimagesize($rutaLocal);
        $anchoMm = (float) ($paginas[0]['ancho'] ?? 215.9);

        if ($dimensiones === false || $dimensiones[0] <= 0) {
            return $this->sinOcr('No se pudo leer la imagen para OCR.');
        }

        $mmPorPx = $anchoMm / $dimensiones[0];

        try {
            $resultado = Process::timeout(60)->run([
                (string) config('formatos_oficiales.ocr.tesseract'),
                $rutaLocal,
                'stdout',
                '-l',
                (string) config('formatos_oficiales.ocr.idioma', 'spa'),
                'tsv',
            ]);
        } catch (Throwable $e) {
            return $this->sinOcr('No se pudo ejecutar el OCR ('.class_basename($e).'). Coloca los campos manualmente.');
        }

        if (! $resultado->successful()) {
            return $this->sinOcr('El OCR falló al leer la imagen. Coloca los campos manualmente.');
        }

        return [
            'metodo' => 'ocr',
            'bloques' => $this->lineasDesdeTsv($resultado->output(), $mmPorPx),
            'placeholders' => [],
            'mensajes' => ['Texto obtenido por OCR: revisa cada sugerencia antes de confirmarla.'],
            'posiciones_aproximadas' => false,
        ];
    }

    /**
     * Agrupa las palabras del TSV de Tesseract en líneas (bloque, párrafo,
     * línea) con su caja envolvente.
     *
     * @return list<array{pagina: int, texto: string, x: float, y: float, ancho: float, alto: float, confianza: float}>
     */
    public function lineasDesdeTsv(string $tsv, float $mmPorPx): array
    {
        $lineas = [];

        foreach (preg_split('/\r?\n/', $tsv) ?: [] as $i => $fila) {
            $c = explode("\t", $fila);

            if ($i === 0 || count($c) < 12 || (int) $c[0] !== 5 || trim($c[11]) === '' || (float) $c[10] < 0) {
                continue;
            }

            $clave = sprintf('%s-%s-%s', $c[2], $c[3], $c[4]);
            [$x, $y, $w, $h] = [(int) $c[6], (int) $c[7], (int) $c[8], (int) $c[9]];
            $linea = $lineas[$clave] ?? ['palabras' => [], 'x1' => $x, 'y1' => $y, 'x2' => $x + $w, 'y2' => $y + $h, 'conf' => []];
            $linea['palabras'][] = trim($c[11]);
            $linea['x1'] = min($linea['x1'], $x);
            $linea['y1'] = min($linea['y1'], $y);
            $linea['x2'] = max($linea['x2'], $x + $w);
            $linea['y2'] = max($linea['y2'], $y + $h);
            $linea['conf'][] = (float) $c[10];
            $lineas[$clave] = $linea;
        }

        $bloques = [];

        foreach ($lineas as $linea) {
            $bloques[] = [
                'pagina' => 1,
                'texto' => implode(' ', $linea['palabras']),
                'x' => round($linea['x1'] * $mmPorPx, 2),
                'y' => round($linea['y1'] * $mmPorPx, 2),
                'ancho' => round(($linea['x2'] - $linea['x1']) * $mmPorPx, 2),
                'alto' => round(($linea['y2'] - $linea['y1']) * $mmPorPx, 2),
                'confianza' => round(array_sum($linea['conf']) / max(1, count($linea['conf'])) / 100, 2),
            ];
        }

        return $bloques;
    }

    /**
     * @return array{metodo: string, bloques: list<array{pagina: int, texto: string, x: float, y: float, ancho: float, alto: float, confianza: float}>, placeholders: list<string>, mensajes: list<string>, posiciones_aproximadas: bool}
     */
    private function sinOcr(string $mensaje): array
    {
        return ['metodo' => 'manual', 'bloques' => [], 'placeholders' => [], 'mensajes' => [$mensaje], 'posiciones_aproximadas' => false];
    }
}
