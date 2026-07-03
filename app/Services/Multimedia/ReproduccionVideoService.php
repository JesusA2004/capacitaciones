<?php

namespace App\Services\Multimedia;

use App\Models\IntervaloVideoVisto;
use App\Models\Leccion;
use App\Models\SesionReproduccion;
use App\Models\User;
use App\Services\Capacitacion\ProgresoService;
use Illuminate\Support\Facades\DB;

/**
 * Control de avance de lecciones de video: calcula cuanto ha visto un
 * usuario de verdad (tramos unicos, fusionados) y hasta donde puede avanzar
 * legitimamente. El limite de avance (segundoMaximoAlcanzado + tolerancia)
 * es lo unico que ManifiestoHlsService/ReproduccionController usan para
 * decidir que segmentos HLS entregar, asi que el anti-adelanto queda
 * respaldado por el servidor y no solo por el reproductor del cliente.
 */
class ReproduccionVideoService
{
    public function __construct(private readonly ProgresoService $progresoService) {}

    public function iniciarSesion(User $usuario, Leccion $leccion, ?string $ip, ?string $userAgent): SesionReproduccion
    {
        return SesionReproduccion::create([
            'user_id' => $usuario->id,
            'leccion_id' => $leccion->id,
            'recurso_multimedia_id' => $leccion->recurso_multimedia_id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'iniciada_en' => now(),
            'ultima_posicion_segundos' => $this->segundoMaximoAlcanzado($usuario, $leccion),
        ]);
    }

    public function segundoMaximoAlcanzado(User $usuario, Leccion $leccion): int
    {
        return (int) (IntervaloVideoVisto::query()
            ->where('user_id', $usuario->id)
            ->where('leccion_id', $leccion->id)
            ->max('fin_segundo') ?? 0);
    }

    /**
     * Ultimo segundo hasta el cual el usuario puede avanzar legitimamente
     * (lo realmente visto mas una tolerancia por variaciones normales de
     * red/heartbeat). ReproduccionController usa este mismo limite tanto
     * para truncar las variantes HLS como para rechazar segmentos sueltos.
     */
    public function segundoMaximoPermitido(User $usuario, Leccion $leccion): int
    {
        return $this->segundoMaximoAlcanzado($usuario, $leccion) + (int) config('media.video.salto_tolerancia_segundos');
    }

    public function porcentajeVisto(User $usuario, Leccion $leccion): float
    {
        $duracion = $leccion->recursoMultimedia?->duracion_segundos;

        if (! $duracion) {
            return 0.0;
        }

        return round(min(100, ($this->segundosUnicosVistos($usuario, $leccion) / $duracion) * 100), 2);
    }

    /**
     * @return array{permitido: bool, posicion_permitida: int, segundo_maximo_permitido: int, porcentaje_visto: float, completada: bool}
     */
    public function registrarHeartbeat(User $usuario, SesionReproduccion $sesion, int $posicionReportada): array
    {
        $leccion = $sesion->leccion;
        $limitePermitido = $this->segundoMaximoPermitido($usuario, $leccion);

        if ($posicionReportada > $limitePermitido) {
            return [
                'permitido' => false,
                'posicion_permitida' => $limitePermitido,
                'segundo_maximo_permitido' => $limitePermitido,
                'porcentaje_visto' => $this->porcentajeVisto($usuario, $leccion),
                'completada' => $sesion->completada,
            ];
        }

        $inicioTramo = min($sesion->ultima_posicion_segundos, $posicionReportada);
        $finTramo = max($sesion->ultima_posicion_segundos, $posicionReportada);

        if ($finTramo > $inicioTramo) {
            $this->fusionarTramo($usuario, $leccion, $inicioTramo, $finTramo);
        }

        $sesion->update(['ultima_posicion_segundos' => $posicionReportada, 'ultimo_heartbeat_en' => now()]);

        $porcentaje = $this->porcentajeVisto($usuario, $leccion);
        $completada = $sesion->completada;

        if (! $completada && $porcentaje >= (float) config('media.video.completion_percent')) {
            $completada = $this->marcarLeccionCompletada($usuario, $sesion);
        }

        return [
            'permitido' => true,
            'posicion_permitida' => $posicionReportada,
            'segundo_maximo_permitido' => $this->segundoMaximoPermitido($usuario, $leccion),
            'porcentaje_visto' => $porcentaje,
            'completada' => $completada,
        ];
    }

    private function marcarLeccionCompletada(User $usuario, SesionReproduccion $sesion): bool
    {
        try {
            $this->progresoService->completarLeccion($usuario, $sesion->leccion);
        } catch (\RuntimeException) {
            // La leccion se bloqueo por requisitos entre el inicio de la sesion
            // y este heartbeat (caso raro); el video sigue viendose con normalidad.
            return false;
        }

        $sesion->update(['completada' => true, 'finalizada_en' => now()]);

        return true;
    }

    private function segundosUnicosVistos(User $usuario, Leccion $leccion): int
    {
        return (int) IntervaloVideoVisto::query()
            ->where('user_id', $usuario->id)
            ->where('leccion_id', $leccion->id)
            ->get()
            ->sum(fn (IntervaloVideoVisto $tramo) => $tramo->fin_segundo - $tramo->inicio_segundo);
    }

    private function fusionarTramo(User $usuario, Leccion $leccion, int $inicio, int $fin): void
    {
        DB::transaction(function () use ($usuario, $leccion, $inicio, $fin) {
            $solapados = IntervaloVideoVisto::query()
                ->where('user_id', $usuario->id)
                ->where('leccion_id', $leccion->id)
                ->where('inicio_segundo', '<=', $fin)
                ->where('fin_segundo', '>=', $inicio)
                ->get();

            $nuevoInicio = min($solapados->min('inicio_segundo') ?? $inicio, $inicio);
            $nuevoFin = max($solapados->max('fin_segundo') ?? $fin, $fin);

            IntervaloVideoVisto::query()->whereIn('id', $solapados->pluck('id'))->delete();

            IntervaloVideoVisto::create([
                'user_id' => $usuario->id,
                'leccion_id' => $leccion->id,
                'inicio_segundo' => $nuevoInicio,
                'fin_segundo' => $nuevoFin,
            ]);
        });
    }
}
