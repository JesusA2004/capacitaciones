<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoDocumento;
use App\Jobs\ProcesarDocumentoPersonalJob;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Documentos\DocumentExtractionService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Unica puerta de entrada al almacenamiento de documentos de expediente
 * (disco 'nas', config('expedientes.disk')). Ningun controlador debe llamar
 * Storage::disk() directamente para estos archivos; espejo deliberado de
 * App\Services\Multimedia\MediaStorageService para el mismo disco NAS, pero
 * con las rutas logicas propias de documentos laborales en vez de video.
 *
 * Las rutas son legibles por diseño (docs/ESTRUCTURA_EXPEDIENTES_NAS.md):
 *
 *   expedientes/{empresa}/{sucursal}/{numero_empleado - nombre}/{tipo} - v{n}.{ext}
 *
 * Esto NO depende de que el nombre sea "secreto" para la seguridad: el disco
 * NAS nunca se expone al frontend (solo se conocen IDs de EmployeeDocument),
 * las descargas pasan por un endpoint protegido por policy, y disk/path
 * siguen ocultos ($hidden en el modelo). El nombre humano es puramente para
 * que alguien en Synology/File Station pueda entender qué es cada archivo.
 */
class DocumentoStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('expedientes.disk'));
    }

    /**
     * Limpia un segmento de ruta (nombre de empresa/sucursal/colaborador/
     * documento) para que sea seguro y legible en cualquier sistema de
     * archivos: sin separadores de ruta, sin ".." (path traversal), sin
     * caracteres de control ni los pocos caracteres inválidos en Windows
     * (por si el NAS se monta también desde ahí), espacios colapsados y sin
     * acentos (para evitar problemas de codificación entre Windows/Linux/SMB).
     * Nunca genera un slug (mr-lana-mexico): mantiene mayúsculas y espacios.
     */
    public function sanitizarSegmento(string $valor): string
    {
        $valor = Str::ascii($valor);
        $valor = str_replace(['/', '\\'], ' - ', $valor);
        $valor = preg_replace('/\.\.+/', '.', $valor) ?? $valor;
        $valor = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/', '', $valor) ?? $valor;
        $valor = preg_replace('/\s+/', ' ', $valor) ?? $valor;
        $valor = trim($valor, " .\t\n\r\0\x0B");

        return $valor !== '' ? $valor : 'SIN-NOMBRE';
    }

    /**
     * "{numero_empleado} - {nombre completo}", único por diseño: el número
     * de empleado (o "SIN-NUMERO-{id}" si no tiene) resuelve homónimos.
     */
    public function carpetaColaborador(User $colaborador): string
    {
        $numero = $colaborador->numero_empleado !== null && trim($colaborador->numero_empleado) !== ''
            ? trim($colaborador->numero_empleado)
            : "SIN-NUMERO-{$colaborador->id}";

        return $this->sanitizarSegmento("{$numero} - {$colaborador->nombreCompleto()}");
    }

    /**
     * Carpeta del colaborador dentro del NAS: expedientes/{empresa}/{sucursal}/{numero - nombre},
     * calculada en FRESCO a partir del estado actual del colaborador (nunca
     * lee ni escribe `expediente_storage_path`). Uso: (a) primera asignación
     * en asignarRutaBaseColaborador(), (b) ExpedienteRelocationService para
     * saber a dónde debería moverse un expediente. Cualquier otro caso debe
     * usar la ruta PERSISTIDA (ver rutaBaseColaboradorPersistida()) — nunca
     * esta directamente, o una nueva versión "seguiría" a un cambio de
     * sucursal/nombre y partiría el expediente en dos carpetas (ver
     * CLAUDE.md y docs/ESTRUCTURA_EXPEDIENTES_NAS.md).
     *
     * Si falta empresa/sucursal (colaborador incompleto) usa un placeholder
     * explícito y lo reporta en el log — nunca lo oculta en silencio (ver
     * CLAUDE.md, "una fila/columna que no cuadra se reporta explícitamente").
     */
    public function rutaBaseColaborador(User $colaborador): string
    {
        $empresaNombre = $colaborador->sucursalPrincipal?->empresa?->nombre;
        $sucursalNombre = $colaborador->sucursalPrincipal?->nombre;

        if ($empresaNombre === null) {
            Log::warning('expedientes: colaborador sin empresa al construir ruta NAS.', ['user_id' => $colaborador->id]);
        }

        if ($sucursalNombre === null) {
            Log::warning('expedientes: colaborador sin sucursal al construir ruta NAS.', ['user_id' => $colaborador->id]);
        }

        return implode('/', [
            'expedientes',
            $this->sanitizarSegmento($empresaNombre ?? 'SIN EMPRESA'),
            $this->sanitizarSegmento($sucursalNombre ?? 'SIN SUCURSAL'),
            $this->carpetaColaborador($colaborador),
        ]);
    }

    /**
     * Ruta base PERSISTIDA del expediente, sin efectos secundarios: si el
     * colaborador ya tiene `expediente_storage_path` la regresa tal cual
     * (aunque ya no coincida con su empresa/sucursal/nombre actuales — ver
     * docs/ESTRUCTURA_EXPEDIENTES_NAS.md, "cambio de nombre/sucursal no
     * mueve el expediente"); si todavía no tiene, calcula (sin guardar) la
     * que se le asignaría. Úsala en cualquier lugar de solo-lectura/preview
     * (planificación de migración, reportes) donde escribir en BD sería
     * incorrecto — por ejemplo, un dry run nunca debe tocar la BD.
     */
    public function rutaBaseColaboradorPersistida(User $colaborador): string
    {
        $actual = $colaborador->expediente_storage_path;

        if ($actual !== null && trim($actual) !== '') {
            return $actual;
        }

        return $this->rutaBaseColaborador($colaborador);
    }

    /**
     * Igual que rutaBaseColaboradorPersistida(), pero si el colaborador
     * todavía no tiene ruta asignada, la calcula Y LA GUARDA de una vez en
     * `expediente_storage_path` — esta es la única función que debe usarse
     * al momento de escribir un archivo real (subirVersion(), foto de
     * perfil), para que la identidad de almacenamiento quede fijada desde el
     * primer documento y nunca se recalcule en subidas futuras.
     */
    public function asignarRutaBaseColaborador(User $colaborador): string
    {
        $actual = $colaborador->expediente_storage_path;

        if ($actual !== null && trim($actual) !== '') {
            return $actual;
        }

        $ruta = $this->rutaBaseColaborador($colaborador);
        $colaborador->forceFill(['expediente_storage_path' => $ruta])->save();

        return $ruta;
    }

    /**
     * "{DocumentType->nombre} - v{version}.{extension}" — nunca UUID: el
     * sistema ya sabe qué documento es (DocumentType), no hace falta
     * adivinar a partir del nombre que subió el usuario (original_name se
     * conserva aparte, solo para auditoría).
     */
    public function nombreDocumento(DocumentType $tipo, int $version, ?string $extension): string
    {
        $nombre = $this->sanitizarSegmento("{$tipo->nombre} - v{$version}");
        $extension = strtolower(trim((string) $extension, ". \t\n\r\0\x0B"));

        return $extension !== '' ? "{$nombre}.{$extension}" : $nombre;
    }

    /**
     * $persistirRutaBase=false se usa solo desde planificación/preview (p.
     * ej. ExpedienteNasOrganizacionService en dry run): calcula la ruta que
     * tendría el documento sin asignar/guardar `expediente_storage_path` si
     * todavía no existe. Cualquier subida real de archivo debe dejarlo en
     * true (default) para fijar la identidad de almacenamiento.
     */
    public function rutaDocumento(User $colaborador, DocumentType $tipo, int $version, ?string $extension, bool $persistirRutaBase = true): string
    {
        $base = $persistirRutaBase
            ? $this->asignarRutaBaseColaborador($colaborador)
            : $this->rutaBaseColaboradorPersistida($colaborador);

        return $base.'/'.$this->nombreDocumento($tipo, $version, $extension);
    }

    public function nombreFoto(?string $extension): string
    {
        $extension = strtolower(trim((string) $extension, ". \t\n\r\0\x0B"));

        return $extension !== '' ? "Foto de perfil.{$extension}" : 'Foto de perfil';
    }

    /**
     * Ruta de la foto de perfil del colaborador (copiada desde el alta
     * digital al convertir, ver ConversionColaboradorService). Nunca se
     * expone esta ruta cruda al frontend: se sirve siempre a través de una
     * ruta protegida por policy (Rh\ExpedienteController::descargarFoto).
     */
    public function rutaFoto(User $colaborador, ?string $extension, bool $persistirRutaBase = true): string
    {
        $base = $persistirRutaBase
            ? $this->asignarRutaBaseColaborador($colaborador)
            : $this->rutaBaseColaboradorPersistida($colaborador);

        return $base.'/foto/'.$this->nombreFoto($extension);
    }

    /**
     * Guarda el archivo y verifica que realmente quedó escrito: el disco
     * 'nas' tiene `throw=false` (config/filesystems.php), así que un fallo
     * de escritura (permmisos, disco lleno, NAS caído) no lanza excepción
     * por sí solo — sin esta verificación explícita, la fila de BD podría
     * crearse apuntando a un archivo que nunca se guardó.
     *
     * Nunca sobrescribe: si el destino ya existe (colisión de versión por
     * una carrera entre dos subidas concurrentes, o un bug de cálculo de
     * versión) se rechaza explícitamente en vez de hacer putFileAs() encima
     * y perder el archivo anterior en silencio (ver CLAUDE.md).
     */
    public function guardar(UploadedFile $archivo, string $rutaDestino): string
    {
        if ($this->existe($rutaDestino)) {
            throw new RuntimeException("Ya existe un archivo en el NAS en «{$rutaDestino}»; no se sobrescribe. Probablemente la versión calculada ya estaba en uso.");
        }

        $carpeta = dirname($rutaDestino);
        $nombre = basename($rutaDestino);

        $this->disco()->putFileAs($carpeta, $archivo, $nombre);

        if (! $this->existe($rutaDestino)) {
            throw new RuntimeException("No se pudo guardar el archivo en el NAS: {$rutaDestino}");
        }

        return $rutaDestino;
    }

    /**
     * Lee el estado real del disco en cada llamada (nunca cachea el
     * resultado): dos llamadas consecutivas pueden dar respuestas distintas
     * si algo más tocó el archivo entre medio (otro proceso, o esta misma
     * migración copiando/borrando), y el código de migración depende de eso.
     *
     * @phpstan-impure
     */
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
     *
     * Atómico frente al archivo: si la fila de BD falla, el archivo recién
     * guardado se elimina (nunca queda huérfano) y NUNCA se toca el archivo
     * de la versión anterior. La versión se calcula sobre el máximo
     * histórico (withTrashed, no solo la vigente): reutilizar un número de
     * versión que alguna vez existió pisaría el nombre de un archivo que
     * pudo seguir vivo en el NAS aunque su fila esté borrada lógicamente.
     */
    public function subirVersion(User $colaborador, DocumentType $tipo, UploadedFile $archivo, int $subidoPorId): EmployeeDocument
    {
        $anterior = EmployeeDocument::query()
            ->where('user_id', $colaborador->id)
            ->where('document_type_id', $tipo->id)
            ->where('status', '!=', EstadoDocumento::Archivado->value)
            ->orderByDesc('version')
            ->first();

        $maximoHistorico = (int) EmployeeDocument::withTrashed()
            ->where('user_id', $colaborador->id)
            ->where('document_type_id', $tipo->id)
            ->max('version');

        $version = $maximoHistorico + 1;
        $ruta = $this->rutaDocumento($colaborador, $tipo, $version, $archivo->getClientOriginalExtension());
        $this->guardar($archivo, $ruta);

        try {
            $documento = DB::transaction(function () use ($colaborador, $tipo, $archivo, $ruta, $version, $anterior, $subidoPorId) {
                $documento = EmployeeDocument::create([
                    'user_id' => $colaborador->id,
                    'empresa_id' => $colaborador->sucursalPrincipal?->empresa_id,
                    'sucursal_id' => $colaborador->sucursal_principal_id,
                    'document_type_id' => $tipo->id,
                    'disk' => config('expedientes.disk'),
                    'path' => $ruta,
                    'original_name' => $archivo->getClientOriginalName(),
                    'stored_name' => basename($ruta),
                    'mime' => $archivo->getClientMimeType(),
                    'extension' => $archivo->getClientOriginalExtension(),
                    'size' => $archivo->getSize(),
                    'hash' => $this->hashSha256($ruta),
                    'version' => $version,
                    'previous_version_id' => $anterior?->id,
                    'status' => EstadoDocumento::EnRevision->value,
                    'uploaded_by' => $subidoPorId,
                ]);

                $anterior?->update(['status' => EstadoDocumento::Archivado->value]);

                return $documento;
            });
        } catch (Throwable $e) {
            // El archivo recién guardado nunca llegó a tener fila en BD:
            // eliminarlo (nunca la version anterior, que sigue vigente).
            $this->eliminar($ruta);

            throw $e;
        }

        // Extraccion automatica de datos personales (docs/DOCUMENT_EXTRACTION.md):
        // solo para tipos de documento donde tiene sentido intentarlo (INE,
        // CURP, RFC, NSS, acta de nacimiento, comprobante de domicilio), y
        // siempre en cola para no bloquear este request de subida.
        if (DocumentExtractionService::tipoElegible($tipo->clave)) {
            ProcesarDocumentoPersonalJob::dispatch($documento);
        }

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
