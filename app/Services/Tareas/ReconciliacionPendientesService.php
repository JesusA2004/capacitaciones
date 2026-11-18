<?php

namespace App\Services\Tareas;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoExcepcionDireccionGeneral;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoIntervencionCandidato;
use App\Enums\EstadoUsuario;
use App\Enums\EtapaCicloLaboral;
use App\Enums\TipoTarea;
use App\Models\Candidato;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\IntervencionCandidato;
use App\Models\Reingreso;
use App\Models\SolicitudExcepcionPeriodoPrueba;
use App\Models\SolicitudInterna;
use App\Models\TareaRh;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Los pendientes se quitan solos (docs/PENDIENTES.md). Además de que cada
 * flujo resuelve su pendiente al completar la acción (TareaService::resolver),
 * esta reconciliación revisa TODOS los pendientes abiertos contra el estado
 * real y cierra los que ya no aplican:
 *
 *  1. el registro del pendiente ya no existe (borrado);
 *  2. la persona ya fue dada de baja (salvo los pendientes de su propio
 *     cierre, finiquito, reingreso y documentos de salida);
 *  3. el registro ya avanzó: candidato con proceso terminado, solicitud
 *     aprobada/rechazada/cancelada, cierre concluido, evaluación capturada o
 *     autorizada, contrato renovado/terminado, documento ya en otro paso,
 *     alta que ya no está en esa etapa…
 *
 * Nunca abre pendientes ni avisa a nadie; solo cierra (sin actor = sistema)
 * y deja el motivo en `datos.cierre_automatico`. Idempotente; un error con
 * un pendiente nunca detiene a los demás.
 */
class ReconciliacionPendientesService
{
    /** Pendientes que existen justamente porque la persona se va o volvió. */
    private const SOBREVIVEN_A_LA_BAJA = [
        TipoTarea::PlantillaFaltante,
        TipoTarea::FirmaPendiente, TipoTarea::ImpresionPendiente, TipoTarea::FirmaFisicaPendiente,
        TipoTarea::EnvioOriginalPendiente, TipoTarea::RecepcionOriginalPendiente, TipoTarea::EscaneoPendiente,
    ];

    /**
     * @return array{revisados: int, resueltos: int, motivos: array<string, int>}
     */
    public function reconciliar(): array
    {
        $resultado = ['revisados' => 0, 'resueltos' => 0, 'motivos' => []];

        TareaRh::query()
            ->whereNull('resuelta_en')
            ->with(['relacionado', 'colaborador'])
            ->chunkById(100, function (EloquentCollection $tareas) use (&$resultado): void {
                foreach ($tareas as $tarea) {
                    $resultado['revisados']++;

                    try {
                        $motivo = $this->motivoParaCerrar($tarea);
                    } catch (Throwable $e) {
                        Log::warning('ReconciliacionPendientes: no se pudo evaluar un pendiente.', ['tarea_id' => $tarea->id, 'error' => $e->getMessage()]);

                        continue;
                    }

                    if ($motivo !== null && $this->cerrar($tarea, $motivo)) {
                        $resultado['resueltos']++;
                        $resultado['motivos'][$motivo] = ($resultado['motivos'][$motivo] ?? 0) + 1;
                    }
                }
            });

        if ($resultado['resueltos'] > 0) {
            Log::info('pendientes:reconciliar cerró pendientes que ya no aplican.', $resultado);
        }

        return $resultado;
    }

