<?php

namespace App\Services\Reclutamiento;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unica puerta de entrada al almacenamiento de CVs de candidatos (disco
 * 'nas', config('reclutamiento.disk')). Espejo deliberado de
 * App\Services\Expedientes\DocumentoStorageService para el mismo disco NAS,
 * con rutas logicas propias de candidatos en vez de expedientes.
 */
class CvStorageService
{
    /**
     * Content-Type por extensión, para cuando el mime guardado falta o es
     * genérico (CVs viejos sin mime, archivos tomados de la bandeja del NAS
     * por SFTP, donde el disco no siempre sabe detectarlo). Sin esto el
     * navegador recibe application/octet-stream y descarga en vez de mostrar.
     */
    private const MIME_POR_EXTENSION = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpe' => 'image/jpeg',
        'jfif' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'avif' => 'image/avif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'mp4' => 'video/mp4',
        'm4v' => 'video/x-m4v',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        '3gp' => 'video/3gpp',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** Mimes que no dicen nada del contenido: se resuelven por extensión. */
    private const MIMES_GENERICOS = [
        'application/octet-stream',
        'application/zip',
        'application/x-empty',
        'binary/octet-stream',
    ];

    public function disco(): Filesystem
    {
        return Storage::disk(config('reclutamiento.disk'));
    }

    public function nombreInterno(string $nombreOriginal): string
    {
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $uuid = (string) Str::uuid();

        return $extension !== '' ? "{$uuid}.{$extension}" : $uuid;
    }

    public function rutaCv(int $candidatoId, string $nombreInterno): string
    {
        return "candidatos/{$candidatoId}/{$nombreInterno}";
    }

    public function guardar(UploadedFile $archivo, string $rutaDestino): string
    {
        $carpeta = dirname($rutaDestino);
        $nombre = basename($rutaDestino);

        $this->disco()->putFileAs($carpeta, $archivo, $nombre);

        return $rutaDestino;
    }

    /**
     * Guarda desde un flujo (p. ej. un archivo de la bandeja de CVs del NAS),
     * sin cargarlo completo en memoria.
     *
     * @param  resource  $flujo
     */
    public function guardarFlujo($flujo, string $rutaDestino): string
    {
        $this->disco()->writeStream($rutaDestino, $flujo);

        return $rutaDestino;
    }

