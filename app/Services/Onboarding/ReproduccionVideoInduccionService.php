<?php

namespace App\Services\Onboarding;

use App\Models\IntervaloVideoInduccion;
use App\Models\OnboardingAvance;
use App\Models\RecursoMultimedia;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Control de avance del VIDEO de la Inducción Mr. Lana (cuando el material
 * activo del módulo es video). Espejo dedicado de
 * App\Services\Multimedia\ReproduccionVideoService sobre
 * induccion_video_intervalos + OnboardingAvance, para NO tocar las tablas del
 * módulo de Capacitación (congelado tras feature:capacitacion).
 *
 * Dos límites distintos: lo que se puede DESCARGAR (segundoMaximoDescargable,
 * por defecto el video completo para que precargue y no se trabe) y lo que
 * cuenta como VISTO (heartbeat: nunca avanza más rápido que el tiempo real
 * del servidor). El anti-adelanto del crédito queda respaldado por el
 * servidor; el del seek, por el reproductor. El progreso vive en el servidor (intervalos +
 * progreso_contenido.video del avance), así que sobrevive a F5 y a cerrar y
 * reabrir el reproductor. Al llegar a media.video.completion_percent se marca
 * progreso_contenido.video.completado_en, que BLOQUEA la evaluación hasta que
 * exista (InduccionPdfService::terminar()).
 */
class ReproduccionVideoInduccionService
{
    public function __construct(private readonly MaterialInduccionService $materiales) {}

    /** El recurso del video activo del módulo del avance, o null. */
    public function recurso(OnboardingAvance $avance): ?RecursoMultimedia
    {
        $material = $this->materiales->materialActivoDeAvance($avance);

        if ($material === null || ! $material->tipo->bloqueaEvaluacion()) {
            return null;
        }

        return $material->recurso;
    }

    /**
     * Arranca una reproducción: devuelve la posición inicial (lo máximo ya
     * alcanzado, para retomar tras F5 sin reiniciar ni saltar) y el estado
     * actual. Fija el ancla de posición en progreso_contenido.video para que
     * el primer heartbeat forme un tramo coherente.
     *
     * @return array{sesion_id: int, posicion_inicial: int, duracion_total_segundos: int|null, porcentaje_visto: float, segundo_maximo_permitido: int, segundo_maximo_visto: int, heartbeat_segundos: int, completada: bool, url_manifiesto: string}
     */
    public function iniciarSesion(User $usuario, OnboardingAvance $avance): array
    {
        $recurso = $this->recurso($avance);
        $maximo = $this->segundoMaximoAlcanzado($usuario, $avance);

        $this->actualizarVideo($avance, [
            'ultima_posicion' => $maximo,
            'ultimo_heartbeat_en' => now()->toIso8601String(),
            'duracion' => $recurso?->duracion_segundos,
        ]);

        return [
            'sesion_id' => $avance->id,
            'posicion_inicial' => $maximo,
            'duracion_total_segundos' => $recurso?->duracion_segundos,
            'porcentaje_visto' => $this->porcentajeVisto($usuario, $avance),
            'segundo_maximo_permitido' => $this->segundoMaximoPermitido($usuario, $avance),
            'segundo_maximo_visto' => $maximo,
            'heartbeat_segundos' => (int) config('media.video.heartbeat_seconds'),
            'completada' => $this->videoCompletado($avance),
            'url_manifiesto' => URL::temporarySignedRoute(
                'portal.induccion.video.manifiesto-maestro',
                now()->addSeconds((int) config('media.token_ttl')),
                ['avance' => $avance->id],
            ),
        ];
    }

    public function segundoMaximoAlcanzado(User $usuario, OnboardingAvance $avance): int
    {
        return (int) (IntervaloVideoInduccion::query()
            ->where('user_id', $usuario->id)
            ->where('onboarding_avance_id', $avance->id)
            ->max('fin_segundo') ?? 0);
    }

    /**
     * Hasta dónde puede BUSCAR el colaborador (lo visto + tolerancia). Ya NO
     * limita la descarga: el controlador sirve la variante HLS completa.
     */
    public function segundoMaximoPermitido(User $usuario, OnboardingAvance $avance): int
    {
        return $this->segundoMaximoAlcanzado($usuario, $avance) + $this->tolerancia();
    }

    private function tolerancia(): int
    {
        return (int) config('media.video.salto_tolerancia_segundos');
    }

