<?php

namespace App\Services\Reclutamiento;

use App\Models\Candidato;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Bandeja de CVs: carpeta del NAS sincronizada con las descargas de Chrome
 * de la computadora de reclutamiento (config('reclutamiento.bandeja_cv')).
 *
 * Existe porque quien registra desde WhatsApp Web (una persona o Claude en
 * Chrome) puede DESCARGAR el CV, pero el navegador no deja que una página o
 * un agente elijan un archivo del disco en «Seleccionar archivo». Con la
 * bandeja, el archivo ya está en el NAS: solo se elige por nombre y se MUEVE
 * a candidatos/{id}/cv/ (sale de la bandeja, así no se asigna dos veces).
 *
 * Solo se aceptan nombres de archivo planos de la propia carpeta (nunca
 * rutas): un nombre con «/» o «..» se rechaza.
 */
class BandejaCvService
{
    public function __construct(private readonly ExpedienteCandidatoService $expediente) {}

    /**
     * Archivos listos para asignar, del más reciente al más antiguo. Omite
     * descargas a medias (.crdownload/.tmp) y archivos ocultos o de un tipo
     * no permitido.
     *
     * @return list<array{archivo: string, tamano: int, modificado_en: string}>
     */
    public function listar(): array
    {
        $disco = $this->disco();
        $carpeta = $this->carpeta();

        try {
            $rutas = $disco->files($carpeta);
        } catch (Throwable $e) {
            Log::warning('Bandeja de CVs: no se pudo leer la carpeta.', ['carpeta' => $carpeta, 'error' => $e->getMessage()]);

            return [];
        }

        $archivos = [];

        foreach ($rutas as $ruta) {
            $nombre = basename($ruta);

            if (! $this->nombreValido($nombre)) {
                continue;
            }

            try {
                $archivos[] = [
                    'archivo' => $nombre,
                    'tamano' => $disco->size($ruta),
                    'modificado_en' => Carbon::createFromTimestamp($disco->lastModified($ruta))->toIso8601String(),
                ];
            } catch (Throwable) {
                // Se movió/borró mientras se listaba: simplemente no se ofrece.
                continue;
            }
        }

        usort($archivos, fn (array $a, array $b): int => strcmp($b['modificado_en'], $a['modificado_en']));

        return array_slice($archivos, 0, max(1, (int) config('reclutamiento.bandeja_cv.limite', 60)));
    }

    public function existe(string $nombre): bool
    {
        return $this->nombreValido($nombre) && $this->disco()->exists($this->ruta($nombre));
    }

    /**
     * Vista previa (para confirmar que es el CV correcto antes de asignarlo).
     */
    public function respuesta(string $nombre): StreamedResponse
    {
        $this->exigirArchivo($nombre);

        /** @var FilesystemAdapter $disco */
        $disco = $this->disco();

        return $disco->response($this->ruta($nombre), null, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => sprintf('inline; filename="%s"', str_replace(['"', '\\', "\r", "\n"], '', $nombre)),
        ]);
    }

    /**
     * Guarda el archivo como CV del candidato y lo quita de la bandeja.
     */
    public function asignar(Candidato $candidato, string $nombre, ?User $actor): Candidato
    {
        $this->exigirArchivo($nombre);

        // Dos personas (o dos pestañas) asignando el mismo archivo a la vez:
        // solo una gana, la otra recibe un mensaje claro.
        $candado = Cache::lock(sprintf('bandeja-cv:%s', md5($nombre)), 60);

        if (! $candado->get()) {
            throw ValidationException::withMessages(['cv_bandeja' => 'Ese archivo se está asignando a otro candidato en este momento.']);
        }

        try {
            $this->exigirArchivo($nombre);
            $disco = $this->disco();
            $ruta = $this->ruta($nombre);
            $tamano = $disco->size($ruta);
            $maximo = (int) config('reclutamiento.max_upload_mb') * 1024 * 1024;

            if ($tamano > $maximo) {
                throw ValidationException::withMessages(['cv_bandeja' => sprintf('El archivo pesa más de %d MB.', (int) config('reclutamiento.max_upload_mb'))]);
            }

            $flujo = $disco->readStream($ruta);

            if (! is_resource($flujo)) {
                throw ValidationException::withMessages(['cv_bandeja' => 'No se pudo leer el archivo de la bandeja. Intenta de nuevo.']);
            }

            try {
                $this->expediente->guardarCvDesdeFlujo($candidato, $flujo, $nombre, $this->mime($disco, $ruta, $nombre), $tamano, $actor);
            } finally {
                fclose($flujo);
            }

            // Ya quedó en el expediente: sale de la bandeja. Si no se puede
            // borrar, el CV del candidato ya es válido; solo se avisa en el log.
            try {
                $disco->delete($ruta);
            } catch (Throwable $e) {
                Log::warning('Bandeja de CVs: el CV se asignó pero no se pudo quitar de la bandeja.', ['archivo' => $nombre, 'error' => $e->getMessage()]);
            }

            return $candidato;
        } finally {
            $candado->release();
        }
    }

    private function exigirArchivo(string $nombre): void
    {
        if (! $this->existe($nombre)) {
            throw ValidationException::withMessages(['cv_bandeja' => sprintf('«%s» ya no está en la bandeja de CVs (¿ya se asignó?). Actualiza la lista.', $nombre)]);
        }
    }

    /**
     * Nombre plano (sin carpetas), visible y con extensión de CV permitida.
     */
    private function nombreValido(string $nombre): bool
    {
        if ($nombre === '' || $nombre !== basename($nombre) || str_contains($nombre, '..') || str_contains($nombre, '\\') || str_starts_with($nombre, '.') || str_starts_with($nombre, '~$')) {
            return false;
        }

        $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

        return in_array($extension, (array) config('reclutamiento.extensiones_permitidas'), true);
    }

    private function mime(Filesystem $disco, string $ruta, string $nombre): string
    {
        try {
            $mime = $disco->mimeType($ruta);
        } catch (Throwable) {
            $mime = false;
        }

        if (is_string($mime) && $mime !== '' && $mime !== 'application/octet-stream') {
            return $mime;
        }

        return match (strtolower(pathinfo($nombre, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function ruta(string $nombre): string
    {
        return sprintf('%s/%s', $this->carpeta(), $nombre);
    }

    private function carpeta(): string
    {
        return trim((string) config('reclutamiento.bandeja_cv.carpeta'), '/');
    }

    private function disco(): Filesystem
    {
        return Storage::disk((string) config('reclutamiento.bandeja_cv.disk'));
    }
}
