<?php

namespace App\Services\DocumentosLaborales;

use App\Enums\MotorPlantilla;
use App\Enums\TipoPlantillaDocumento;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Plantillas\PlantillaStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carga/configuración de plantillas documentales versionadas por clave
 * (reutiliza la tabla document_templates). Publicar una versión nueva de
 * una clave desactiva las anteriores: los documentos ya emitidos conservan
 * su plantilla/versión y su snapshot, así que nunca cambian.
 */
class PlantillaDocumentalService
{
    public function __construct(
        private readonly PlantillaStorageService $storage,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Validado por GuardarPlantillaDocumentalRequest.
     */
    public function crearVersion(array $datos, ?UploadedFile $archivoDocx, User $actor): DocumentTemplate
    {
        $motor = MotorPlantilla::from($datos['motor']);

        if ($motor === MotorPlantilla::Docx && $archivoDocx === null) {
            throw ValidationException::withMessages(['archivo' => 'Las plantillas DOCX requieren el archivo Word.']);
        }

        if ($motor === MotorPlantilla::Html && trim((string) ($datos['contenido_html'] ?? '')) === '') {
            throw ValidationException::withMessages(['contenido_html' => 'Captura el contenido HTML de la plantilla.']);
        }

        if ($motor === MotorPlantilla::PdfOverlay && empty($datos['official_format_id'])) {
            throw ValidationException::withMessages(['official_format_id' => 'Selecciona el formato oficial PDF.']);
        }

        $ruta = null;

        if ($archivoDocx !== null) {
            $ruta = $this->storage->rutaPlantilla($this->storage->nombreInterno($archivoDocx->getClientOriginalName()));
            $this->storage->guardar($archivoDocx, $ruta);
        }

        try {
            $plantilla = DB::transaction(function () use ($datos, $motor, $archivoDocx, $ruta, $actor): DocumentTemplate {
                // Bloquea las versiones existentes de la clave: dos cargas
                // simultáneas no pueden calcular el mismo número de versión.
                $maxima = (int) DocumentTemplate::withTrashed()->where('clave', $datos['clave'])->lockForUpdate()->max('version');
                $activa = (bool) ($datos['activo'] ?? true);

                if ($activa) {
                    DocumentTemplate::query()->where('clave', $datos['clave'])->update(['activo' => false]);
                }
                $plantilla = DocumentTemplate::query()->create([
                    'clave' => $datos['clave'],
                    'nombre' => $datos['nombre'],
                    'descripcion' => $datos['descripcion'] ?? null,
                    'tipo' => $datos['tipo'] ?? TipoPlantillaDocumento::Otro->value,
                    'categoria' => $datos['categoria'] ?? config("contratos.plantillas.{$datos['clave']}.categoria"),
                    'motor' => $motor,
                    'contenido_html' => $motor === MotorPlantilla::Html ? $datos['contenido_html'] : null,
                    'official_format_id' => $motor === MotorPlantilla::PdfOverlay ? $datos['official_format_id'] : null,
                    'document_type_id' => $datos['document_type_id'] ?? null,
                    'empresa_id' => $datos['empresa_id'] ?? null,
                    'disk' => $ruta !== null ? config('plantillas.disk') : null,
                    'path' => $ruta,
                    'original_name' => $archivoDocx?->getClientOriginalName(),
                    'mime' => $archivoDocx?->getClientMimeType(),
                    'size' => $archivoDocx?->getSize() ?: null,
                    'version' => $maxima + 1,
                    'activo' => $activa,
                    'requiere_firma_digital' => (bool) ($datos['requiere_firma_digital'] ?? false),
                    'requiere_impresion' => (bool) ($datos['requiere_impresion'] ?? false),
                    'requiere_firma_fisica' => (bool) ($datos['requiere_firma_fisica'] ?? false),
                    'requiere_huella' => (bool) ($datos['requiere_huella'] ?? false),
                    'requiere_testigos' => (bool) ($datos['requiere_testigos'] ?? false),
                    'created_by' => $actor->id,
                ]);

                return $plantilla;
            });
        } catch (\Throwable $e) {
            if ($ruta !== null) {
                $this->storage->eliminar($ruta);
            }

            throw $e;
        }

        $this->auditoria->registrar('plantilla_documental_version', $plantilla, $actor, ['clave' => $plantilla->clave, 'version' => $plantilla->version]);

        return $plantilla;
    }

    /**
     * Cambia metadatos/banderas sin tocar el contenido (el contenido solo
     * cambia publicando una versión nueva, para no alterar documentos ya
     * emitidos con esta versión).
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(DocumentTemplate $plantilla, array $datos, User $actor): DocumentTemplate
    {
        DB::transaction(function () use ($plantilla, $datos): void {
            if (($datos['activo'] ?? null) === true && $plantilla->clave !== null) {
                DocumentTemplate::query()->where('clave', $plantilla->clave)->where('id', '!=', $plantilla->id)->update(['activo' => false]);
            }

            $plantilla->update(array_intersect_key($datos, array_flip([
                'nombre', 'descripcion', 'categoria', 'document_type_id', 'activo',
                'requiere_firma_digital', 'requiere_impresion', 'requiere_firma_fisica', 'requiere_huella', 'requiere_testigos',
            ])));
        });

        $this->auditoria->registrar('plantilla_documental_actualizada', $plantilla, $actor, ['clave' => $plantilla->clave, 'cambios' => array_keys($datos)]);

        return $plantilla->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(DocumentTemplate $plantilla): array
    {
        return [
            'id' => $plantilla->id,
            'clave' => $plantilla->clave,
            'nombre' => $plantilla->nombre,
            'descripcion' => $plantilla->descripcion,
            'categoria' => $plantilla->categoria?->value,
            'motor' => $plantilla->motor->value,
            'version' => $plantilla->version,
            'activo' => $plantilla->activo,
            'tipo_documento_id' => $plantilla->document_type_id,
            'official_format_id' => $plantilla->official_format_id,
            'tiene_archivo' => $plantilla->path !== null,
            'requiere_firma_digital' => $plantilla->requiere_firma_digital,
            'requiere_impresion' => $plantilla->requiere_impresion,
            'requiere_firma_fisica' => $plantilla->requiere_firma_fisica,
            'requiere_huella' => $plantilla->requiere_huella,
            'requiere_testigos' => $plantilla->requiere_testigos,
        ];
    }
}
