<?php

namespace App\Services\Reclutamiento;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento de CVs de candidatos (disco
 * 'nas', config('reclutamiento.disk')). Espejo deliberado de
 * App\Services\Expedientes\DocumentoStorageService para el mismo disco NAS,
 * con rutas logicas propias de candidatos en vez de expedientes.
 */
class CvStorageService
{
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
