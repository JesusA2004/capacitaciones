<?php

namespace App\Services\Formatos\Analisis;

use App\Enums\TipoArchivoFormato;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * PDF con capa de texto (smalot/pdfparser, ya dependencia del proyecto).
 *
 * Las posiciones que da el parser son aproximadas (no aplica todas las
 * transformaciones del PDF, y en algunos generadores no las puede leer):
 * en ese caso se usa el texto por líneas con posición estimada. El editor
 * siempre las refina en el navegador con pdf.js al abrir la plantilla
 * (AnalisisPlantillaService::refinar()). Un PDF escaneado (sin texto)
 * regresa cero bloques y un mensaje claro.
 */
class AnalizadorPdfTexto implements AnalizadorPlantilla
{
    private const PT_A_MM = 25.4 / 72;

    public function soporta(TipoArchivoFormato $tipo): bool
    {
        return $tipo === TipoArchivoFormato::Pdf;
    }

    public function extraer(string $rutaLocal, array $paginas): array
    {
        $bloques = [];

        try {
            $documento = (new Parser)->parseFile($rutaLocal);

            foreach ($documento->getPages() as $indice => $pagina) {
                $numero = $indice + 1;
                $posicionados = $this->conPosicion($pagina, $numero);
                $bloques = [...$bloques, ...($posicionados !== [] ? $posicionados : $this->porLineas($pagina, $numero))];
            }
        } catch (Throwable $e) {
            return [
                'metodo' => 'pdf_texto',
                'bloques' => [],
                'placeholders' => [],
                'mensajes' => ['No se pudo leer el texto del PDF ('.class_basename($e).'). Coloca los campos manualmente.'],
                'posiciones_aproximadas' => true,
            ];
        }

        return [
            'metodo' => 'pdf_texto',
            'bloques' => $bloques,
            'placeholders' => [],
            'mensajes' => $bloques === []
                ? ['El PDF no tiene texto seleccionable (parece escaneado). Coloca los campos manualmente sobre la vista previa.']
                : [],
            'posiciones_aproximadas' => true,
        ];
    }

    /**
     * @return list<array{pagina: int, texto: string, x: float, y: float, ancho: float, alto: float, confianza: float}>
     */
    private function conPosicion(Page $pagina, int $numero): array
    {
        try {
            $caja = $pagina->getDetails()['MediaBox'] ?? null;
            $altoPt = is_array($caja) && isset($caja[3]) ? (float) $caja[3] - (float) ($caja[1] ?? 0) : 792.0;
            $datos = $pagina->getDataTm();
        } catch (Throwable) {
            return [];
        }

        $bloques = [];

        foreach ($datos as [$matriz, $texto]) {
            $texto = trim(preg_replace('/\s+/u', ' ', (string) $texto) ?? '');

            if (mb_strlen($texto) < 2) {
                continue;
            }

            $tamano = abs((float) ($matriz[3] ?? 10));
            $tamano = $tamano >= 4 && $tamano <= 40 ? $tamano : 10.0;

            $bloques[] = [
                'pagina' => $numero,
                'texto' => $texto,
                'x' => round((float) ($matriz[4] ?? 0) * self::PT_A_MM, 2),
                'y' => round(max(0, $altoPt - (float) ($matriz[5] ?? 0) - $tamano) * self::PT_A_MM, 2),
                'ancho' => round(mb_strlen($texto) * $tamano * 0.5 * self::PT_A_MM, 2),
                'alto' => round($tamano * 1.2 * self::PT_A_MM, 2),
                'confianza' => 0.7,
            ];
        }

        return $bloques;
    }

    /**
     * Sin posiciones legibles: una línea de texto por bloque, apiladas desde
     * el margen superior (el visor las reubica al abrir el editor).
     *
     * @return list<array{pagina: int, texto: string, x: float, y: float, ancho: float, alto: float, confianza: float}>
     */
    private function porLineas(Page $pagina, int $numero): array
    {
        try {
            $texto = $pagina->getText();
        } catch (Throwable) {
            return [];
        }

        $bloques = [];
        $y = 20.0;

        foreach (preg_split('/\R/u', $texto) ?: [] as $linea) {
            $linea = trim(preg_replace('/\s+/u', ' ', $linea) ?? '');

            if (mb_strlen($linea) < 2) {
                continue;
            }

            $bloques[] = [
                'pagina' => $numero,
                'texto' => $linea,
                'x' => 20.0,
                'y' => $y,
                'ancho' => round(min(170.0, mb_strlen($linea) * 2.0), 2),
                'alto' => 5.0,
                'confianza' => 0.5,
            ];
            $y += 7.0;
        }

        return $bloques;
    }
}
