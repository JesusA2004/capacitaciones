<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\OnboardingAvance;
use App\Models\User;
use App\Services\Multimedia\ManifiestoHlsService;
use App\Services\Multimedia\MediaStorageService;
use App\Services\Onboarding\AccesoInduccionColaboradorService;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\ReproduccionVideoInduccionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Material de explicación de la Inducción Mr. Lana que el colaborador titular
 * ve ENTRE las hojas del PDF y su evaluación:
 *  - Audio: narración continua (se sirve el original con el content-type del
 *    recurso); no bloquea la evaluación.
 *  - Video: reproductor HLS con anti-adelanto RESPALDADO POR EL SERVIDOR
 *    en lo que cuenta como VISTO (heartbeat: nunca más rápido que el tiempo
 *    real). La variante se sirve COMPLETA para que precargue; el seek más
 *    allá de lo visto lo bloquea el reproductor — mismo patrón que MiCapacitacion\ReproduccionController, pero
 *    sobre ReproduccionVideoInduccionService (tablas propias, no las de
 *    Capacitación). El video BLOQUEA la evaluación hasta verse completo.
 *
 * Mismo Service que la API móvil (App\Http\Controllers\Api\V1\MiProcesoController).
 */
class InduccionReproduccionController extends Controller
{
    public function __construct(
        private readonly ReproduccionVideoInduccionService $reproduccion,
        private readonly MaterialInduccionService $materiales,
        private readonly ManifiestoHlsService $manifiestos,
        private readonly MediaStorageService $storage,
        private readonly AccesoInduccionColaboradorService $acceso,
        private readonly InduccionPdfService $induccionPdf,
    ) {}

    public function material(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $this->autorizar($request->user(), $avance);

        return response()->json(['data' => [...$this->materiales->datosParaColaborador($avance), ...$this->induccionPdf->bloqueoVideo($avance)]]);
    }

    public function videoIniciar(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizar($usuario, $avance);
        $this->exigirVideo($avance);
        // Orden obligatorio: presentación completa → video (423 «presentacion_incompleta»).
        $this->induccionPdf->exigirPresentacionParaVideo($avance);

        return response()->json($this->reproduccion->iniciarSesion($usuario, $avance));
    }

