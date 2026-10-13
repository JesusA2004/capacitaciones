<?php

namespace App\Services\Plantillas;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
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
    public function generar(DocumentTemplate $plantilla, Colaborador|Candidato|null $sujeto, array $extra = []): array
    {
        $valores = $this->resolver->resolver($sujeto, $extra);

        return [
            'contenido' => $this->generarConValores($plantilla, $valores),
            'nombre_interno' => $this->storage->nombreInterno("{$plantilla->tipo->value}.docx"),
        ];
    }

    /**
     * Sustituye los {{placeholders}} del DOCX de la plantilla con valores ya
     * resueltos (el motor documental los resuelve una sola vez y guarda ese
     * mismo arreglo como snapshot, ver
     * App\Services\DocumentosLaborales\MotorDocumentalService).
     *
     * @param  array<string, string>  $valores
     *
     * @throws RuntimeException Si la plantilla no tiene archivo DOCX cargado.
     */
    public function generarConValores(DocumentTemplate $plantilla, array $valores): string
    {
        if ($plantilla->path === null) {
            throw new RuntimeException("La plantilla «{$plantilla->nombre}» no tiene archivo DOCX cargado.");
        }

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

            return $contenido !== false ? $contenido : '';
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
        $clave = "plantillas:{$plantilla->id}:v{$plantilla->version}:variables";
        $enCache = Cache::get($clave);

        if (is_array($enCache)) {
            return array_values(array_filter($enCache, 'is_string'));
        }

        // Un fallo de lectura (archivo bloqueado un instante, NAS lento) se
        // reintenta una vez y NUNCA se cachea: una lista vacía por error
        // haría que una variable requerida dejara de bloquear el formato
        // durante todo un día sin que nadie se entere.
        $variables = $this->escanearVariables($plantilla) ?? $this->escanearVariables($plantilla);

        if ($variables === null) {
            return [];
        }

        Cache::put($clave, $variables, now()->addDay());

        return $variables;
    }

    /**
     * @return list<string>|null Null si el archivo no se pudo leer (queda en el log).
     */
    private function escanearVariables(DocumentTemplate $plantilla): ?array
    {
        if ($plantilla->path === null) {
            return [];
        }

        $archivoTemporal = sys_get_temp_dir().'/'.Str::uuid().'.docx';

        try {
            $contenido = $this->storage->disco()->get($plantilla->path);

            if ($contenido === null || file_put_contents($archivoTemporal, $contenido) === false) {
                Log::warning('PlantillaDocumentoService: no se pudo leer el DOCX de la plantilla para detectar sus variables.', ['plantilla_id' => $plantilla->id]);

                return null;
            }

            $zip = new ZipArchive;

            if ($zip->open($archivoTemporal) !== true) {
                Log::warning('PlantillaDocumentoService: el DOCX de la plantilla no se pudo abrir como ZIP.', ['plantilla_id' => $plantilla->id]);

                return null;
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
        } catch (\Throwable $e) {
            Log::warning('PlantillaDocumentoService: falló la detección de variables de la plantilla.', ['plantilla_id' => $plantilla->id, 'error' => $e->getMessage()]);

            return null;
        } finally {
            if (file_exists($archivoTemporal)) {
                unlink($archivoTemporal);
            }
        }
    }
}
