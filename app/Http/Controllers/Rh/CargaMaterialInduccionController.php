<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoCargaMultimedia;
use App\Enums\TipoRecursoMultimedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Multimedia\SubirBloqueCargaRequest;
use App\Models\CargaMultimedia;
use App\Services\Multimedia\CargaResumibleService;
use App\Services\Onboarding\MaterialInduccionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Carga por bloques reanudable del VIDEO de explicación de la inducción.
 *
 * Reutiliza tal cual CargaResumibleService (el mismo de la biblioteca
 * multimedia) pero vive bajo onboarding, sin feature:capacitacion: un video de
 * cientos de MB en un solo request choca con client_max_body_size/post_max_size
 * y falla sin aviso; por bloques (8 MB) no. Al llegar el último bloque, el
 * Service ensambla, crea el RecursoMultimedia y encola ProcesarVideoJob; aquí
 * solo se activa el recurso como material vigente (MaterialInduccionService).
 *
 * Permiso: onboarding.gestionar + onboarding.configurar (el mismo que
 * MaterialInduccionController). Toda carga se re-consulta scopeada al usuario.
 */
class CargaMaterialInduccionController extends Controller
{
    /** Extensiones de video que FFmpeg convierte sin problema. */
    private const EXTENSIONES_VIDEO = ['mp4', 'm4v', 'mov', 'webm', 'mkv'];

    public function __construct(
        private readonly CargaResumibleService $cargas,
        private readonly MaterialInduccionService $materiales,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function iniciar(Request $request): JsonResponse
    {
        $this->materiales->exigirPermisoConfigurar($request->user());
        // Antes de subir cientos de MB: que exista dónde colocarlo.
        $this->materiales->moduloInstitucionalObligatorio();

        $datos = $request->validate([
            'nombre_original' => ['required', 'string', 'max:255', 'regex:/\.('.implode('|', self::EXTENSIONES_VIDEO).')$/i'],
            'tamano_total_bytes' => ['required', 'integer', 'min:1', 'max:'.((int) config('media.max_upload_mb') * 1024 * 1024)],
            'hash_esperado' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i'],
        ], [
            'nombre_original.regex' => sprintf('El video debe ser %s.', strtoupper(implode(', ', self::EXTENSIONES_VIDEO))),
            'tamano_total_bytes.max' => sprintf('El video no debe pesar más de %d MB.', (int) config('media.max_upload_mb')),
        ]);

        $carga = $this->cargas->iniciar($request->user(), [
            'nombre_original' => (string) $datos['nombre_original'],
            'tipo' => TipoRecursoMultimedia::Video->value,
            'tamano_total_bytes' => (int) $datos['tamano_total_bytes'],
            'hash_esperado' => $datos['hash_esperado'] ?? null,
        ]);

        return response()->json($this->cargas->presentar($carga));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function bloque(Request $request, string $identificador): JsonResponse
    {
        $carga = $this->carga($request, $identificador);
        $datos = $request->validate((new SubirBloqueCargaRequest)->rules());
        $yaCompletada = $carga->estado === EstadoCargaMultimedia::Completada;

        try {
            $carga = $this->cargas->recibirBloque($carga, (int) $datos['numero_bloque'], $request->file('bloque'));
        } catch (\RuntimeException $excepcion) {
            return response()->json(['message' => $excepcion->getMessage()], 422);
        }

        // Solo en la transición a «completada» (no en reintentos posteriores):
        // si RH ya activó otro material después, no se le pisa.
        if (! $yaCompletada) {
            $this->materiales->activarCargaCompletada($carga, $request->user());
        }

        return response()->json($this->cargas->presentar($carga));
    }

    /**
     * @throws AuthorizationException
     */
    public function estado(Request $request, string $identificador): JsonResponse
    {
        return response()->json($this->cargas->presentar($this->carga($request, $identificador)));
    }

    /**
     * @throws AuthorizationException
     */
    public function pausar(Request $request, string $identificador): JsonResponse
    {
        return response()->json($this->cargas->presentar($this->cargas->pausar($this->carga($request, $identificador))));
    }

    /**
     * @throws AuthorizationException
     */
    public function reanudar(Request $request, string $identificador): JsonResponse
    {
        return response()->json($this->cargas->presentar($this->cargas->reanudar($this->carga($request, $identificador))));
    }

    /**
     * @throws AuthorizationException
     */
    public function cancelar(Request $request, string $identificador): JsonResponse
    {
        return response()->json($this->cargas->presentar($this->cargas->cancelar($this->carga($request, $identificador))));
    }

    /**
     * @throws AuthorizationException
     */
    private function carga(Request $request, string $identificador): CargaMultimedia
    {
        $this->materiales->exigirPermisoConfigurar($request->user());

        return $this->cargas->deUsuario($request->user(), $identificador);
    }
}
