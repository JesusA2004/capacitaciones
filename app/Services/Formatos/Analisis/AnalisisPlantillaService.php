<?php

namespace App\Services\Formatos\Analisis;

use App\Enums\EstrategiaFormato;
use App\Enums\TipoArchivoFormato;
use App\Models\OfficialFormatVersion;
use App\Services\Formatos\OfficialFormatStorageService;
use App\Services\Formatos\Variables\CatalogoVariablesFormato;

/**
 * Analiza una versión de plantilla y guarda el resultado en
 * `official_format_versions.analisis`:
 *
 *   {metodo, paginas, bloques, sugerencias, placeholders, mensajes,
 *    posiciones_aproximadas, analizado_en}
 *
 * Extractores desacoplados (AnalizadorPlantilla) por tipo de archivo; el
 * detector de campos es uno solo. Además de lo que el servidor puede leer,
 * el editor envía el texto con posición EXACTA que obtiene pdf.js al
 * dibujar el PDF base (refinar()), que reemplaza al del servidor.
 *
 * @phpstan-import-type Bloque from AnalizadorPlantilla
 */
class AnalisisPlantillaService
{
    public function __construct(
        private readonly OfficialFormatStorageService $storage,
        private readonly DetectorCampos $detector,
        private readonly CatalogoVariablesFormato $catalogo,
        private readonly AnalizadorPdfTexto $pdf,
        private readonly AnalizadorImagenOcr $imagen,
        private readonly AnalizadorDocx $docx,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analizar(OfficialFormatVersion $version, string $rutaFuenteLocal): array
    {
        $paginas = $version->paginas ?? [];

        $extraccion = match ($version->file_type) {
            TipoArchivoFormato::Pdf => $this->pdf->extraer($rutaFuenteLocal, $paginas),
            TipoArchivoFormato::Imagen => $this->imagen->extraer($rutaFuenteLocal, $paginas),
            TipoArchivoFormato::Docx => $this->docxConTextoDelBase($version, $rutaFuenteLocal, $paginas),
        };

        return $this->guardar($version, $extraccion);
    }

    /**
     * Reemplaza los bloques de texto por los que midió pdf.js en el
     * navegador (coordenadas exactas) y vuelve a detectar.
     *
     * @param  list<Bloque>  $bloques
     * @return array<string, mixed>
     */
    public function refinar(OfficialFormatVersion $version, array $bloques): array
    {
        $anterior = $version->analisis ?? [];

        return $this->guardar($version, [
            'metodo' => ($anterior['metodo'] ?? 'pdf_texto') === 'ocr' ? 'ocr' : 'pdf_texto_visor',
            'bloques' => $bloques,
            'placeholders' => $anterior['placeholders'] ?? [],
            'mensajes' => $bloques === [] ? ['No se encontró texto en el documento. Coloca los campos manualmente.'] : [],
            'posiciones_aproximadas' => false,
        ]);
    }

    /**
     * @param  array{metodo: string, bloques: list<Bloque>, placeholders: list<string>, mensajes: list<string>, posiciones_aproximadas: bool}  $extraccion
     * @return array<string, mixed>
     */
    private function guardar(OfficialFormatVersion $version, array $extraccion): array
    {
        $placeholders = array_map(fn (string $p) => [
            'placeholder' => $p,
            'variable' => $this->catalogo->claveDePlaceholder($p),
        ], $extraccion['placeholders']);

        $sugerencias = $version->estrategia === EstrategiaFormato::DocxVariables
            ? []
            : $this->detector->detectar($extraccion['bloques'], $version->paginas ?? []);

        $analisis = [
            'metodo' => $extraccion['metodo'],
            'bloques' => array_slice($extraccion['bloques'], 0, 800),
            'sugerencias' => $sugerencias,
            'placeholders' => $placeholders,
            'mensajes' => $extraccion['mensajes'],
            'posiciones_aproximadas' => $extraccion['posiciones_aproximadas'],
            'analizado_en' => now()->toIso8601String(),
        ];

        $version->update(['analisis' => $analisis]);

        return $analisis;
    }

    /**
     * @param  list<array{numero: int, ancho: float, alto: float}>  $paginas
     * @return array{metodo: string, bloques: list<Bloque>, placeholders: list<string>, mensajes: list<string>, posiciones_aproximadas: bool}
     */
    private function docxConTextoDelBase(OfficialFormatVersion $version, string $rutaFuenteLocal, array $paginas): array
    {
        $docx = $this->docx->extraer($rutaFuenteLocal, $paginas);

        if ($version->base_path === null || $version->estrategia === EstrategiaFormato::DocxVariables) {
            return $docx;
        }

        $base = $this->storage->aTemporal($version->base_path, 'pdf');

        try {
            $pdf = $this->pdf->extraer($base, $paginas);
        } finally {
            @unlink($base);
        }

        return [...$pdf, 'metodo' => 'docx', 'placeholders' => $docx['placeholders']];
    }
}
