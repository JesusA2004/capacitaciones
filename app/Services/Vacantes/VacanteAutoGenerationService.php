<?php

namespace App\Services\Vacantes;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Models\HeadcountTarget;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Headcount\HeadcountService;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza vacantes AUTOMÁTICAS (`vacantes.generada_automaticamente = true`)
 * a partir del headcount (ver docs/HEADCOUNT_Y_VACANTES.md). Nunca toca
 * vacantes creadas a mano por RH — esas viven en su propio ciclo de vida
 * (VacanteController). Reglas:
 *
 * - autorizada > actual y no hay vacante automática abierta -> se abre una.
 * - autorizada baja o actual sube hasta igualar/superar la autorizada, y la
 *   vacante automática sigue abierta -> se cancela (no se "cubre": nadie la
 *   cubrió, simplemente dejó de hacer falta).
 * - autorizada sube más -> no duplica: ya hay como máximo una vacante
 *   automática abierta por (sucursal, puesto); esa misma fila actualiza su
 *   `plazas_requeridas`/`plazas_disponibles` para reflejar el faltante
 *   nuevo (2->4, 4->1, etc.), nunca se crea una segunda fila.
 */
class VacanteAutoGenerationService
{
    public function __construct(private readonly HeadcountService $headcount) {}

    /**
     * Sincroniza un único (sucursal, puesto) — se llama después de dar de
     * alta/baja a un colaborador o de editar un HeadcountTarget, para que
     * la vacante automática refleje el estado sin esperar a un comando
     * periódico.
     */
    public function sincronizar(int $sucursalId, int $puestoId): void
    {
        DB::transaction(function () use ($sucursalId, $puestoId) {
            $faltantes = $this->headcount->vacantesDerivadas($sucursalId, $puestoId);

            $vacanteAutomatica = Vacante::query()
                ->where('sucursal_id', $sucursalId)
                ->where('puesto_id', $puestoId)
                ->where('generada_automaticamente', true)
                ->whereIn('estado', [EstadoVacante::Abierta->value, EstadoVacante::EnReclutamiento->value, EstadoVacante::ConCandidatos->value, EstadoVacante::EnRevision->value])
                ->first();

            $sucursalActiva = Sucursal::query()->where('id', $sucursalId)->where('activo', true)->exists();

            if ($faltantes > 0 && $vacanteAutomatica === null && $sucursalActiva) {
                $target = HeadcountTarget::query()
                    ->where('sucursal_id', $sucursalId)
                    ->where('puesto_id', $puestoId)
                    ->first();

                Vacante::create([
                    'empresa_id' => $target?->empresa_id,
                    'sucursal_id' => $sucursalId,
                    'departamento_id' => $target?->departamento_id,
                    'puesto_id' => $puestoId,
                    'motivo' => MotivoVacante::Crecimiento->value,
                    'estado' => EstadoVacante::Abierta->value,
                    'fecha_apertura' => now(),
                    'observaciones' => 'Generada automáticamente: plantilla autorizada por encima de la actual.',
                    'generada_automaticamente' => true,
                    'headcount_target_id' => $target?->id,
                    'plazas_requeridas' => $faltantes,
                    'plazas_cubiertas' => 0,
                    'plazas_disponibles' => $faltantes,
                ]);

                return;
            }

            if ($vacanteAutomatica === null) {
                return;
            }

            // Informativo, no se resta del faltante: el faltante ya baja
            // solo cuando el candidato contratado queda como colaborador
            // activo (User.estatus), contarlo aquí serviría doble.
            $cubiertas = $vacanteAutomatica->candidatos()->where('estado', EstadoCandidato::Contratado->value)->count();

            if ($faltantes === 0) {
                $vacanteAutomatica->update([
                    'estado' => EstadoVacante::Cancelada->value,
                    'motivo_cancelacion' => 'Cerrada automáticamente: la plantilla actual ya alcanzó a la autorizada.',
                    'plazas_requeridas' => 0,
                    'plazas_cubiertas' => $cubiertas,
                    'plazas_disponibles' => 0,
                ]);

                return;
            }

            // Sigue haciendo falta la misma vacante automática, pero el
            // numero de plazas cambio (2->4, 4->1, etc.): se mantiene en
            // sincronia con el faltante real en cada llamada, no solo al
            // crearla.
            $vacanteAutomatica->update([
                'plazas_requeridas' => $faltantes,
                'plazas_cubiertas' => $cubiertas,
                'plazas_disponibles' => $faltantes,
            ]);
        });
    }

    /**
     * Recorre TODOS los pares (sucursal, puesto) con headcount configurado
     * y sincroniza cada uno. Usado por `headcount:importar` al terminar y
     * por un comando programado opcional.
     *
     * @return array{abiertas: int, cerradas: int}
     */
    public function sincronizarTodo(): array
    {
        $abiertas = 0;
        $cerradas = 0;

        foreach ($this->headcount->paresConTarget() as $par) {
            $antesAbierta = Vacante::query()
                ->where('sucursal_id', $par['sucursal_id'])
                ->where('puesto_id', $par['puesto_id'])
                ->where('generada_automaticamente', true)
                ->whereIn('estado', [EstadoVacante::Abierta->value, EstadoVacante::EnReclutamiento->value, EstadoVacante::ConCandidatos->value, EstadoVacante::EnRevision->value])
                ->exists();

            $this->sincronizar($par['sucursal_id'], $par['puesto_id']);

            $despuesAbierta = Vacante::query()
                ->where('sucursal_id', $par['sucursal_id'])
                ->where('puesto_id', $par['puesto_id'])
                ->where('generada_automaticamente', true)
                ->whereIn('estado', [EstadoVacante::Abierta->value, EstadoVacante::EnReclutamiento->value, EstadoVacante::ConCandidatos->value, EstadoVacante::EnRevision->value])
                ->exists();

            if (! $antesAbierta && $despuesAbierta) {
                $abiertas++;
            } elseif ($antesAbierta && ! $despuesAbierta) {
                $cerradas++;
            }
        }

        return ['abiertas' => $abiertas, 'cerradas' => $cerradas];
    }
}