    public function videoHeartbeat(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizar($usuario, $avance);
        $this->exigirVideo($avance);
        // Mismo orden que «iniciar»: sin esto, un heartbeat directo marcaba el
        // video como iniciado y saltaba el bloqueo de la presentación.
        $this->induccionPdf->exigirPresentacionParaVideo($avance);

        $datos = $request->validate([
            'posicion_segundos' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($this->reproduccion->registrarHeartbeat($usuario, $avance, (int) $datos['posicion_segundos']));
    }

    /**
     * Telemetría del audio (no bloquea nada): deja rastro de hasta dónde
     * escuchó la narración, sin reglas de avance.
     */
    public function audioProgreso(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizar($usuario, $avance);

        $datos = $request->validate([
            'posicion_segundos' => ['required', 'integer', 'min:0'],
        ]);

        $progreso = (array) ($avance->progreso_contenido ?? []);
        $audio = (array) ($progreso['audio'] ?? []);
        $avance->update(['progreso_contenido' => [
            ...$progreso,
            'audio' => [
                ...$audio,
                'ultima_posicion' => (int) $datos['posicion_segundos'],
                'max_posicion' => max((int) ($audio['max_posicion'] ?? 0), (int) $datos['posicion_segundos']),
                'ultima_vez' => now()->toIso8601String(),
            ],
        ]]);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function manifiestoMaestro(Request $request, OnboardingAvance $avance): Response
    {
        $this->autorizar($request->user(), $avance);
        $this->induccionPdf->exigirPresentacionParaVideo($avance);
        $manifiesto = $this->rutaManifiesto($avance);

        $contenido = $this->manifiestos->reescribirMaestro(
            $this->storage->disco()->get($manifiesto) ?? '',
            fn (int $altura) => URL::temporarySignedRoute(
                'portal.induccion.video.variante',
                now()->addSeconds((int) config('media.token_ttl')),
                ['avance' => $avance->id, 'altura' => $altura],
            ),
        );

        return response($contenido, 200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
    }

    public function variante(Request $request, OnboardingAvance $avance, int $altura): Response
    {
        $this->autorizar($request->user(), $avance);
        $manifiesto = $this->rutaManifiesto($avance);

        $rutaVariante = dirname($manifiesto)."/{$altura}p.m3u8";
        abort_unless($this->storage->existe($rutaVariante), 404);

        // Variante COMPLETA (VOD + #EXT-X-ENDLIST), sin truncar a lo visto:
        // truncarla dejaba al reproductor con 1–2 segmentos (~12 s) como
        // playlist «EVENT»; se quedaba sin contenido, se trababa y el
        // heartbeat al reanudar caía fuera del límite («No puedes
        // adelantar…» sin adelantar). El anti-adelanto del seek vive en el
        // reproductor y lo que cuenta como VISTO, en el heartbeat.
        //
        // Como un VOD no se vuelve a pedir, las firmas de los segmentos duran
        // lo que el video (×3, por pausas/buffering) + 1 h, nunca menos que
        // media.token_ttl; cada segmento sigue exigiendo sesión del titular.
        $duracion = (int) ($this->reproduccion->recurso($avance)->duracion_segundos ?? 0);
        $vigenciaSegmentos = max((int) config('media.token_ttl'), $duracion * 3 + 3600);

        $resultado = $this->manifiestos->truncarVariante(
            $this->storage->disco()->get($rutaVariante) ?? '',
            PHP_INT_MAX,
            fn (int $indice, string $archivo) => URL::temporarySignedRoute(
                'portal.induccion.video.segmento',
                now()->addSeconds($vigenciaSegmentos),
                ['avance' => $avance->id, 'altura' => $altura, 'archivo' => $archivo],
            ),
        );

        return response($resultado['contenido'], 200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
    }

    public function segmento(Request $request, OnboardingAvance $avance, int $altura, string $archivo): StreamedResponse
    {
        $this->autorizar($request->user(), $avance);
        $manifiesto = $this->rutaManifiesto($avance);

        // Sin 403 por posición: el reproductor precarga el video completo.
        if (! preg_match('/^'.$altura.'p_\d+\.ts$/', $archivo)) {
            abort(404);
        }

        $ruta = dirname($manifiesto)."/{$archivo}";
        abort_unless($this->storage->existe($ruta), 404);

        return $this->storage->respuesta($ruta, ['Content-Type' => 'video/mp2t']);
    }

    /**
     * Narración (audio): se sirve el archivo ORIGINAL (sin HLS); el content-type
     * es el del recurso. Seek libre (no hay anti-adelanto en el audio).
     */
    public function audio(Request $request, OnboardingAvance $avance): StreamedResponse
    {
        $this->autorizar($request->user(), $avance);
        $material = $this->materiales->materialActivoDeAvance($avance);
        $recurso = $material?->recurso;
        abort_unless($material !== null && ! $material->tipo->bloqueaEvaluacion() && $recurso !== null, 404);
        abort_unless($this->storage->existe($recurso->ruta_original), 404);

        return $this->storage->respuesta($recurso->ruta_original, [
            'Content-Type' => $recurso->mime_type ?? 'audio/mpeg',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Ruta del manifiesto HLS del video de inducción (404 si no hay/está sin procesar). */
    private function rutaManifiesto(OnboardingAvance $avance): string
    {
        $recurso = $this->reproduccion->recurso($avance);
        abort_unless($recurso !== null && $recurso->ruta_hls_manifiesto !== null, 404);

        return $recurso->ruta_hls_manifiesto;
    }

    private function exigirVideo(OnboardingAvance $avance): void
    {
        $recurso = $this->reproduccion->recurso($avance);
        abort_unless($this->materiales->videoListo($recurso), 422, 'El video de la inducción se está preparando. Vuelve a intentarlo en unos minutos.');
    }

    /**
     * @throws AuthorizationException
     */
    private function autorizar(User $usuario, OnboardingAvance $avance): void
    {
        $avance->loadMissing('proceso', 'modulo');

        if ($usuario->colaborador_id === null || $usuario->colaborador_id !== $avance->proceso->colaborador_id) {
            throw new AuthorizationException('Solo el colaborador titular ve el material de su inducción.');
        }

        if ($avance->modulo->evaluacion_formal_clave === null) {
            abort(404);
        }

        $this->acceso->exigirContenidoVisible($usuario);
    }
}
