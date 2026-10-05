<?php

namespace App\Services\Vacantes;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Models\HeadcountTarget;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Headcount\HeadcountService;
use App\Services\Headcount\PuestosPlantillaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincroniza vacantes AUTOMÁTICAS (`vacantes.generada_automaticamente = true`)
 * a partir del headcount (ver docs/HEADCOUNT_Y_VACANTES.md). Nunca toca
 * vacantes creadas a mano por RH — esas viven en su propio ciclo de vida
 * (VacanteController). Única regla:
 *
 *   faltantes = max(plantilla autorizada − ocupados reales, 0)
 *
 * - faltantes > 0 y no hay vacante automática abierta -> se abre una.
 * - faltantes = 0 y la automática sigue abierta -> se cierra (cubierta si
 *   alguien se contrató desde ella; si no, cancelada con su motivo).
 * - faltantes cambia (2->4, 4->1) -> la MISMA fila actualiza sus plazas;
 *   nunca hay dos automáticas abiertas para el mismo (sucursal, puesto).
 *
 * Se dispara solo al cambiar estatus/puesto/sucursal de cualquier
 * colaborador (ColaboradorPlantillaObserver: alta, baja, migración,
 * edición de expediente, movimiento) y al editar la plantilla autorizada;
 * `people:sincronizar-vacantes` recalcula todo (con --simular).
 */
class VacanteAutoGenerationService
{
    public function __construct(
        private readonly HeadcountService $headcount,
        private readonly PuestosPlantillaService $puestos,
    ) {}

