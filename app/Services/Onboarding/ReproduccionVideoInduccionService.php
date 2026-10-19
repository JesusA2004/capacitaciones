<?php

namespace App\Services\Onboarding;

use App\Models\IntervaloVideoInduccion;
use App\Models\OnboardingAvance;
use App\Models\RecursoMultimedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Control de avance del VIDEO de la Inducción Mr. Lana (cuando el material
 * activo del módulo es video). Espejo dedicado de
 * App\Services\Multimedia\ReproduccionVideoService sobre
 * induccion_video_intervalos + OnboardingAvance, para NO tocar las tablas del
 * módulo de Capacitación (congelado tras feature:capacitacion).
 *
 * El límite de avance (segundoMaximoPermitido = lo realmente visto +
 * tolerancia) es lo único que el controlador usa para truncar las variantes
 * HLS y rechazar segmentos: el anti-adelanto queda respaldado por el servidor,
 * no solo por el reproductor. El progreso vive en el servidor (intervalos +
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
     * @return array{sesion_id: int, posicion_inicial: int, duracion_total_segundos: int|null, porcentaje_visto: float, segundo_maximo_permitido: int, heartbeat_segundos: int, completada: bool, url_manifiesto: string}
     */
    public function iniciarSesion(User $usuario, OnboardingAvance $avance): array
    {
        $recurso = $this->recurso($avance);
        $maximo = $this->segundoMaximoAlcanzado($usuario, $avance);

        $this->actualizarVideo($avance, ['ultima_posicion' => $maximo, 'duracion' => $recurso?->duracion_segundos]);

        return [
            'sesion_id' => $avance->id,
            'posicion_inicial' => $maximo,
            'duracion_total_segundos' => $recurso?->duracion_segundos,
            'porcentaje_visto' => $this->porcentajeVisto($usuario, $avance),
            'segundo_maximo_permitido' => $this->segundoMaximoPermitido($usuario, $avance),
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

    public function segundoMaximoPermitido(User $usuario, OnboardingAvance $avance): int
    {
        return $this->segundoMaximoAlcanzado($usuario, $avance) + (int) config('media.video.salto_tolerancia_segundos');
    }

    public function porcentajeVisto(User $usuario, OnboardingAvance $avance): float
    {
        $duracion = $this->recurso($avance)?->duracion_segundos;

        if (! $duracion) {
            return 0.0;
        }

        return round(min(100, ($this->segundosUnicosVistos($usuario, $avance) / $duracion) * 100), 2);
    }

    public function videoCompletado(OnboardingAvance $avance): bool
    {
        $video = $this->video($avance);

        return ($video['completado_en'] ?? null) !== null;
    }

    /**
     * @return array{permitido: bool, posicion_permitida: int, segundo_maximo_permitido: int, porcentaje_visto: float, completada: bool}
     */
    public function registrarHeartbeat(User $usuario, OnboardingAvance $avance, int $posicionReportada): array
    {
        $limitePermitido = $this->segundoMaximoPermitido($usuario, $avance);

        if ($posicionReportada > $limitePermitido) {
            // Intento de adelantar: ni se extienden los tramos ni se mueve el
            // ancla; el cliente debe regresar a la posición permitida.
            return [
                'permitido' => false,
                'posicion_permitida' => $limitePermitido,
                'segundo_maximo_permitido' => $limitePermitido,
                'porcentaje_visto' => $this->porcentajeVisto($usuario, $avance),
                'completada' => $this->videoCompletado($avance),
            ];
        }

        $video = $this->video($avance);
        $ancla = (int) ($video['ultima_posicion'] ?? $this->segundoMaximoAlcanzado($usuario, $avance));
        $inicioTramo = min($ancla, $posicionReportada);
        $finTramo = max($ancla, $posicionReportada);

        if ($finTramo > $inicioTramo) {
            $this->fusionarTramo($usuario, $avance, $inicioTramo, $finTramo);
        }

        $porcentaje = $this->porcentajeVisto($usuario, $avance);
        $completada = $this->videoCompletado($avance);
        $cambios = [
            'ultima_posicion' => $posicionReportada,
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
