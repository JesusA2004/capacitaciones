<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoMultimedia;
use App\Enums\OrigenRecursoMultimedia;
use App\Enums\TipoMaterialInduccion;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoRecursoMultimedia;
use App\Enums\VisibilidadRecursoMultimedia;
use App\Http\Controllers\Controller;
use App\Jobs\ProcesarVideoJob;
use App\Models\OnboardingModulo;
use App\Models\RecursoMultimedia;
use App\Services\Multimedia\FfmpegService;
use App\Services\Multimedia\MediaStorageService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Carga y activación del material de explicación (audio XOR video) de la
 * Inducción Mr. Lana, para RH (onboarding.gestionar). Reutiliza el pipeline
 * multimedia (MediaStorageService + ProcesarVideoJob + RecursoMultimedia) SIN
 * depender de feature:capacitacion — vive bajo onboarding. La regla de negocio
 * (uno activo a la vez, XOR, auditoría) la centraliza MaterialInduccionService.
 */
class MaterialInduccionController extends Controller
{
    public function __construct(
        private readonly MaterialInduccionService $materiales,
        private readonly MediaStorageService $storage,
        private readonly FfmpegService $ffmpeg,
    ) {}

    /**
     * Sube un archivo (audio/video) y lo activa como material del módulo
     * institucional de inducción.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $this->exigirPermiso($request);

        $datos = $request->validate([
            'tipo' => ['required', 'in:audio,video'],
            'archivo' => [
                'required', 'file',
                'max:'.(config('media.max_upload_mb') * 1024),
                $request->input('tipo') === 'video'
                    ? 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska'
                    : 'mimetypes:audio/mpeg,audio/mp4,audio/aac,audio/ogg,audio/wav,audio/x-wav,audio/webm',
            ],
        ]);

        $modulo = $this->moduloInduccion();
        $tipoMaterial = TipoMaterialInduccion::from($datos['tipo']);
        $tipoRecurso = $tipoMaterial->tipoRecurso();

        $archivo = $request->file('archivo');
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $rutaDestino = $this->storage->rutaOriginal($nombreInterno);
        $this->storage->guardar($archivo, $rutaDestino);

        $recurso = RecursoMultimedia::query()->create([
            'tipo' => $tipoRecurso->value,
            'nombre_original' => $archivo->getClientOriginalName(),
            'nombre_interno' => $nombreInterno,
            'disco' => config('media.disk'),
            'ruta_original' => $rutaDestino,
            'mime_type' => $archivo->getMimeType(),
            'tamano_bytes' => $archivo->getSize(),
            // El audio queda disponible de inmediato (sin HLS); el video se
            // procesa en cola (HLS + miniatura) antes de poder reproducirse.
            'estado' => $tipoRecurso === TipoRecursoMultimedia::Video ? EstadoMultimedia::Pendiente->value : EstadoMultimedia::Disponible->value,
            'duracion_segundos' => $tipoRecurso === TipoRecursoMultimedia::Audio ? $this->duracionAudio($rutaDestino) : null,
            'subido_por' => $request->user()->id,
            'origen' => OrigenRecursoMultimedia::Biblioteca->value,
            'visibilidad' => VisibilidadRecursoMultimedia::Publica->value,
            'acceso_restringido' => false,
        ]);

        if ($tipoRecurso === TipoRecursoMultimedia::Video) {
            ProcesarVideoJob::dispatch($recurso);
        }

        $this->materiales->activar($modulo, $recurso, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => $tipoRecurso === TipoRecursoMultimedia::Video
            ? 'Video cargado: se está procesando y quedará listo en unos minutos.'
            : 'Audio de narración cargado y activado.']);
    }

    /**
     * Activa un recurso multimedia ya existente como material de inducción.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function activar(Request $request): RedirectResponse
    {
        $this->exigirPermiso($request);
        $datos = $request->validate(['recurso_multimedia_id' => ['required', 'integer', 'exists:recursos_multimedia,id']]);

        $recurso = RecursoMultimedia::query()->where('id', (int) $datos['recurso_multimedia_id'])->firstOrFail();
        $this->materiales->activar($this->moduloInduccion(), $recurso, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Material de inducción actualizado.']);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->exigirPermiso($request);
        $this->materiales->desactivar($this->moduloInduccion(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Material de inducción retirado.']);
    }

    private function moduloInduccion(): OnboardingModulo
    {
        $modulo = OnboardingModulo::query()
            ->whereNotNull('evaluacion_formal_clave')
            ->where('tipo', TipoModuloOnboarding::Institucional->value)
            ->where('activo', true)
            ->orderBy('orden')
            ->first();

        if ($modulo === null) {
            throw ValidationException::withMessages(['modulo' => 'No hay un módulo de inducción institucional activo donde colocar el material.']);
        }

        return $modulo;
    }

    private function duracionAudio(string $ruta): ?int
    {
        if (! $this->storage->esDiscoLocal()) {
            return null;
        }

        try {
            return $this->ffmpeg->inspeccionar($this->storage->rutaLocalAbsoluta($ruta))['duracion_segundos'];
        } catch (\Throwable $e) {
            // La duración del audio es solo telemetría (no bloquea nada): si
            // ffprobe no está disponible, se deja sin registrar.
            Log::warning('MaterialInduccionController: no se pudo medir la duración del audio.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function exigirPermiso(Request $request): void
    {
        if (! $request->user()->can(OnboardingService::PERMISO_GESTIONAR)) {
            throw new AuthorizationException('Solo Recursos Humanos gestiona el material de inducción.');
        }
    }
}
