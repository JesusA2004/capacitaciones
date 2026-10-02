<?php

namespace App\Services\Organigrama;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * El jefe directo NO se captura: sale del organigrama (puesto superior +
 * sucursal/región, ver OrganigramaPersonasService::jefesDerivados()). Si a
 * alguien se le cambia el puesto o la sucursal, o se mueve el "reporta a"
 * de un puesto, su jefe (y el de quienes le reportan) se recalcula solo.
 *
 * `colaboradores.jefe_id` se conserva como copia materializada porque
 * aprobaciones, notificaciones y alcance lo leen directo; esta clase es la
 * única que lo escribe. Cada cambio queda en la bitácora
 * (jefe_directo_cambiado) con el jefe anterior y el nuevo.
 */
class JefeDirectoService
{
    public const MOTIVO = 'Derivado del organigrama';

    public function __construct(
        private readonly OrganigramaPersonasService $organigrama,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Recalcula el jefe directo de todas las personas vigentes y guarda los
     * que cambiaron.
     *
     * @return array{actualizados: int, sin_jefe: int}
     */
    public function sincronizar(?User $actor = null): array
    {
        $derivados = $this->organigrama->jefesDerivados();

        $actuales = Colaborador::query()
            ->whereIn('id', array_keys($derivados))
            ->pluck('jefe_id', 'id')
            ->map(fn ($id) => $id === null ? null : (int) $id);

        $cambios = [];

        foreach ($derivados as $colaboradorId => $jefeId) {
            if (($actuales[$colaboradorId] ?? null) !== $jefeId) {
                $cambios[$colaboradorId] = ['antes' => $actuales[$colaboradorId] ?? null, 'despues' => $jefeId];
            }
        }

        if ($cambios !== []) {
            DB::transaction(function () use ($cambios): void {
                foreach ($cambios as $colaboradorId => $cambio) {
                    // Query directo (no save()): no dispara de nuevo la
                    // sincronización ni el log de actividad del modelo.
                    Colaborador::query()->whereKey($colaboradorId)->update(['jefe_id' => $cambio['despues']]);
                }
            });

            $this->auditar($cambios, $actor);
        }

        return [
            'actualizados' => count($cambios),
            'sin_jefe' => count(array_filter($derivados, fn (?int $jefe) => $jefe === null)),
        ];
    }

    /**
     * Agenda UNA sincronización al terminar la petición/comando actual (un
     * alta masiva o un seeder no recalcula el organigrama por cada fila).
     * Un fallo nunca deshace la acción que la provocó: se registra en el log.
     */
    public function programar(): void
    {
        defer(function (): void {
            try {
                $this->sincronizar();
            } catch (Throwable $e) {
                Log::warning('JefeDirectoService: no se pudo sincronizar el jefe directo con el organigrama.', ['error' => $e->getMessage()]);
            }
        }, 'organigrama.sincronizar-jefes');
    }

    /**
     * @param  array<int, array{antes: int|null, despues: int|null}>  $cambios
     */
    private function auditar(array $cambios, ?User $actor): void
    {
        $ids = [];

        foreach ($cambios as $colaboradorId => $cambio) {
            $ids[] = $colaboradorId;
            $ids[] = $cambio['antes'];
            $ids[] = $cambio['despues'];
        }

        $personas = Colaborador::query()->whereIn('id', array_filter($ids))->get()->keyBy('id');

        foreach ($cambios as $colaboradorId => $cambio) {
            $persona = $personas->get($colaboradorId);

            if ($persona === null) {
                continue;
            }

            $this->auditoria->registrar('jefe_directo_cambiado', $persona, $actor, [
                'antes' => ['jefe_id' => $cambio['antes'], 'jefe' => $personas->get($cambio['antes'] ?? 0)?->nombreCompleto()],
                'despues' => ['jefe_id' => $cambio['despues'], 'jefe' => $personas->get($cambio['despues'] ?? 0)?->nombreCompleto()],
                'motivo' => self::MOTIVO,
            ]);
        }
    }
}
