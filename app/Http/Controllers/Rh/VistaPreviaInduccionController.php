<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\RecursoMultimedia;
use App\Services\Multimedia\ManifiestoHlsService;
use App\Services\Multimedia\MediaStorageService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\MaterialInduccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vistas previas de RH de la inducción (regla: todo lo que se le presenta
 * al colaborador, RH lo ve TAL CUAL antes de habilitarlo):
 *
 *  - «Ver evaluación como colaborador»: la misma página del colaborador
 *    (Portal/EvaluacionInduccion) en modo vista previa, sin evaluación ni
 *    envío — no crea evaluación, intento ni registro.
 *  - Material de explicación (audio/video): RH lo escucha/ve completo, sin
 *    anti-adelanto y sin registrar avance (no toca OnboardingAvance ni los
 *    intervalos del video). Endpoints firmados, como los del colaborador.
 *
 * La autorización (onboarding.gestionar) vive en los Services.
 */
class VistaPreviaInduccionController extends Controller
{
    public function __construct(
        private readonly EvaluacionInduccionService $evaluacion,
        private readonly MaterialInduccionService $materiales,
        private readonly ManifiestoHlsService $manifiestos,
        private readonly MediaStorageService $storage,
    ) {}

    public function evaluacion(Request $request): InertiaResponse
    {
        return Inertia::render('Portal/EvaluacionInduccion', [
            'contenido' => $this->evaluacion->vistaPreviaRh($request->user()),
            'evaluacionId' => null,
            'vistaPrevia' => true,
        ]);
    }

    /** URLs firmadas frescas del material vigente (se piden al reproducir). */
    public function material(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->materiales->vistaPreviaRh($request->user())]);
    }

    public function manifiestoMaestro(Request $request): Response
    {
        $manifiesto = $this->rutaManifiesto($this->video($request));

        $contenido = $this->manifiestos->reescribirMaestro(
            $this->storage->disco()->get($manifiesto) ?? '',
            fn (int $altura) => URL::temporarySignedRoute(
                'rh.onboarding.induccion.material.vista-previa.variante',
                now()->addSeconds((int) config('media.token_ttl')),
                ['altura' => $altura],
            ),
        );

        return response($contenido, 200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
    }

    /** Variante COMPLETA (sin truncar): RH no está sujeto al anti-adelanto. */
    public function variante(Request $request, int $altura): Response
    {
        $manifiesto = $this->rutaManifiesto($this->video($request));
        $rutaVariante = sprintf('%s/%dp.m3u8', dirname($manifiesto), $altura);
        abort_unless($this->storage->existe($rutaVariante), 404);

        $resultado = $this->manifiestos->truncarVariante(
            $this->storage->disco()->get($rutaVariante) ?? '',
            PHP_INT_MAX,
            fn (int $indice, string $archivo) => URL::temporarySignedRoute(
                'rh.onboarding.induccion.material.vista-previa.segmento',
                now()->addSeconds((int) config('media.token_ttl')),
                ['altura' => $altura, 'archivo' => $archivo],
            ),
        );

        return response($resultado['contenido'], 200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
    }

    public function segmento(Request $request, int $altura, string $archivo): StreamedResponse
    {
        $manifiesto = $this->rutaManifiesto($this->video($request));
        abort_unless(preg_match('/^'.$altura.'p_\d+\.ts$/', $archivo) === 1, 404);

        $ruta = sprintf('%s/%s', dirname($manifiesto), $archivo);
        abort_unless($this->storage->existe($ruta), 404);

        return $this->storage->respuesta($ruta, ['Content-Type' => 'video/mp2t']);
    }

    public function audio(Request $request): StreamedResponse
    {
        $recurso = $this->materiales->recursoVistaPreviaRh($request->user(), false);
        abort_unless($recurso !== null && $this->storage->existe($recurso->ruta_original), 404);

        return $this->storage->respuesta($recurso->ruta_original, [
            'Content-Type' => $recurso->mime_type ?? 'audio/mpeg',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function video(Request $request): ?RecursoMultimedia
    {
        return $this->materiales->recursoVistaPreviaRh($request->user(), true);
    }

    private function rutaManifiesto(?RecursoMultimedia $recurso): string
    {
        abort_unless($recurso !== null && $recurso->ruta_hls_manifiesto !== null, 404);

        return $recurso->ruta_hls_manifiesto;
    }
}