    /**
     * Versión para «Mis pendientes»: corre como máximo una vez por intervalo
     * (config pendientes.reconciliar_cada_segundos) para que la lista y el
     * contador ya lleguen limpios. Nunca tumba la página.
     */
    public function reconciliarSiToca(): void
    {
        try {
            if (Cache::add('pendientes:reconciliados', true, now()->addSeconds((int) config('pendientes.reconciliar_cada_segundos', 120)))) {
                $this->reconciliar();
            }
        } catch (Throwable $e) {
            Log::warning('ReconciliacionPendientes: falló la limpieza al abrir Mis pendientes.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * null = el pendiente sigue vigente; si no, el motivo (texto corto en
     * español) por el que ya no aplica.
     */
    public function motivoParaCerrar(TareaRh $tarea): ?string
    {
        $relacionado = $tarea->relacionado_type !== null ? $tarea->relacionado : null;

        if ($tarea->relacionado_type !== null && $relacionado === null) {
            return 'El registro del pendiente ya no existe.';
        }

        if ($this->personaDeBaja($tarea)) {
            return 'La persona ya fue dada de baja.';
        }

        return $relacionado instanceof Model ? $this->motivoPorEstado($tarea->tipo, $relacionado) : null;
    }

    private function personaDeBaja(TareaRh $tarea): bool
    {
        $etapa = $tarea->tipo->etapa();

        if ($etapa === EtapaCicloLaboral::Cierre || $etapa === EtapaCicloLaboral::Reingreso || in_array($tarea->tipo, self::SOBREVIVEN_A_LA_BAJA, true)) {
            return false;
        }

        $colaborador = $tarea->colaborador;

        if (! $colaborador instanceof Colaborador) {
            return false;
        }

        return $colaborador->trashed()
            || $colaborador->estado_alta === EstadoAltaColaborador::Baja
            || $colaborador->estatus === EstadoUsuario::Inactivo;
    }

    private function motivoPorEstado(TipoTarea $tipo, Model $relacionado): ?string
    {
        return match (true) {
            $relacionado instanceof Candidato => $relacionado->estado->esTerminal() ? 'El proceso del candidato ya terminó.' : null,
            $relacionado instanceof IntervencionCandidato => $relacionado->estado !== EstadoIntervencionCandidato::Pendiente ? 'La intervención ya se resolvió.' : null,
            $relacionado instanceof SolicitudInterna => $relacionado->estado->esFinal() ? sprintf('La solicitud ya fue %s.', mb_strtolower($relacionado->estado->etiqueta())) : null,
            $relacionado instanceof CierreLaboral => $relacionado->estado->esFinal() ? 'El cierre laboral ya concluyó.' : null,
            $relacionado instanceof Reingreso => $relacionado->estado->esFinal() ? 'El reingreso ya se resolvió.' : null,
            $relacionado instanceof SolicitudExcepcionPeriodoPrueba => $relacionado->estado !== EstadoExcepcionDireccionGeneral::Pendiente ? 'La excepción ya se resolvió.' : null,
            $relacionado instanceof EvaluacionPeriodoPrueba => $this->motivoEvaluacion($tipo, $relacionado),
            $relacionado instanceof ContratoLaboral => in_array($tipo, [TipoTarea::ContratoPendiente, TipoTarea::ContratoPorVencer], true) && $relacionado->estado !== EstadoContratoLaboral::Vigente
                ? 'El contrato ya no está vigente (renovado, terminado o cancelado).'
                : null,
            $relacionado instanceof GeneratedDocument => $this->motivoDocumento($tipo, $relacionado),
            $relacionado instanceof Colaborador => $this->motivoAlta($tipo, $relacionado),
            default => null,
        };
    }

    private function motivoEvaluacion(TipoTarea $tipo, EvaluacionPeriodoPrueba $evaluacion): ?string
    {
        return match ($tipo) {
            TipoTarea::EvaluacionPendiente => in_array($evaluacion->estado, [EstadoEvaluacionPrueba::Pendiente, EstadoEvaluacionPrueba::Devuelta], true) ? null : 'La evaluación ya se capturó.',
            TipoTarea::EvaluacionPorAutorizar => $evaluacion->estado === EstadoEvaluacionPrueba::Capturada ? null : 'La evaluación ya no espera autorización.',
            default => null,
        };
    }

    /**
     * Cada paso del flujo documental tiene su estado; si el documento ya está
     * en otro, el pendiente de ese paso ya no aplica (FlujoDocumentalService).
     */
    private function motivoDocumento(TipoTarea $tipo, GeneratedDocument $documento): ?string
    {
        $estado = $documento->estado_flujo;

        if (! $estado instanceof EstadoFlujoDocumento) {
            return null;
        }

        $vigente = match ($tipo) {
            TipoTarea::FirmaPendiente => $estado === EstadoFlujoDocumento::PendienteFirmaColaborador,
            TipoTarea::ImpresionPendiente => in_array($estado, [EstadoFlujoDocumento::Generado, EstadoFlujoDocumento::PendienteImpresion], true),
            TipoTarea::FirmaFisicaPendiente => in_array($estado, [EstadoFlujoDocumento::Impreso, EstadoFlujoDocumento::PendienteFirmaFisica], true),
            // Flujo vigente: firmado físicamente = documento CONCLUIDO (ya no
            // se envía, recibe ni escanea); solo sigue lo que quedó a medias
            // en el flujo anterior.
            TipoTarea::EnvioOriginalPendiente, TipoTarea::RecepcionOriginalPendiente, TipoTarea::EscaneoPendiente => ! $estado->estaConcluido(),
            default => true,
        };

        return $vigente ? null : sprintf('El documento ya está en «%s».', $estado->etiqueta());
    }

    private function motivoAlta(TipoTarea $tipo, Colaborador $colaborador): ?string
    {
        return match ($tipo) {
            TipoTarea::ExpedienteIncompleto => in_array($colaborador->estado_alta, [EstadoAltaColaborador::PendienteDocumentos, EstadoAltaColaborador::DocumentacionEnRevision], true)
                ? null
                : 'El expediente ya no está pendiente de documentos.',
            TipoTarea::ActivacionPendiente => $colaborador->estado_alta === EstadoAltaColaborador::PendienteActivacion ? null : 'El alta ya no espera activación.',
            default => null,
        };
    }

    private function cerrar(TareaRh $tarea, string $motivo): bool
    {
        try {
            $tarea->update([
                'resuelta_en' => now(),
                'resuelta_por' => null,
                'clave_abierta' => null,
                'datos' => [...($tarea->datos ?? []), 'cierre_automatico' => $motivo],
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('ReconciliacionPendientes: no se pudo cerrar un pendiente.', ['tarea_id' => $tarea->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
