<?php

namespace App\Http\Controllers\MiCapacitacion;

use App\Enums\TipoLeccion;
use App\Http\Controllers\Controller;
use App\Http\Requests\MiCapacitacion\HeartbeatReproduccionRequest;
use App\Models\Leccion;
use App\Models\SesionReproduccion;
use App\Models\User;
use App\Services\Capacitacion\ProgresoService;
use App\Services\Multimedia\ManifiestoHlsService;
use App\Services\Multimedia\MediaStorageService;
use App\Services\Multimedia\ReproduccionVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve el reproductor HLS de las lecciones de video de "Mi capacitación" y
 * hace cumplir el control de avance en el servidor: el manifiesto maestro,
 * las variantes y los segmentos se entregan solo a traves de rutas firmadas
 * de corta duracion, y las variantes/segmentos se truncan/rechazan segun lo
 * que ReproduccionVideoService considera realmente visto por el usuario.
 */
class ReproduccionController extends Controller
{
    public function __construct(
        private readonly ReproduccionVideoService $reproduccion,
        private readonly ManifiestoHlsService $manifiestos,
        private readonly MediaStorageService $storage,
        private readonly ProgresoService $progreso,
    ) {}

    public function iniciar(Request $request, Leccion $leccion): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizarAcceso($usuario, $leccion);

        $recurso = $leccion->recursoMultimedia;

        if ($leccion->tipo !== TipoLeccion::Video || ! $recurso?->estaDisponible()) {
            abort(422, 'Este recurso de video no está disponible.');
        }

        $sesion = $this->reproduccion->iniciarSesion($usuario, $leccion, $request->ip(), $request->userAgent());

        return response()->json([
            'sesion_id' => $sesion->id,
            'posicion_inicial' => $sesion->ultima_posicion_segundos,
            'duracion_total_segundos' => $recurso->duracion_segundos,
            'porcentaje_visto' => $this->reproduccion->porcentajeVisto($usuario, $leccion),
            'segundo_maximo_permitido' => $this->reproduccion->segundoMaximoPermitido($usuario, $leccion),
            'heartbeat_segundos' => (int) config('media.video.heartbeat_seconds'),
            'completada' => $this->progreso->leccionCompletada($usuario, $leccion),
            'url_manifiesto' => URL::temporarySignedRoute(
                'mi-capacitacion.lecciones.reproduccion.manifiesto-maestro',
                now()->addSeconds((int) config('media.token_ttl')),
                ['leccion' => $leccion->id],
            ),
        ]);
    }

    public function heartbeat(HeartbeatReproduccionRequest $request, Leccion $leccion): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizarAcceso($usuario, $leccion);

        $sesion = SesionReproduccion::query()
            ->where('id', $request->integer('sesion_id'))
            ->where('user_id', $usuario->id)
            ->where('leccion_id', $leccion->id)
            ->firstOrFail();

        return response()->json(
            $this->reproduccion->registrarHeartbeat($usuario, $sesion, $request->integer('posicion_segundos')),
        );
    }

    public function manifiestoMaestro(Request $request, Leccion $leccion): Response
    {
        $usuario = $request->user();
        $this->autorizarAcceso($usuario, $leccion);

        $recurso = $leccion->recursoMultimedia;
        abort_unless($recurso !== null && $recurso->ruta_hls_manifiesto !== null, 404);

        $contenido = $this->manifiestos->reescribirMaestro(
            $this->storage->disco()->get($recurso->ruta_hls_manifiesto) ?? '',
            fn (int $altura) => URL::temporarySignedRoute(
                'mi-capacitacion.lecciones.reproduccion.variante',
                now()->addSeconds((int) config('media.token_ttl')),
                ['leccion' => $leccion->id, 'altura' => $altura],
            ),
        );

        return response($contenido, 200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
    }

    public function variante(Request $request, Leccion $leccion, int $altura): Response
    {
        $usuario = $request->user();
        $this->autorizarAcceso($usuario, $leccion);

        $recurso = $leccion->recursoMultimedia;
        abort_unless($recurso !== null && $recurso->ruta_hls_manifiesto !== null, 404);

        $rutaVariante = dirname($recurso->ruta_hls_manifiesto)."/{$altura}p.m3u8";
        abort_unless($this->storage->existe($rutaVariante), 404);

        $limite = $this->reproduccion->segundoMaximoPermitido($usuario, $leccion);

        $resultado = $this->manifiestos->truncarVariante(
            $this->storage->disco()->get($rutaVariante) ?? '',
            $limite,
            fn (int $indice, string $archivo) => URL::temporarySignedRoute(
                'mi-capacitacion.lecciones.reproduccion.segmento',
                now()->addSeconds((int) config('media.token_ttl')),
                ['leccion' => $leccion->id, 'altura' => $altura, 'archivo' => $archivo],
            ),
        );

        return response($resultado['contenido'], 200, ['Content-Type' => 'application/vnd.apple.mpegurl']);
    }

    public function segmento(Request $request, Leccion $leccion, int $altura, string $archivo): StreamedResponse
    {
        $usuario = $request->user();
        $this->autorizarAcceso($usuario, $leccion);

        $recurso = $leccion->recursoMultimedia;
        abort_unless($recurso !== null && $recurso->ruta_hls_manifiesto !== null, 404);

        if (! preg_match('/^'.$altura.'p_(\d+)\.ts$/', $archivo, $coincidencias)) {
            abort(404);
        }

        $segundoSegmento = ((int) $coincidencias[1]) * (int) config('media.video.segmento_segundos');

        abort_if($segundoSegmento > $this->reproduccion->segundoMaximoPermitido($usuario, $leccion), 403, 'Todavía no puedes ver esta parte del video.');

        $ruta = dirname($recurso->ruta_hls_manifiesto)."/{$archivo}";
        abort_unless($this->storage->existe($ruta), 404);

        return $this->storage->respuesta($ruta, ['Content-Type' => 'video/mp2t']);
    }

    private function autorizarAcceso(User $usuario, Leccion $leccion): void
    {
        $this->progreso->autorizarAccesoLeccion($usuario, $leccion);
    }
}
