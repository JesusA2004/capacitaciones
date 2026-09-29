<?php

namespace App\Services\Plantillas;

use App\Models\GeneratedDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Acceso seguro al archivo de un documento del motor de plantillas Word
 * editables (catálogo "Generados (Word)"), compartido por el panel web
 * (Rh\FormatoController) y la API móvil (Api\V1\Rh\FormatoController).
 *
 * Reglas:
 *  - Solo documentos de GeneratedDocument::desdePlantillaEditable() (DOCX
 *    creado desde una DocumentTemplate). Un recibo de nómina o un
 *    documento laboral en PDF jamás llega al conversor DOCX.
 *  - Se lee del disco que el propio documento registró
 *    (`$documento->disk`), no se asume config('plantillas.disk'). Un disco
 *    vacío o que no existe en config/filesystems.php se rechaza.
 *  - Un archivo que ya no existe (o un NAS que no responde) regresa null
 *    en vez de propagar UnableToRetrieveMetadata/TypeError: el
 *    controlador decide si responde 404 o un toast.
 */
class DocumentoWordGeneradoService
{
    public function esDelMotorWord(GeneratedDocument $documento): bool
    {
        return $documento->esDePlantillaEditable();
    }

    /**
     * Contenido DOCX del documento, o null si no pertenece al motor Word,
     * su disco/ruta no son válidos o el archivo físico ya no está.
     */
    public function contenido(GeneratedDocument $documento): ?string
    {
        $disco = $this->discoDisponible($documento);

        if ($disco === null) {
            return null;
        }

        try {
            $contenido = $disco->get($documento->path);
        } catch (Throwable $e) {
            $this->avisar($documento, $e);

            return null;
        }

        return is_string($contenido) && $contenido !== '' ? $contenido : null;
    }

    /**
     * Descarga del DOCX, o null si el archivo no está disponible.
     *
     * @param  array<string, string>  $headers
     */
    public function respuesta(GeneratedDocument $documento, array $headers = []): ?StreamedResponse
    {
        $disco = $this->discoDisponible($documento);

        if ($disco === null) {
            return null;
        }

        try {
            return $disco->response($documento->path, null, $headers);
        } catch (Throwable $e) {
            $this->avisar($documento, $e);

            return null;
        }
    }

    /**
     * Borra el archivo físico si existe; un NAS caído nunca impide borrar
     * el registro (el archivo huérfano queda en el log para sistemas).
     */
    public function eliminarArchivo(GeneratedDocument $documento): void
    {
        $disco = $this->discoDisponible($documento);

        if ($disco === null) {
            return;
        }

        try {
            $disco->delete($documento->path);
        } catch (Throwable $e) {
            $this->avisar($documento, $e);
        }
    }

    private function discoDisponible(GeneratedDocument $documento): ?FilesystemAdapter
    {
        if (! $this->esDelMotorWord($documento)) {
            return null;
        }

        $nombreDisco = (string) $documento->disk;
        $ruta = (string) $documento->path;

        if ($nombreDisco === '' || $ruta === '' || config(sprintf('filesystems.disks.%s', $nombreDisco)) === null) {
            return null;
        }

        try {
            $disco = Storage::disk($nombreDisco);

            return $disco->exists($ruta) ? $disco : null;
        } catch (Throwable $e) {
            $this->avisar($documento, $e);

            return null;
        }
    }

    private function avisar(GeneratedDocument $documento, Throwable $e): void
    {
        Log::warning('DocumentoWordGeneradoService: archivo del documento no disponible.', [
            'generated_document_id' => $documento->id,
            'disk' => $documento->disk,
            'error' => $e->getMessage(),
        ]);
    }
}
