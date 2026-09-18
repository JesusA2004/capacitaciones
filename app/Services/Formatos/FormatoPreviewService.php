<?php

namespace App\Services\Formatos;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Services\Plantillas\PlaceholderResolver;
use App\Services\Plantillas\PlantillaDocumentoService;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;

/**
 * Vista previa (HTML embebido) y conversión a PDF de un formato generado.
 * No duplica la fusión de placeholders: reutiliza
 * App\Services\Plantillas\PlantillaDocumentoService::generar() para obtener
 * los bytes del DOCX y solo se encarga de convertirlos a algo que se pueda
 * mostrar/descargar. Ambas conversiones son best-effort: un DOCX con
 * estructura compleja puede no traducirse perfecto a HTML/PDF via PhpWord,
 * así que cualquier fallo se loggea y se degrada con elegancia (nunca
 * truena la pantalla de formatos ni bloquea la descarga del DOCX original).
 */
class FormatoPreviewService
{
    public function __construct(
        private readonly PlantillaDocumentoService $generador,
        private readonly PlaceholderResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     * @return array{
     *     html: string|null,
     *     variables: array<string, string>,
     *     faltantes: list<string>,
     *     docx: string,
     * }
     */
    public function previsualizar(DocumentTemplate $plantilla, Colaborador|Candidato|null $sujeto, array $extra = []): array
    {
        $valoresResueltos = $this->resolver->resolver($sujeto, $extra);
        $variablesPlantilla = $this->generador->variablesEnPlantilla($plantilla);

        $faltantes = array_values(array_filter(
            $variablesPlantilla,
            fn (string $clave) => trim((string) ($valoresResueltos[$clave] ?? '')) === '',
        ));

        $resultado = $this->generador->generar($plantilla, $sujeto, $extra);

        return [
            'html' => $this->aHtml($resultado['contenido']),
            'variables' => array_intersect_key($valoresResueltos, array_flip($variablesPlantilla)),
            'faltantes' => $faltantes,
            'docx' => $resultado['contenido'],
        ];
    }

    /**
     * @return string|null Null si no se pudo convertir (se loggea el motivo).
     */
    public function aHtml(string $contenidoDocx): ?string
    {
        return $this->convertir($contenidoDocx, 'HTML');
    }

    /**
     * @return string|null Null si no se pudo convertir (se loggea el motivo).
     */
    public function aPdf(string $contenidoDocx): ?string
    {
        if (! class_exists(Dompdf::class)) {
            return null;
        }

        Settings::setPdfRendererName('DomPDF');
        Settings::setPdfRendererPath(base_path('vendor/dompdf/dompdf'));

        return $this->convertir($contenidoDocx, 'PDF');
    }

    private function convertir(string $contenidoDocx, string $formato): ?string
    {
        $archivoOrigen = sys_get_temp_dir().'/'.Str::uuid().'.docx';
        $archivoSalida = sys_get_temp_dir().'/'.Str::uuid().'.'.strtolower($formato);

        try {
            file_put_contents($archivoOrigen, $contenidoDocx);

            $phpWord = IOFactory::load($archivoOrigen);
            $escritor = IOFactory::createWriter($phpWord, $formato);
            $escritor->save($archivoSalida);

            $salida = file_get_contents($archivoSalida);

            return $salida !== false ? $salida : null;
        } catch (\Throwable $e) {
            Log::warning('formatos: no se pudo convertir el documento generado.', [
                'formato' => $formato,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if (file_exists($archivoOrigen)) {
                unlink($archivoOrigen);
            }

            if (file_exists($archivoSalida)) {
                unlink($archivoSalida);
            }
        }
    }
}
