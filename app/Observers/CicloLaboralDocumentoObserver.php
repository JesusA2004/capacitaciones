<?php

namespace App\Observers;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoDocumento;
use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Tareas\TareaService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mantiene sincronizados, sin importar por qué camino cambió un documento
 * (web Inertia, API móvil, flujo documental, alta digital):
 *
 *  - el estado del alta del colaborador (EstadoAltaColaborador), que depende
 *    de expediente + contrato firmado;
 *  - la tarea "documento rechazado" del colaborador (se abre al rechazar y
 *    se resuelve al subir la nueva versión).
 *
 * Se registra en AppServiceProvider. Nunca lanza excepciones hacia la
 * operación que disparó el cambio.
 */
class CicloLaboralDocumentoObserver
{
    public function savedEmployeeDocument(EmployeeDocument $documento): void
    {
        if (! $documento->wasChanged('status') && ! $documento->wasRecentlyCreated) {
            return;
        }

        try {
            $tareas = app(TareaService::class);
            $colaborador = $documento->colaborador;

            if ($documento->status === EstadoDocumento::Rechazado || $documento->status === EstadoDocumento::RequiereCorreccion) {
                $documento->loadMissing(['tipo', 'colaborador.user']);

                $tareas->abrir(TipoTarea::DocumentoRechazado, $documento, [
                    'titulo' => sprintf('Documento rechazado: %s', $documento->tipo->nombre),
                    'descripcion' => $documento->rejection_reason ?? $documento->comments,
                    'prioridad' => PrioridadTarea::Alta,
                    'colaborador' => $colaborador,
                    'usuario' => $colaborador?->user,
                    'permiso' => $colaborador?->user === null ? 'documentos.subir' : null,
                    'accion' => 'reemplazar_documento',
                ]);
            }

            // Una versión nueva reemplaza a la rechazada: su pendiente se cierra.
            if ($documento->wasRecentlyCreated && $documento->previous_version_id !== null) {
                $anterior = EmployeeDocument::query()->find($documento->previous_version_id);

                if ($anterior !== null) {
                    $tareas->resolver(TipoTarea::DocumentoRechazado, $anterior);
                }
            }

            $this->recalcular($colaborador);
        } catch (Throwable $e) {
            Log::warning('CicloLaboralDocumentoObserver: fallo al sincronizar expediente.', ['documento_id' => $documento->id, 'error' => $e->getMessage()]);
        }
    }

    public function updatedGeneratedDocument(GeneratedDocument $documento): void
    {
        if (! $documento->wasChanged('estado_flujo') || $documento->colaborador_id === null) {
            return;
        }

        try {
            $this->recalcular($documento->colaborador);
        } catch (Throwable $e) {
            Log::warning('CicloLaboralDocumentoObserver: fallo al recalcular el alta.', ['documento_id' => $documento->id, 'error' => $e->getMessage()]);
        }
    }

    private function recalcular(?Colaborador $colaborador): void
    {
        if ($colaborador === null || $colaborador->estado_alta === null) {
            return;
        }

        if (in_array($colaborador->estado_alta, [EstadoAltaColaborador::Activo, EstadoAltaColaborador::Baja], true)) {
            return;
        }

        app(AltaColaboradorService::class)->recalcularEstado($colaborador);
    }
}
