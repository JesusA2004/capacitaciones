<?php

namespace App\Jobs;

use App\Models\RegistroSesion;
use App\Models\SesionEnVivo;
use App\Services\Reuniones\CalculoAsistenciaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recalcula el resultado de asistencia de una sesión a partir de los datos
 * de `sesiones_participante` YA recuperados del proveedor (sin volver a
 * llamar a la API externa). Responsabilidad separada de
 * Sincronizar{GoogleMeet,Zoom}Job a propósito: cuando un administrador
 * cambia las reglas de asistencia de una sesión ya sincronizada
 * (porcentaje mínimo, minutos, tolerancia, criterio), no hace falta volver
 * a consultar Google/Zoom — basta con reevaluar los datos ya guardados.
 * Ver App\Http\Controllers\Reuniones\SesionEnVivoController::update().
 */
class CalcularAsistenciasSesionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public int $backoff = 30;

    public function __construct(
        public readonly SesionEnVivo $sesion,
        public readonly RegistroSesion $registro,
    ) {}

    public function handle(CalculoAsistenciaService $servicio): void
    {
        $servicio->calcularParaSesion($this->sesion, $this->registro);
    }

    public function failed(\Throwable $excepcion): void
    {
        report($excepcion);
    }
}
