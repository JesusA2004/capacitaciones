<?php

namespace App\Services\Tareas;

use App\Enums\EstadoAltaColaborador;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Expedientes\ExpedienteService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Barrido programado de la bandeja: asegura el pendiente "expediente
 * incompleto" de cada alta en proceso y recalcula su estado. Idempotente
 * gracias a la deduplicación de TareaService (clave_abierta única).
 */
class SincronizacionTareasService
{
    public function __construct(
        private readonly TareaService $tareas,
        private readonly ExpedienteService $expediente,
        private readonly AltaColaboradorService $altas,
    ) {}

    public function sincronizarExpedientesIncompletos(): int
    {
        $procesados = 0;

        Colaborador::query()
            ->whereIn('estado_alta', [EstadoAltaColaborador::PendienteDocumentos->value, EstadoAltaColaborador::DocumentacionEnRevision->value])
            ->with('user')
            ->chunkById(200, function ($colaboradores) use (&$procesados): void {
                foreach ($colaboradores as $colaborador) {
                    try {
                        $estado = $this->altas->recalcularEstado($colaborador);

                        if ($estado !== EstadoAltaColaborador::PendienteDocumentos) {
                            continue;
                        }

                        $faltantes = $this->expediente->estadoDocumental($colaborador)['faltantes'];

                        $this->tareas->abrir(TipoTarea::ExpedienteIncompleto, $colaborador, [
                            'titulo' => "Expediente incompleto: {$colaborador->nombreCompleto()}",
                            'descripcion' => sprintf('Faltan %d documento(s) obligatorio(s).', $faltantes),
                            'colaborador' => $colaborador,
                            'permiso' => 'documentos.revisar',
                            'accion' => 'revisar_expediente',
                        ]);

                        $procesados++;
                    } catch (Throwable $e) {
                        Log::warning('SincronizacionTareasService: fallo con un colaborador.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $procesados;
    }
}
