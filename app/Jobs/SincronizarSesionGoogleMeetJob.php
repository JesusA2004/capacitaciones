<?php

namespace App\Jobs;

use App\Enums\TipoSincronizacionReunion;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Services\Reuniones\SincronizacionAsistenciaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sincroniza la asistencia real de una sesión de Google Meet: recupera el
 * conferenceRecord/participants/participantSessions y calcula el resultado
 * de asistencia. La idempotencia y el conteo de intentos viven en
 * `registros_sesion` (App\Services\Reuniones\SincronizacionAsistenciaService),
 * no solo en el mecanismo de reintento de la cola — así una sincronización
 * manual y una automática comparten el mismo límite de intentos.
 */
class SincronizarSesionGoogleMeetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly SesionEnVivo $sesion,
        public readonly TipoSincronizacionReunion $tipo = TipoSincronizacionReunion::Automatica,
        public readonly ?int $iniciadoPorId = null,
    ) {}

    public function handle(SincronizacionAsistenciaService $servicio): void
    {
        $servicio->sincronizar(
            $this->sesion,
            $this->tipo,
            $this->iniciadoPorId ? User::find($this->iniciadoPorId) : null,
            $this->job?->getJobId(),
        );
    }

    public function failed(\Throwable $excepcion): void
    {
        report($excepcion);
    }
}
