<?php

namespace App\Services\Plantillas;

use App\Models\Candidato;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;

/**
 * Genera un DOCX precargado a partir de una DocumentTemplate, reemplazando
 * los placeholders {{...}} con los datos resueltos por PlaceholderResolver.
 * Usa archivos temporales locales porque PhpWord\TemplateProcessor necesita
 * una ruta de archivo real (ZipArchive), sin asumir que el disco 'nas' es
 * local: siempre lee/escribe a traves de PlantillaStorageService.
 */
class PlantillaDocumentoService
{
    public function __construct(
        private readonly PlantillaStorageService $storage,
        private readonly PlaceholderResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     * @return array{contenido: string, nombre_interno: string}
     */
    public function generar(DocumentTemplate $plantilla, User|Candidato|null $sujeto, array $extra = []): array
    {
        $valores = $this->resolver->resolver($sujeto, $extra);

        $archivoOrigen = sys_get_temp_dir().'/'.Str::uuid().'.docx';
        file_put_contents($archivoOrigen, $this->storage->disco()->get($plantilla->path));

        try {
            $procesador = new TemplateProcessor($archivoOrigen);
            // Placeholders documentados para RH como {{clave}} (ver
            // claude/formatos/placeholders/PLACEHOLDERS.md), no el
            // delimitador ${clave} que trae PhpWord por defecto.
            $procesador->setMacroChars('{{', '}}');

            foreach ($valores as $clave => $valor) {
                $procesador->setValue($clave, htmlspecialchars($valor, ENT_QUOTES | ENT_XML1));
            }

            $archivoSalida = sys_get_temp_dir().'/'.Str::uuid().'.docx';
            $procesador->saveAs($archivoSalida);

            $contenido = file_get_contents($archivoSalida);
            unlink($archivoSalida);

            return [
                'contenido' => $contenido !== false ? $contenido : '',
                'nombre_interno' => $this->storage->nombreInterno("{$plantilla->tipo->value}.docx"),
            ];
        } finally {
            if (file_exists($archivoOrigen)) {
                unlink($archivoOrigen);
            }
        }
    }

    /**
     * Placeholders {{clave}} que realmente aparecen en el DOCX de la
     * plantilla (leyendo word/document.xml directamente del zip), para
     * mostrar en el catálogo de formatos qué variables usa cada una sin
     * mantener una lista aparte a mano. Nunca lanza excepción: si el
     * archivo no se puede leer regresa una lista vacía.
     *
     * Cacheada por plantilla+version (invalida sola si RH sube una nueva
     * version del DOCX) porque el catálogo la llama para cada plantilla
     * activa en cada carga de /rh/formatos, y leer el archivo del disco
     * 'nas' en cada visita es innecesario si el contenido no cambió.
     *
     * @return list<string>
     */
    public function variablesEnPlantilla(DocumentTemplate $plantilla): array
    {
        return Cache::remember(
            "plantillas:{$plantilla->id}:v{$plantilla->version}:variables",
            now()->addDay(),
            fn () => $this->escanearVariables($plantilla),
        );
    }

    /**
     * @return list<string>
     */
    private function escanearVariables(DocumentTemplate $plantilla): array
    {
        $archivoTemporal = sys_get_temp_dir().'/'.Str::uuid().'.docx';

        try {
            file_put_contents($archivoTemporal, $this->storage->disco()->get($plantilla->path));

            $zip = new ZipArchive;

            if ($zip->open($archivoTemporal) !== true) {
                return [];
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false) {
                return [];
            }

            // Word puede partir "{{clave}}" en varias <w:t> con formato
            // distinto por letra; se quitan las etiquetas XML antes del
            // regex para no perder placeholders con "negritas a medias".
            $textoPlano = strip_tags($xml);
            preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $textoPlano, $coincidencias);

            return array_values(array_unique($coincidencias[1]));
        } catch (\Throwable) {
            return [];
        } finally {
            if (file_exists($archivoTemporal)) {
                unlink($archivoTemporal);
            }
        }
    }
}
