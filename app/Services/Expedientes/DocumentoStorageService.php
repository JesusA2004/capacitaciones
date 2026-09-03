<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento de documentos de expediente
 * (disco 'nas', config('expedientes.disk')). Ningun controlador debe llamar
 * Storage::disk() directamente para estos archivos; espejo deliberado de
 * App\Services\Multimedia\MediaStorageService para el mismo disco NAS, pero
 * con las rutas logicas propias de documentos laborales en vez de video.
 */
class DocumentoStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('expedientes.disk'));
    }

    /**
     * Nombre de archivo interno no predecible (UUID): nunca se guarda ni se
     * expone el nombre original del archivo como nombre real en disco.
     */
    public function nombreInterno(string $nombreOriginal): string
    {
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $uuid = (string) Str::uuid();

        return $extension !== '' ? "{$uuid}.{$extension}" : $uuid;
    }

    public function rutaDocumento(int $usuarioId, string $nombreInterno): string
    {
        return "expedientes/{$usuarioId}/{$nombreInterno}";
    }

    /**
     * Ruta de la foto de perfil del colaborador (copiada desde el alta
     * digital al convertir, ver ConversionColaboradorService). Nunca se
     * expone esta ruta cruda al frontend: se sirve siempre a través de una
     * ruta protegida por policy (Rh\ExpedienteController::descargarFoto).
     */
    public function rutaFoto(int $usuarioId, string $nombreInterno): string
    {
        return "expedientes/{$usuarioId}/foto/{$nombreInterno}";
    }

    public function guardar(UploadedFile $archivo, string $rutaDestino): string
    {
        $carpeta = dirname($rutaDestino);
        $nombre = basename($rutaDestino);

        $this->disco()->putFileAs($carpeta, $archivo, $nombre);

        return $rutaDestino;
    }

    public function existe(string $ruta): bool
    {
        return $this->disco()->exists($ruta);
    }

    public function eliminar(string $ruta): void
    {
        if ($this->existe($ruta)) {
            $this->disco()->delete($ruta);
        }
    }

    public function hashSha256(string $ruta): string
    {
        $flujo = $this->disco()->readStream($ruta);
        $contexto = hash_init('sha256');

        while (! feof($flujo)) {
            $bloque = fread($flujo, 1024 * 1024);

            if ($bloque !== false) {
                hash_update($contexto, $bloque);
            }
        }
        fclose($flujo);

        return hash_final($contexto);
    }

    /**
     * Sube una nueva version de un documento de expediente: si ya existe una
     * version vigente del mismo tipo para el colaborador, la archiva y
     * enlaza la nueva como su sucesora (previous_version_id), igual que
     * Rh\EmployeeDocumentController::store. Unica fuente de esta logica para
     * que la subida normal al expediente y la subida de un formato firmado
     * (Rh\FormatoController::subirFirmado) no la dupliquen.
     */
    public function subirVersion(User $colaborador, DocumentType $tipo, UploadedFile $archivo, int $subidoPorId): EmployeeDocument
    {
        $anterior = EmployeeDocument::query()
            ->where('user_id', $colaborador->id)
            ->where('document_type_id', $tipo->id)
            ->where('status', '!=', EstadoDocumento::Archivado->value)
            ->orderByDesc('version')
            ->first();

        $nombreInterno = $this->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->rutaDocumento($colaborador->id, $nombreInterno);
        $this->guardar($archivo, $ruta);

        $documento = EmployeeDocument::create([
            'user_id' => $colaborador->id,
            'empresa_id' => $colaborador->sucursalPrincipal?->empresa_id,
            'sucursal_id' => $colaborador->sucursal_principal_id,
            'document_type_id' => $tipo->id,
            'disk' => config('expedientes.disk'),
            'path' => $ruta,
            'original_name' => $archivo->getClientOriginalName(),
            'stored_name' => $nombreInterno,
            'mime' => $archivo->getClientMimeType(),
            'extension' => $archivo->getClientOriginalExtension(),
            'size' => $archivo->getSize(),
            'hash' => $this->hashSha256($ruta),
            'version' => $anterior ? $anterior->version + 1 : 1,
            'previous_version_id' => $anterior?->id,
            'status' => EstadoDocumento::EnRevision->value,
            'uploaded_by' => $subidoPorId,
        ]);

        $anterior?->update(['status' => EstadoDocumento::Archivado->value]);

        return $documento;
    }

    /**
     * Respuesta HTTP en streaming (visor/descarga). El controlador que la
     * invoca ya valido el permiso antes de llegar aqui; esta capa solo sirve
     * bytes desde la ruta logica, nunca expone la ruta fisica al cliente.
     *
     * @param  array<string, string>  $headers
     */
    public function respuesta(string $ruta, array $headers = []): StreamedResponse
    {
        /** @var FilesystemAdapter $adaptador */
        $adaptador = $this->disco();

        return $adaptador->response($ruta, null, $headers);
    }
}