    public function eliminar(string $ruta): void
    {
        if ($this->disco()->exists($ruta)) {
            $this->disco()->delete($ruta);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function respuesta(string $ruta, array $headers = []): StreamedResponse
    {
        /** @var FilesystemAdapter $adaptador */
        $adaptador = $this->disco();

        // Archivos privados del NAS: el navegador nunca debe adivinar el
        // tipo (un .pdf con HTML dentro no se ejecuta como página).
        return $adaptador->response($ruta, null, ['X-Content-Type-Options' => 'nosniff', ...$headers]);
    }

    /**
     * Respuesta con soporte real de HTTP Range (RFC 7233): un video pesado
     * en el NAS se puede adelantar/retroceder sin descargarlo completo ni
     * cargarlo en memoria de PHP (CLAUDE.md §8). `Storage::response()` de
     * Laravel ignora el encabezado `Range` y siempre manda el archivo
     * completo — por eso esta respuesta se construye a mano.
     *
     * Sin encabezado `Range` (primera carga del reproductor): 200 con el
     * archivo completo y `Accept-Ranges: bytes`, para que el navegador sepa
     * que puede pedir rangos en la siguiente petición.
     *
     * @param  array<string, string>  $headers
     */
    public function respuestaConRango(Request $request, string $ruta, array $headers = []): StreamedResponse
    {
        $disco = $this->disco();
        $tamano = (int) $disco->size($ruta);
        [$inicio, $fin] = $this->rango($request->header('Range'), $tamano);
        $largo = $fin - $inicio + 1;
        $esParcial = $request->hasHeader('Range') && $largo < $tamano;

        $respuesta = new StreamedResponse(function () use ($disco, $ruta, $inicio, $largo): void {
            $stream = $disco->readStream($ruta);

            if ($stream === null) {
                return;
            }

            if ($inicio > 0) {
                fseek($stream, $inicio);
            }

            $restante = $largo;

            while ($restante > 0 && ! feof($stream)) {
                $trozo = fread($stream, min(8192, $restante));

                if ($trozo === false) {
                    break;
                }

                echo $trozo;
                $restante -= strlen($trozo);
                flush();
            }

            fclose($stream);
        }, $esParcial ? 206 : 200);

        $respuesta->headers->replace([
            ...$headers,
            'Content-Type' => $headers['Content-Type'] ?? (string) ($disco->mimeType($ruta) ?: 'application/octet-stream'),
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $largo,
            'X-Content-Type-Options' => 'nosniff',
            ...($esParcial ? ['Content-Range' => "bytes {$inicio}-{$fin}/{$tamano}"] : []),
        ]);

        return $respuesta;
    }

    /**
     * Archivo del mini expediente (CV o evidencia) listo para el visor
     * embebido: `inline` por default (iframe/img/video), `attachment` solo
     * cuando se pide descargar. Content-Type correcto aunque el mime guardado
     * falte, nombre con acentos codificado según RFC 6266 y soporte de Range
     * para adelantar videos. Un archivo que ya no está en el NAS es 404, no
     * un error 500.
     */
    public function respuestaArchivo(
        Request $request,
        string $ruta,
        string $nombreOriginal,
        ?string $mimeGuardado = null,
        bool $descargar = false,
    ): StreamedResponse {
        if (! $this->disco()->exists($ruta)) {
            throw new NotFoundHttpException('El archivo ya no está disponible en el NAS.');
        }

        $nombre = $nombreOriginal !== '' ? $nombreOriginal : basename($ruta);
        $mime = $this->mimeDe($ruta, $nombre, $mimeGuardado);

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => $this->disposicion($descargar ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE, $nombre),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ];

        // Un SVG abierto directamente en una pestaña podría ejecutar scripts
        // con la sesión del usuario: se sirve aislado (como en <img>).
        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        return $this->respuestaConRango($request, $ruta, $headers);
    }

    /**
     * Mime real del archivo: el guardado al subirlo (detectado por contenido)
     * salvo que sea genérico; si no, por extensión del nombre original; al
     * final, lo que diga el disco.
     */
    public function mimeDe(string $ruta, ?string $nombreOriginal = null, ?string $mimeGuardado = null): string
    {
        $guardado = strtolower(trim((string) $mimeGuardado));

        if ($guardado !== '' && ! in_array($guardado, self::MIMES_GENERICOS, true)) {
            return $guardado;
        }

        foreach ([$nombreOriginal, $ruta] as $fuente) {
            $extension = strtolower(pathinfo((string) $fuente, PATHINFO_EXTENSION));

            if (isset(self::MIME_POR_EXTENSION[$extension])) {
                return self::MIME_POR_EXTENSION[$extension];
            }
        }

        $delDisco = $this->disco()->mimeType($ruta);

        return is_string($delDisco) && $delDisco !== '' ? $delDisco : 'application/octet-stream';
    }

    private function disposicion(string $tipo, string $nombre): string
    {
        // Respaldo ASCII sin "/", "\" ni "%" (requisito de HeaderUtils) para
        // navegadores viejos; el nombre real va en filename*= (UTF-8).
        $respaldo = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', Str::ascii($nombre));

        return HeaderUtils::makeDisposition($tipo, $nombre, $respaldo !== '' ? $respaldo : 'archivo');
    }

    /**
     * @return array{0: int, 1: int} [inicio, fin] inclusive.
     */
    private function rango(?string $encabezado, int $tamano): array
    {
        $ultimo = max($tamano - 1, 0);

        if ($encabezado === null || preg_match('/^bytes=(\d*)-(\d*)$/', $encabezado, $m) !== 1) {
            return [0, $ultimo];
        }

        if ($m[1] === '' && $m[2] === '') {
            return [0, $ultimo];
        }

        $inicio = $m[1] === '' ? max($tamano - (int) $m[2], 0) : (int) $m[1];
        $fin = $m[2] === '' || $m[1] === '' ? $ultimo : min((int) $m[2], $ultimo);

        if ($inicio > $fin || $inicio >= $tamano) {
            return [0, $ultimo];
        }

        return [$inicio, $fin];
    }
}
