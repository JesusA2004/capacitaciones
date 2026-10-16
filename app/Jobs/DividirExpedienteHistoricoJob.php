<?php

namespace App\Jobs;

use App\Enums\EstadoDivisionHistorico;
use App\Models\ExpedienteHistorico;
use App\Models\User;
use App\Services\Expedientes\DivisionHistorico\DivisionExpedienteHistoricoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Divide en cola el PDF histórico por checklist (la lectura con OCR de un
 * PDF largo tarda minutos; nunca bloquea el request de RH).
 * docs/DIVISION_EXPEDIENTE_HISTORICO.md.
 */
class DividirExpedienteHistoricoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly ExpedienteHistorico $historico,
        public readonly User $usuario,
        public readonly bool $forzar = false,
    ) {}

    public function handle(DivisionExpedienteHistoricoService $division): void
    {
        $historico = $this->historico->fresh();

        if ($historico === null || ! $division->elegible($historico)) {
            return;
        }

        try {
            $corrida = $division->dividir($historico, $this->usuario, $this->forzar);
        } catch (Throwable $e) {
            // dividir() puede lanzar ANTES de crear la corrida (no elegible,
            // candado ocupado): el fallo nunca debe pasar inadvertido para la
            // cola. Lo registramos y lo marcamos como fallido en failed_jobs.
            Log::error('division_historico: fallo inesperado en el job.', ['expediente_historico_id' => $historico->id, 'error' => $e->getMessage()]);
            $this->fail($e);

            return;
        }

        // dividir() captura los errores de la corrida y la deja marcada como
        // Fallida (retorna normal, para no romper la pantalla de RH). El job,
        // en cambio, SÍ debe propagar el fallo a la cola: si la corrida no
        // quedó Completada, la registramos en failed_jobs para que se vea en el
        // monitor/alertas (con tries=1 no se reintenta; solo se deja constancia).
        if ($corrida->estado === EstadoDivisionHistorico::Fallida) {
            $mensaje = sprintf('División histórica %d falló: %s', $corrida->id, $corrida->error_message ?? 'sin detalle');
            Log::error('division_historico: la corrida quedó marcada como fallida.', ['expediente_historico_id' => $historico->id, 'division_id' => $corrida->id, 'error' => $corrida->error_message]);
            $this->fail(new RuntimeException($mensaje));
        }
    }
}
