<?php

namespace App\Services\Plantillas;

use App\Models\Candidato;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;

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
}