    /**
     * Sincroniza un único (sucursal, puesto).
     *
     * @return array{sucursal_id: int, puesto_id: int, autorizada: int, ocupada: int, faltantes: int, accion: string, vacante_id: int|null, plazas_antes: int|null, plazas_despues: int|null}
     */
    public function sincronizar(int $sucursalId, int $puestoId): array
    {
        // Una baja/alta de un Gestor volante mueve la vacante de Gestor:
        // es la misma plaza (config/headcount.php).
        $puestoId = $this->puestos->canonico($puestoId);

        return DB::transaction(function () use ($sucursalId, $puestoId): array {
            $this->unificarDuplicadas($sucursalId, $puestoId);
            $plantilla = $this->headcount->plantillaDePar($sucursalId, $puestoId);
            $faltantes = $plantilla['faltantes'];
            $resultado = [
                'sucursal_id' => $sucursalId, 'puesto_id' => $puestoId,
                'autorizada' => $plantilla['autorizada'], 'ocupada' => $plantilla['ocupada'], 'faltantes' => $faltantes,
                'accion' => 'sin_cambios', 'vacante_id' => null, 'plazas_antes' => null, 'plazas_despues' => null,
            ];

            $vacanteAutomatica = Vacante::query()
                ->where('sucursal_id', $sucursalId)
                ->where('puesto_id', $puestoId)
                ->where('generada_automaticamente', true)
                ->whereIn('estado', EstadoVacante::valoresAbiertos())
                ->lockForUpdate()
                ->first();

            $sucursalActiva = Sucursal::query()->where('id', $sucursalId)->where('activo', true)->exists();

            if ($faltantes > 0 && $vacanteAutomatica === null && $sucursalActiva) {
                $target = HeadcountTarget::query()
                    ->where('sucursal_id', $sucursalId)
                    ->where('puesto_id', $puestoId)
                    ->first();

                $vacante = Vacante::create([
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

                return [...$resultado, 'accion' => 'abierta', 'vacante_id' => $vacante->id, 'plazas_antes' => 0, 'plazas_despues' => $faltantes];
            }

            if ($vacanteAutomatica === null) {
                return $resultado;
            }

            $resultado = [...$resultado, 'vacante_id' => $vacanteAutomatica->id, 'plazas_antes' => $vacanteAutomatica->plazas_disponibles];

            // Informativo, no se resta del faltante: el faltante ya baja
            // solo cuando el candidato contratado queda como colaborador
            // activo, contarlo aquí serviría doble.
            $cubiertas = $vacanteAutomatica->candidatos()->where('estado', EstadoCandidato::Contratado->value)->count();

            if ($faltantes === 0 || ! $sucursalActiva) {
                // Plaza ocupada con candidatos contratados desde esta vacante
                // → «cubierta»; si el faltante desapareció por otra vía (alta
                // directa, migración, ajuste de headcount, movimiento
                // interno) → cancelada con su motivo.
                $cubiertaPorContratacion = $cubiertas > 0 || $vacanteAutomatica->colaborador_contratado_id !== null;
                $motivo = $sucursalActiva
                    ? sprintf('Cerrada automáticamente: %d de %d plazas autorizadas ocupadas.', $plantilla['ocupada'], $plantilla['autorizada'])
                    : 'Cerrada automáticamente: la sucursal está inactiva.';

                $vacanteAutomatica->update([
                    'estado' => $cubiertaPorContratacion ? EstadoVacante::Cubierta->value : EstadoVacante::Cancelada->value,
                    'motivo_cancelacion' => $cubiertaPorContratacion ? null : $motivo,
                    'plazas_requeridas' => 0,
                    'plazas_cubiertas' => $cubiertas,
                    'plazas_disponibles' => 0,
                    'fecha_cierre' => now()->toDateString(),
                ]);

                return [...$resultado, 'accion' => 'cerrada', 'plazas_despues' => 0];
            }

            if ($vacanteAutomatica->plazas_disponibles === $faltantes && $vacanteAutomatica->plazas_requeridas === $faltantes && $vacanteAutomatica->plazas_cubiertas === $cubiertas) {
                return [...$resultado, 'plazas_despues' => $faltantes];
            }

            // Sigue haciendo falta, pero cambió el número de plazas (2->4, 4->1).
            $vacanteAutomatica->update([
                'plazas_requeridas' => $faltantes,
                'plazas_cubiertas' => $cubiertas,
                'plazas_disponibles' => $faltantes,
            ]);

            return [...$resultado, 'accion' => 'actualizada', 'plazas_despues' => $faltantes];
        });
    }

    /**
     * Igual que sincronizar(), pero un fallo nunca deshace la acción que lo
     * provocó (alta, baja, edición de expediente): se registra en el log y
     * `people:sincronizar-vacantes` lo corrige después.
     */
    public function sincronizarSinFallar(?int $sucursalId, ?int $puestoId): void
    {
        if ($sucursalId === null || $puestoId === null) {
            return;
        }

        try {
            $this->sincronizar($sucursalId, $puestoId);
        } catch (Throwable $e) {
            Log::warning('Vacantes: no se pudo sincronizar la vacante automática.', ['sucursal_id' => $sucursalId, 'puesto_id' => $puestoId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Nunca dos automáticas abiertas para la misma plaza: las de un puesto
     * equivalente (Gestor volante, de antes de unificar) pasan a ser la del
     * puesto de plantilla si éste no tiene una; cualquier otra duplicada se
     * cancela con su motivo (se conserva la del puesto de plantilla y, entre
     * iguales, la más antigua).
     */
    private function unificarDuplicadas(int $sucursalId, int $puestoId): void
    {
        $abiertas = Vacante::query()
            ->where('sucursal_id', $sucursalId)
            ->whereIn('puesto_id', $this->puestos->equivalentes($puestoId))
            ->where('generada_automaticamente', true)
            ->whereIn('estado', EstadoVacante::valoresAbiertos())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->sortBy(fn (Vacante $v) => $v->puesto_id === $puestoId ? 0 : 1)
            ->values();

        foreach ($abiertas as $indice => $vacante) {
            if ($indice === 0) {
                if ($vacante->puesto_id !== $puestoId) {
                    $vacante->update(['puesto_id' => $puestoId]);
                }

                continue;
            }

            $vacante->update([
                'estado' => EstadoVacante::Cancelada->value,
                'motivo_cancelacion' => 'Unificada: ya hay otra vacante automática abierta para la misma plaza.',
                'plazas_requeridas' => 0,
                'plazas_disponibles' => 0,
                'fecha_cierre' => now()->toDateString(),
            ]);
        }
    }

    /**
     * Recorre TODOS los pares (sucursal, puesto) con plantilla autorizada o
     * con una vacante automática abierta (aunque ya no tenga plantilla) y
     * sincroniza cada uno. Con $simular corre exactamente la misma lógica
     * dentro de una transacción que se revierte: lo reportado es lo que
     * pasaría, sin escribir nada.
     *
     * @return array{abiertas: int, cerradas: int, actualizadas: int, sin_cambios: int, cambios: list<array<string, mixed>>}
     */
    public function sincronizarTodo(bool $simular = false): array
    {
        $conVacante = Vacante::query()
            ->where('generada_automaticamente', true)
            ->whereIn('estado', EstadoVacante::valoresAbiertos())
            ->whereNotNull('sucursal_id')
            ->whereNotNull('puesto_id')
            ->get(['sucursal_id', 'puesto_id'])
            ->map(fn (Vacante $v) => ['sucursal_id' => (int) $v->sucursal_id, 'puesto_id' => $this->puestos->canonico((int) $v->puesto_id)]);

        $pares = $this->headcount->paresConTarget()
            ->merge($conVacante)
            ->unique(fn (array $par) => sprintf('%d:%d', $par['sucursal_id'], $par['puesto_id']))
            ->values();

        $reporte = ['abiertas' => 0, 'cerradas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0, 'cambios' => []];

        DB::beginTransaction();

        try {
            foreach ($pares as $par) {
                $resultado = $this->sincronizar($par['sucursal_id'], $par['puesto_id']);
                $clave = match ($resultado['accion']) {
                    'abierta' => 'abiertas',
                    'cerrada' => 'cerradas',
                    'actualizada' => 'actualizadas',
                    default => 'sin_cambios',
                };
                $reporte[$clave]++;

                if ($resultado['accion'] !== 'sin_cambios') {
                    $reporte['cambios'][] = $resultado;
                }
            }

            $simular ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $reporte;
    }
}
