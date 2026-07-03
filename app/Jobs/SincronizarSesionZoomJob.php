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
 * Sincroniza la asistencia real de una sesión de Zoom vía la Report API. Se
 * dispara automáticamente por el Scheduler, manualmente desde el panel
 * administrativo, o encadenada desde ProcesarWebhookZoomJob cuando llega un
 * evento `meeting.ended`.
 */
class SincronizarSesionZoomJob implements ShouldQueue
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