    /**
     * Límite del heartbeat: lo ya visto + tolerancia, o la última posición
     * reportada + el tiempo REAL transcurrido desde el último heartbeat
     * aceptado (× velocidad permitida) + tolerancia. Así la reproducción
     * normal (8 s entre heartbeats) nunca se rechaza, un buffering solo la
     * hace avanzar más lento, y nadie acumula «visto» más rápido que el reloj
     * del servidor (no depende del reloj del cliente).
     *
     * @param  array<string, mixed>  $video
     */
    private function limiteHeartbeat(User $usuario, OnboardingAvance $avance, array $video): int
    {
        $alcanzado = $this->segundoMaximoAlcanzado($usuario, $avance);
        $ancla = (int) ($video['ultima_posicion'] ?? $alcanzado);

        return max(
            $alcanzado + $this->tolerancia(),
            $ancla + $this->segundosAcreditables($video) + $this->tolerancia(),
        );
    }

    /**
     * Segundos de video que el reloj del servidor permite acreditar desde el
     * último heartbeat. Con tope de 3 heartbeats: esperar sin reproducir (o
     * dejar la pestaña abierta) no «ahorra» tiempo para marcar el video de un
     * solo golpe.
     *
     * @param  array<string, mixed>  $video
     */
    private function segundosAcreditables(array $video): int
    {
        return (int) floor($this->transcurridoTopado($video) * $this->velocidad());
    }

    /** @param  array<string, mixed>  $video */
    private function transcurridoTopado(array $video): float
    {
        $tope = 3.0 * (float) config('media.video.heartbeat_seconds');
        $ultimoHeartbeat = $video['ultimo_heartbeat_en'] ?? null;

        if (! is_string($ultimoHeartbeat)) {
            return $tope;
        }

        return min($tope, max(0.0, Carbon::parse($ultimoHeartbeat)->diffInSeconds(now(), false)));
    }

    private function velocidad(): float
    {
        return max(1.0, (float) config('media.video.playback_rate'));
    }

    /** Marca de tiempo con microsegundos: el tiempo no acreditado se arrastra al siguiente heartbeat. */
    private function marcaTiempo(CarbonInterface $momento): string
    {
        return $momento->format('Y-m-d\TH:i:s.uP');
    }

    public function porcentajeVisto(User $usuario, OnboardingAvance $avance): float
    {
        $duracion = $this->recurso($avance)?->duracion_segundos;

        if (! $duracion) {
            return 0.0;
        }

        return round(min(100, ($this->segundosUnicosVistos($usuario, $avance) / $duracion) * 100), 2);
    }

    /**
     * Porcentaje del video ya visto según el último heartbeat del titular
     * (progreso_contenido.video.porcentaje), 0–100. Lo usa el avance que ve
     * RH: un video a medias ya cuenta como «viendo», no como «sin empezar».
     */
    public function porcentajeRegistrado(OnboardingAvance $avance): float
    {
        if ($this->videoCompletado($avance)) {
            return 100.0;
        }

        $video = $this->video($avance);

        return max(0.0, min(100.0, (float) ($video['porcentaje'] ?? 0)));
    }

    /** El titular ya reprodujo algo del video (aunque sea un tramo). */
    public function videoIniciado(OnboardingAvance $avance): bool
    {
        $video = $this->video($avance);

        return (int) ($video['segundo_max'] ?? 0) > 0 || (float) ($video['porcentaje'] ?? 0) > 0;
    }

    public function videoCompletado(OnboardingAvance $avance): bool
    {
        $video = $this->video($avance);

        return ($video['completado_en'] ?? null) !== null;
    }

