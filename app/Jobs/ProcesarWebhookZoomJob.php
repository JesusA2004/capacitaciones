<?php

namespace App\Jobs;

use App\Enums\EstadoWebhook;
use App\Enums\ProveedorSesion;
use App\Enums\TipoSincronizacionReunion;
use App\Models\SesionEnVivo;
use App\Models\WebhookRecibido;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Procesa un webhook de Zoom ya registrado (firma válida, evento no
 * duplicado) fuera de la petición HTTP original. Solo `meeting.ended`
 * dispara una sincronización real: la asistencia se calcula con la Report
 * API (ver ZoomAsistenciaSincronizador), no con los datos del propio
 * webhook, así que `participant_joined/left` únicamente se archivan como
 * evidencia de que Zoom los envió. Ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 2.
 */
class ProcesarWebhookZoomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const EVENTOS_QUE_DISPARAN_SINCRONIZACION = ['meeting.ended'];

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly WebhookRecibido $webhook) {}

    public function handle(): void
    {
        $this->webhook->update([
            'estado' => EstadoWebhook::Procesando,
            'intentos' => $this->webhook->intentos + 1,
        ]);

        $evento = $this->webhook->tipo;
        $objeto = $this->webhook->payload_normalizado['payload']['object'] ?? [];

        if (in_array($evento, self::EVENTOS_QUE_DISPARAN_SINCRONIZACION, true)) {
            $idReunionExterna = isset($objeto['id']) ? (string) $objeto['id'] : null;

            if ($idReunionExterna === null) {
                $this->marcarError('El webhook no trae el identificador de la reunión de Zoom.');

                return;
            }

            $sesion = SesionEnVivo::query()
                ->where('proveedor', ProveedorSesion::Zoom)
                ->where('id_reunion_externa', $idReunionExterna)
                ->first();

            if ($sesion === null) {
                throw new \RuntimeException("No existe ninguna sesión en vivo asociada a la reunión de Zoom {$idReunionExterna}.");
            }

            SincronizarSesionZoomJob::dispatch($sesion, TipoSincronizacionReunion::Webhook);
        }

        $this->webhook->update([
            'estado' => EstadoWebhook::Procesado,
            'procesado_en' => now(),
        ]);
    }

    public function failed(\Throwable $excepcion): void
    {
        $this->marcarError($excepcion->getMessage());
        report($excepcion);
    }

    private function marcarError(string $mensaje): void
    {
        $this->webhook->update([
            'estado' => EstadoWebhook::Error,
            'error' => $mensaje,
        ]);
    }
}
