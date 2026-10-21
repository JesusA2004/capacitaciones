<?php

namespace App\Services\Organigrama;

use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Tareas\TareaService;
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
        private readonly TareaService $tareas,
    ) {}

    /**
     * Recalcula el jefe directo de todas las personas vigentes y guarda los
     * que cambiaron. El personal de sucursal «sin jefe asignable» (ni
     * gerencia, ni regional, ni regional de respaldo) queda con jefe null y
     * con un pendiente para RH; nunca se le asigna Dirección Comercial.
     *
     * @return array{actualizados: int, sin_jefe: int, sin_jefe_asignable: list<int>}
     */
    public function sincronizar(?User $actor = null): array
    {
        $derivados = $this->organigrama->jefesDerivados();
        $sinJefeAsignable = $this->organigrama->sinJefeAsignable();

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

        $this->pendientesSinJefe($sinJefeAsignable, $actor);

        return [
            'actualizados' => count($cambios),
            'sin_jefe' => count(array_filter($derivados, fn (?int $jefe) => $jefe === null)) - count($sinJefeAsignable),
            'sin_jefe_asignable' => $sinJefeAsignable,
        ];
    }

    /**
     * Abre el pendiente «Sin jefe asignable» para RH (organigrama.editar) de
     * cada persona marcada y cierra los que ya tienen jefe. Un fallo aquí
     * nunca deshace la sincronización.
     *
     * @param  list<int>  $ids
     */
    private function pendientesSinJefe(array $ids, ?User $actor): void
    {
        try {
            $abiertas = TareaRh::query()
                ->whereNull('resuelta_en')
                ->where('tipo', TipoTarea::OrganigramaSinJefe->value)
                ->get();

            foreach ($abiertas as $tarea) {
                if (! in_array((int) $tarea->colaborador_id, $ids, true)) {
                    $this->tareas->resolverTarea($tarea, $actor);
                }
            }

            foreach (Colaborador::query()->whereIn('id', $ids)->get() as $persona) {
                $this->tareas->abrir(TipoTarea::OrganigramaSinJefe, $persona, [
                    'titulo' => sprintf('Sin jefe asignable: %s', $persona->nombreCompleto()),
                    'descripcion' => 'Su sucursal no tiene gerencia, su región no tiene Gerente Regional y no hay ninguna regional vigente. Registra al gerente o una cobertura «Cubre temporalmente» en el Organigrama.',
                    'prioridad' => PrioridadTarea::Alta,
                    'colaborador' => $persona,
                    'permiso' => 'organigrama.editar',
                    'accion' => 'revisar_organigrama',
                ]);
            }

            if ($ids !== []) {
                Log::warning('JefeDirectoService: personal de sucursal sin jefe asignable.', ['colaboradores' => $ids]);
            }
        } catch (Throwable $e) {
            Log::warning('JefeDirectoService: no se pudieron actualizar los pendientes de «sin jefe asignable».', ['error' => $e->getMessage()]);
        }
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