    /**
     * @return array{permitido: bool, posicion_permitida: int, segundo_maximo_permitido: int, segundo_maximo_visto: int, porcentaje_visto: float, completada: bool}
     */
    public function registrarHeartbeat(User $usuario, OnboardingAvance $avance, int $posicionReportada): array
    {
        $video = $this->video($avance);
        $limitePermitido = $this->limiteHeartbeat($usuario, $avance, $video);

        if ($posicionReportada > $limitePermitido) {
            // Adelanto real (más rápido que el tiempo transcurrido): ni se
            // extienden los tramos ni se mueve el ancla ni el reloj; el
            // cliente regresa a lo ya visto.
            $permitida = $this->segundoMaximoPermitido($usuario, $avance);

            return [
                'permitido' => false,
                'posicion_permitida' => $permitida,
                'segundo_maximo_permitido' => $permitida,
                'segundo_maximo_visto' => $this->segundoMaximoAlcanzado($usuario, $avance),
                'porcentaje_visto' => $this->porcentajeVisto($usuario, $avance),
                'completada' => $this->videoCompletado($avance),
            ];
        }

        $ancla = (int) ($video['ultima_posicion'] ?? $this->segundoMaximoAlcanzado($usuario, $avance));
        $ahora = now();

        if ($posicionReportada > $ancla) {
            // Solo se acredita lo que el reloj del servidor permite (la
            // tolerancia acepta la posición, pero NO cuenta como visto): varios
            // heartbeats seguidos sin que pase tiempo no suman nada. El ancla y
            // el reloj avanzan exactamente lo acreditado, así el tiempo sobrante
            // (o la posición adelantada dentro de la tolerancia) se acredita en
            // el siguiente heartbeat, sin huecos en la reproducción normal.
            $credito = min($posicionReportada - $ancla, $this->segundosAcreditables($video));
            $inicioReloj = $ahora->copy()->subMicroseconds((int) round($this->transcurridoTopado($video) * 1_000_000));
            $nuevaAncla = $ancla + $credito;
            $nuevoReloj = $inicioReloj->addMicroseconds((int) round($credito / $this->velocidad() * 1_000_000));

            if ($credito > 0) {
                $this->fusionarTramo($usuario, $avance, $ancla, $nuevaAncla);
            }
        } else {
            // Retrocedió o sigue en el mismo punto (pausa/buffering): nada nuevo que acreditar.
            $nuevaAncla = $posicionReportada;
            $nuevoReloj = $ahora;
        }

        $porcentaje = $this->porcentajeVisto($usuario, $avance);
        $completada = $this->videoCompletado($avance);
        $cambios = [
            'ultima_posicion' => $nuevaAncla,
            'ultimo_heartbeat_en' => $this->marcaTiempo($nuevoReloj),
            'segundo_max' => max((int) ($video['segundo_max'] ?? 0), $this->segundoMaximoAlcanzado($usuario, $avance)),
            'porcentaje' => $porcentaje,
        ];

        if (! $completada && $porcentaje >= (float) config('media.video.completion_percent')) {
            $cambios['completado_en'] = now()->toIso8601String();
            $completada = true;
        }

        $this->actualizarVideo($avance, $cambios);

        return [
            'permitido' => true,
            'posicion_permitida' => $posicionReportada,
            'segundo_maximo_permitido' => $this->segundoMaximoPermitido($usuario, $avance),
            'segundo_maximo_visto' => $this->segundoMaximoAlcanzado($usuario, $avance),
            'porcentaje_visto' => $porcentaje,
            'completada' => $completada,
        ];
    }

    private function segundosUnicosVistos(User $usuario, OnboardingAvance $avance): int
    {
        return (int) IntervaloVideoInduccion::query()
            ->where('user_id', $usuario->id)
            ->where('onboarding_avance_id', $avance->id)
            ->get()
            ->sum(fn (IntervaloVideoInduccion $tramo): int => $tramo->fin_segundo - $tramo->inicio_segundo);
    }

    private function fusionarTramo(User $usuario, OnboardingAvance $avance, int $inicio, int $fin): void
    {
        DB::transaction(function () use ($usuario, $avance, $inicio, $fin): void {
            $solapados = IntervaloVideoInduccion::query()
                ->where('user_id', $usuario->id)
                ->where('onboarding_avance_id', $avance->id)
                ->where('inicio_segundo', '<=', $fin)
                ->where('fin_segundo', '>=', $inicio)
                ->get();

            $nuevoInicio = min($solapados->min('inicio_segundo') ?? $inicio, $inicio);
            $nuevoFin = max($solapados->max('fin_segundo') ?? $fin, $fin);

            IntervaloVideoInduccion::query()->whereIn('id', $solapados->pluck('id')->all())->delete();

            IntervaloVideoInduccion::query()->create([
                'user_id' => $usuario->id,
                'onboarding_avance_id' => $avance->id,
                'inicio_segundo' => $nuevoInicio,
                'fin_segundo' => $nuevoFin,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function video(OnboardingAvance $avance): array
    {
        $progreso = (array) ($avance->refresh()->progreso_contenido ?? []);

        return (array) ($progreso['video'] ?? []);
    }

    /**
     * Mezcla cambios en progreso_contenido.video sin pisar el resto del rastro
     * (páginas vistas, audio).
     *
     * @param  array<string, mixed>  $cambios
     */
    private function actualizarVideo(OnboardingAvance $avance, array $cambios): void
    {
        $progreso = (array) ($avance->progreso_contenido ?? []);
        $video = (array) ($progreso['video'] ?? []);

        $avance->update(['progreso_contenido' => [
            ...$progreso,
            'video' => [...$video, ...$cambios],
        ]]);
    }
}
