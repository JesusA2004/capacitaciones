<?php

namespace App\Console\Commands;

use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Vacante;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Puestos\EliminacionPuestoService;
use App\Support\FechaMexico;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repara puestos que se eliminaron ANTES de que eliminar un puesto liberara
 * sus plazas y vacantes (EliminacionPuestoService): RH → Vacantes mostraba
 * «Puesto sin definir · 1 plaza por cubrir». Seguro en producción:
 *
 * - Plazas autorizadas del puesto eliminado → 0, con renglón en la bitácora
 *   de plantilla (fuente `puesto_eliminado`).
 * - Vacantes abiertas del puesto eliminado (automáticas y manuales) y
 *   automáticas abiertas sin puesto → canceladas con motivo.
 * - Nunca borra filas; idempotente (una segunda corrida no encuentra nada).
 * - Solo REPORTA, sin tocar, lo que necesita una decisión de RH: personas
 *   vigentes o puestos que todavía apuntan al puesto eliminado.
 *
 *   php artisan people:reparar-puestos-eliminados --simular   # solo reporta
 *   php artisan people:reparar-puestos-eliminados             # aplica
 */
class RepararPuestosEliminadosCommand extends Command
{
    protected $signature = 'people:reparar-puestos-eliminados {--simular : Solo muestra qué cambiaría, sin escribir nada}';

    protected $description = 'Cancela vacantes y libera plazas de puestos eliminados del catálogo (idempotente, no borra historia)';

    public function handle(EliminacionPuestoService $eliminacion, AuditoriaService $auditoria): int
    {
        $simular = (bool) $this->option('simular');

        if ($simular) {
            $this->warn('Modo simulación: no se escribe nada.');
        }

        $filas = [];
        $pendientes = [];
        $sinPuesto = [];

        DB::beginTransaction();

        try {
            foreach (Puesto::onlyTrashed()->orderBy('id')->get() as $puesto) {
                $pendientes = [...$pendientes, ...$this->pendientesDe($puesto)];

                $tienePendiente = Vacante::query()->where('puesto_id', $puesto->id)->whereIn('estado', EstadoVacante::valoresAbiertos())->exists()
                    || HeadcountTarget::query()->where('puesto_id', $puesto->id)->where('plantilla_autorizada', '>', 0)->exists();

                if (! $tienePendiente) {
                    continue;
                }

                $resultado = $eliminacion->liberar($puesto, null, sprintf('El puesto «%s» se eliminó del catálogo (reparación).', $puesto->nombre));
                $filas[] = [
                    sprintf('#%d %s', $puesto->id, $puesto->nombre),
                    $resultado['plazas_liberadas'],
                    $resultado['vacantes_canceladas'] === [] ? '—' : implode(', ', array_map(fn (int $id) => '#'.$id, $resultado['vacantes_canceladas'])),
                ];
            }

            // Automáticas abiertas cuyo puesto ya no existe en absoluto (borrado físico → puesto_id null).
            foreach (Vacante::query()->whereNull('puesto_id')->where('generada_automaticamente', true)->whereIn('estado', EstadoVacante::valoresAbiertos())->lockForUpdate()->get() as $vacante) {
                $vacante->update([
                    'estado' => EstadoVacante::Cancelada->value,
                    'motivo_cancelacion' => 'Cancelada automáticamente: el puesto de la vacante ya no existe (reparación).',
                    'plazas_requeridas' => 0,
                    'plazas_disponibles' => 0,
                    'fecha_cierre' => FechaMexico::hoy(),
                ]);
                $sinPuesto[] = (int) $vacante->id;
            }

            $simular ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($filas === [] && $sinPuesto === []) {
            $this->info('Nada que reparar: ningún puesto eliminado conserva plazas ni vacantes abiertas.');
        } else {
            $this->table(['Puesto eliminado', 'Plazas liberadas', 'Vacantes canceladas'], $filas);

            if ($sinPuesto !== []) {
                $this->line(sprintf('Vacantes automáticas sin puesto canceladas: %s', implode(', ', array_map(fn (int $id) => '#'.$id, $sinPuesto))));
            }
        }

        if ($pendientes !== []) {
            $this->warn('Requieren decisión de RH (no se tocaron):');

            foreach ($pendientes as $pendiente) {
                $this->line(sprintf(' - %s', $pendiente));
            }
        }

        if (! $simular && ($filas !== [] || $sinPuesto !== [])) {
            $auditoria->registrar('puestos_eliminados_reparados', null, null, [
                'puestos' => array_map(fn (array $f) => $f[0], $filas),
                'vacantes_sin_puesto' => $sinPuesto,
            ]);
        }

        $this->info($simular ? 'Simulación terminada.' : 'Reparación terminada.');

        return self::SUCCESS;
    }

    /**
     * Lo que sigue apuntando al puesto eliminado y no se puede decidir solo.
     *
     * @return list<string>
     */
    private function pendientesDe(Puesto $puesto): array
    {
        $pendientes = [];
        $personas = Colaborador::query()->where('puesto_id', $puesto->id)->whereIn('estatus', EstadoUsuario::valoresVigentes())->count();

        if ($personas > 0) {
            $pendientes[] = sprintf('«%s» (eliminado) tiene %d persona(s) vigente(s): asígnales otro puesto.', $puesto->nombre, $personas);
        }

        $subordinados = Puesto::query()->where('puesto_superior_id', $puesto->id)->pluck('nombre');

        if ($subordinados->isNotEmpty()) {
            $pendientes[] = sprintf('«%s» (eliminado) sigue siendo el «reporta a» de: %s.', $puesto->nombre, $subordinados->implode(', '));
        }

        return $pendientes;
    }
}
