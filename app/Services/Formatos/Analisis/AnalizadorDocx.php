<?php

namespace App\Services\Formatos\Analisis;

use App\Enums\TipoArchivoFormato;
use PhpOffice\PhpWord\TemplateProcessor;
use Throwable;

/**
 * Word: detecta los marcadores {{variable}} que ya trae el documento (si
 * los trae, la versión se genera rellenándolos dentro del propio DOCX). El
 * texto con posición del Word convertido a PDF lo aporta el análisis PDF
 * sobre el PDF base (AnalisisPlantillaService).
 */
class AnalizadorDocx implements AnalizadorPlantilla
{
    public function soporta(TipoArchivoFormato $tipo): bool
    {
        return $tipo === TipoArchivoFormato::Docx;
    }

    public function extraer(string $rutaLocal, array $paginas): array
    {
        return [
            'metodo' => 'docx',
            'bloques' => [],
            'placeholders' => $this->placeholders($rutaLocal),
            'mensajes' => [],
            'posiciones_aproximadas' => false,
        ];
    }

    /**
     * @return list<string>
     */
    public function placeholders(string $rutaLocal): array
    {
        try {
            $procesador = new TemplateProcessor($rutaLocal);
            $procesador->setMacroChars('{{', '}}');

            return array_values(array_unique(array_map('trim', $procesador->getVariables())));
        } catch (Throwable) {
            return [];
        }
    }
}
