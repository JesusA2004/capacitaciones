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
     * Carpeta temporal de una sesion de carga por bloques (Fase 9, ver
     * App\Services\Multimedia\CargaResumibleService). Cada bloque se guarda
     * como un archivo independiente numerado para poder recibirlos fuera de
     * orden y reintentar uno solo sin repetir los demas.
     */
    public function rutaCargaTemporal(string $identificador): string
    {
        return "temporales/cargas/{$identificador}";
    }

    public function rutaBloque(string $identificador, int $numeroBloque): string
    {
        return $this->rutaCargaTemporal($identificador)."/{$numeroBloque}.part";
    }

    /**
     * Concatena, en orden, los bloques ya recibidos de una carga en la ruta
     * final indicada. No carga el archivo completo en memoria: copia cada
     * bloque por streaming.
     */
    public function ensamblarBloques(string $identificador, int $totalBloques, string $rutaDestino): void
    {
        $disco = $this->disco();

        // Se ensambla primero en un temporal local (no en memoria) y se sube
        // una sola vez al disco final, para no mantener abiertas N conexiones
        // de escritura contra un disco remoto (p. ej. SFTP) a la vez.
        $temporalLocal = tempnam(sys_get_temp_dir(), 'ensamblado_');

        if ($temporalLocal === false) {
            throw new \RuntimeException('No se pudo preparar el archivo temporal de ensamblado.');
        }

        $recursoDestino = fopen($temporalLocal, 'wb');

        if ($recursoDestino === false) {
            throw new \RuntimeException('No se pudo abrir el archivo temporal de ensamblado.');
        }

        try {
            for ($numero = 0; $numero < $totalBloques; $numero++) {
                $rutaBloque = $this->rutaBloque($identificador, $numero);

                if (! $disco->exists($rutaBloque)) {
                    throw new \RuntimeException("Falta el bloque {$numero} de {$totalBloques} para ensamblar la carga.");
                }

                $flujoBloque = $disco->readStream($rutaBloque);
                stream_copy_to_stream($flujoBloque, $recursoDestino);
                fclose($flujoBloque);
            }

            fclose($recursoDestino);

            $flujoLectura = fopen($temporalLocal, 'rb');

            if ($flujoLectura === false) {
                throw new \RuntimeException('No se pudo leer el archivo ensamblado.');
            }

            $disco->put($rutaDestino, $flujoLectura);

            if (is_resource($flujoLectura)) {
                fclose($flujoLectura);
            }
        } finally {
            if (is_resource($recursoDestino)) {
                fclose($recursoDestino);
            }

            @unlink($temporalLocal);
        }
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
     * Cuando `config('media.x_accel_redirect')` está activo (solo tiene
     * sentido con el disco local montado también por Nginx, ver
     * deploy/nginx/multimedia.conf), no se transmite el archivo desde PHP:
     * se responde solo con el header `X-Accel-Redirect` y Nginx sirve el
     * archivo directamente. El navegador nunca ve la ruta física real, solo
     * la ruta interna que Nginx resuelve puertas adentro. En este entorno de
     * desarrollo (sin Nginx delante) permanece desactivado y se sigue
     * transmitiendo por streaming directo, que es igual de correcto, solo
     * más lento bajo carga alta.
     *
     * @param  array<string, string>  $headers
     */
    public function respuesta(string $ruta, array $headers = []): StreamedResponse
    {
        if ($this->debeUsarXAccelRedirect()) {
            return $this->respuestaXAccelRedirect($ruta, $headers);
        }

        /** @var FilesystemAdapter $adaptador */
        $adaptador = $this->disco();

        return $adaptador->response($ruta, null, $headers);
    }

    private function debeUsarXAccelRedirect(): bool
    {
        return (bool) config('media.x_accel_redirect') && $this->esDiscoLocal();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function respuestaXAccelRedirect(string $ruta, array $headers): StreamedResponse
    {
        $prefijoInterno = rtrim((string) config('media.x_accel_internal_prefix'), '/');
        $rutaInterna = $prefijoInterno.'/'.ltrim($ruta, '/');

        // Cuerpo vacio: Nginx reemplaza la respuesta por completo al ver el
        // header X-Accel-Redirect, PHP nunca llega a leer el archivo.
        $respuesta = new StreamedResponse(function () {});
        $respuesta->headers->set('X-Accel-Redirect', $rutaInterna);

        foreach ($headers as $nombre => $valor) {
            $respuesta->headers->set($nombre, $valor);
        }

        // El Content-Length lo calcula Nginx sobre el archivo real; un valor
        // puesto aqui por PHP (que no leyo el archivo) seria incorrecto.
        $respuesta->headers->remove('Content-Length');
        $respuesta->headers->remove('Transfer-Encoding');

        return $respuesta;
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
