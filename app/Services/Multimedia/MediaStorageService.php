<?php

namespace App\Services\Multimedia;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento multimedia (disco 'nas'). Nunca
 * se expone al frontend la ruta fisica real: solo se manejan identificadores
 * de recurso y rutas logicas dentro del disco.
 *
 * En local (NAS_DRIVER=local, incluida la variante de produccion donde el
 * NAS esta montado por NFS como carpeta local del servidor) FFmpeg/FFprobe
 * pueden operar directamente sobre la ruta absoluta del disco. Si el disco
 * usa un driver remoto (por ejemplo NAS_DRIVER=sftp), los archivos se
 * descargan primero a un temporal local para procesarlos y el resultado se
 * vuelve a subir; ver rutaLocalParaProcesar()/publicarResultadoProcesado().
 */
class MediaStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('media.disk'));
    }

    public function esDiscoLocal(): bool
    {
        return config('filesystems.disks.'.config('media.disk').'.driver', 'local') === 'local';
    }

    /**
     * Nombre de archivo interno no predecible (UUID), para no exponer ni
     * depender del nombre original del archivo.
     */
    public function nombreInterno(string $nombreOriginal): string
    {
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $uuid = (string) Str::uuid();

        return $extension !== '' ? "{$uuid}.{$extension}" : $uuid;
    }

    public function rutaOriginal(string $nombreInterno): string
    {
        return "originales/{$nombreInterno}";
    }

    public function rutaTemporal(string $nombreInterno): string
    {
        return "temporales/{$nombreInterno}";
    }

    public function rutaHlsCarpeta(string $nombreInterno): string
    {
        return "hls/{$nombreInterno}";
    }

    public function rutaMiniatura(string $nombreInterno): string
    {
        return "miniaturas/{$nombreInterno}.jpg";
    }

    public function rutaDocumento(string $nombreInterno): string
    {
        return "documentos/{$nombreInterno}";
    }

    /**
     * Guarda un archivo subido (o un stream/ruta local) en la ruta logica
     * indicada, sin cargarlo completo en memoria.
     */
    public function guardar(UploadedFile|string $archivo, string $rutaDestino): string
    {
        $carpeta = dirname($rutaDestino);
        $nombre = basename($rutaDestino);

        if ($archivo instanceof UploadedFile) {
            $this->disco()->putFileAs($carpeta, $archivo, $nombre);
        } else {
            $flujo = fopen($archivo, 'r');

            if ($flujo === false) {
                throw new \RuntimeException("No se pudo abrir el archivo origen: {$archivo}");
            }

            $this->disco()->put($rutaDestino, $flujo);
            fclose($flujo);
        }

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

    public function eliminarCarpeta(string $ruta): void
    {
        $this->disco()->deleteDirectory($ruta);
    }

    public function tamano(string $ruta): int
    {
        return $this->disco()->size($ruta);
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
     * Ruta absoluta local que FFmpeg/FFprobe pueden abrir directamente. Solo
     * valida cuando el disco usa el driver "local" (incluye NFS montado como
     * carpeta local). Para drivers remotos, usar descargarATemporal().
     */
    public function rutaLocalAbsoluta(string $ruta): string
    {
        /** @var FilesystemAdapter $adaptador */
        $adaptador = $this->disco();

        return $adaptador->path($ruta);
    }

    /**
     * Respuesta HTTP en streaming para un archivo del disco multimedia (por
     * ejemplo, un segmento .ts). Funciona igual sin importar el driver, ya
     * que Laravel la implementa leyendo por streaming, no copiando a memoria.
     *
     * En produccion detras de Nginx, esto puede sustituirse por un header
     * X-Accel-Redirect para que el propio Nginx sirva el archivo sin pasar
     * por PHP (ver docs/PROCESAMIENTO_VIDEO.md); no es necesario para que la
     * funcionalidad sea correcta, solo para aligerar la carga del servidor.
     *
     * @param  array<string, string>  $headers
     */
    public function respuesta(string $ruta, array $headers = []): StreamedResponse
    {
        /** @var FilesystemAdapter $adaptador */
        $adaptador = $this->disco();

        return $adaptador->response($ruta, null, $headers);
    }

    /**
     * Descarga una ruta del disco multimedia a un archivo temporal local del
     * servidor, para poder procesarla con FFmpeg cuando el disco no es local.
     */
    public function descargarATemporal(string $ruta): string
    {
        $destino = tempnam(sys_get_temp_dir(), 'media_');

        if ($destino === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal para procesar el recurso.');
        }

        $flujoOrigen = $this->disco()->readStream($ruta);
        $flujoDestino = fopen($destino, 'w');

        if ($flujoDestino === false) {
            throw new \RuntimeException('No se pudo abrir el archivo temporal para escritura.');
        }

        stream_copy_to_stream($flujoOrigen, $flujoDestino);
        fclose($flujoDestino);
        fclose($flujoOrigen);

        return $destino;
    }
}
