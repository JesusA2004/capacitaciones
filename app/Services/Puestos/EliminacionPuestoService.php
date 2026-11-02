<?php

namespace App\Services\Puestos;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Auditoria\AuditoriaService;
use App\Support\FechaMexico;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Eliminar un puesto del catálogo sin dejar rastros incoherentes en
 * Organigrama, Headcount y Vacantes (docs/HEADCOUNT_Y_VACANTES.md →
 * «Eliminar un puesto»).
 *
 * El puesto se elimina con SoftDeletes, así que las llaves foráneas
 * (`cascadeOnDelete`/`nullOnDelete`) NUNCA se disparan: sin este servicio
 * sus plazas autorizadas y su vacante automática seguían vivas y RH →
 * Vacantes mostraba «Puesto sin definir · 1 plaza por cubrir».
 *
 * - Se bloquea (con mensaje claro) si el puesto todavía forma parte de la
 *   estructura: personas vigentes, puestos que le reportan en el
 *   organigrama, coberturas temporales vigentes, nodos de la matriz
 *   comercial o candidatos en proceso en sus vacantes.
 * - Si se puede eliminar, en una sola transacción: sus plazas autorizadas
 *   quedan en 0 (con renglón en la bitácora de plantilla), sus vacantes
 *   abiertas —automáticas y manuales— se cancelan con motivo, la ruta de
 *   crecimiento que apuntaba a él se limpia y el puesto se desactiva y se
 *   elimina. Nunca se borra historia.
 */
class EliminacionPuestoService
{
    public const FUENTE_PLANTILLA = 'puesto_eliminado';

    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * Razones (en español, listas para mostrar) por las que el puesto no se
     * puede eliminar todavía. Vacío = se puede eliminar.
     *
     * @return list<string>
     */
    public function bloqueos(Puesto $puesto): array
    {
        $bloqueos = [];

        $personas = Colaborador::query()
            ->where('puesto_id', $puesto->id)
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->count();

        if ($personas > 0) {
            $bloqueos[] = sprintf('Tiene %d %s. Trasládalas a otro puesto antes de eliminarlo.', $personas, $personas === 1 ? 'persona vigente' : 'personas vigentes');
        }

        $subordinados = Puesto::query()
            ->where('puesto_superior_id', $puesto->id)
            ->orderBy('nombre')
            ->pluck('nombre');

        if ($subordinados->isNotEmpty()) {
            $bloqueos[] = sprintf(
                'En el organigrama le reportan: %s. Cambia primero a quién reportan (Organigrama → Por puestos).',
                $subordinados->implode(', '),
            );
        }

        if (CoberturaPuesto::query()->where('puesto_id', $puesto->id)->where('activa', true)->exists()) {
            $bloqueos[] = 'Alguien lo cubre temporalmente. Quita la cobertura en el organigrama antes de eliminarlo.';
        }

        if (NodoComercial::query()->where('puesto_id', $puesto->id)->where('activa', true)->exists()) {
            $bloqueos[] = 'Está ligado a la matriz comercial. Desvincúlalo de la región antes de eliminarlo.';
        }

        $enProceso = Vacante::query()
            ->where('puesto_id', $puesto->id)
            ->whereIn('estado', EstadoVacante::valoresAbiertos())
            ->whereHas('candidatos', fn ($q) => $q->whereIn('estado', $this->estadosCandidatoActivos()))
            ->count();

        if ($enProceso > 0) {
            $bloqueos[] = sprintf('Tiene %d %s con candidatos en proceso. Cierra o reasigna esos procesos antes de eliminarlo.', $enProceso, $enProceso === 1 ? 'vacante' : 'vacantes');
        }

        return $bloqueos;
    }

    /**
     * @return array{vacantes_canceladas: list<int>, plazas_liberadas: int}
     *
     * @throws ValidationException si el puesto todavía forma parte de la estructura.
     */
    public function eliminar(Puesto $puesto, User $actor): array
    {
        $resultado = DB::transaction(function () use ($puesto, $actor): array {
            // Bajo candado: nadie asigna personas ni subordinados a medio camino.
            $bloqueado = Puesto::query()->whereKey($puesto->id)->lockForUpdate()->firstOrFail();
            $bloqueos = $this->bloqueos($bloqueado);

            if ($bloqueos !== []) {
                throw ValidationException::withMessages(['puesto' => sprintf('No se puede eliminar «%s»: %s', $bloqueado->nombre, implode(' ', $bloqueos))]);
            }

            $resultado = $this->liberar($bloqueado, $actor, sprintf('El puesto «%s» se eliminó del catálogo.', $bloqueado->nombre));

            Puesto::query()->where('puesto_crecimiento_id', $bloqueado->id)->update(['puesto_crecimiento_id' => null]);

            $bloqueado->update(['activo' => false]);
            $bloqueado->delete();

            return $resultado;
        });

        $this->auditoria->registrar('puesto_eliminado', $puesto, $actor, [
            'puesto_id' => $puesto->id,
            'puesto' => $puesto->nombre,
            ...$resultado,
        ]);

        return $resultado;
    }

    /**
     * Deja sin plazas ni vacantes abiertas a un puesto que sale del
     * catálogo: plazas autorizadas en 0 (con bitácora) y vacantes abiertas
     * canceladas con motivo. Idempotente — la usa también el comando de
     * reparación `people:reparar-puestos-eliminados` para puestos que se
     * eliminaron antes de esta regla. Debe llamarse dentro de una transacción.
     *
     * @return array{vacantes_canceladas: list<int>, plazas_liberadas: int}
     */
    public function liberar(Puesto $puesto, ?User $actor, string $motivo): array
    {
        $vacantes = Vacante::query()
            ->where('puesto_id', $puesto->id)
            ->whereIn('estado', EstadoVacante::valoresAbiertos())
            ->lockForUpdate()
            ->get();

        foreach ($vacantes as $vacante) {
            $vacante->update([
                'estado' => EstadoVacante::Cancelada->value,
                'motivo_cancelacion' => sprintf('Cancelada automáticamente: %s', $motivo),
                'plazas_requeridas' => 0,
                'plazas_disponibles' => 0,
                'fecha_cierre' => FechaMexico::hoy(),
            ]);
        }

        $plazasLiberadas = 0;
        $targets = HeadcountTarget::query()
            ->where('puesto_id', $puesto->id)
            ->where('plantilla_autorizada', '>', 0)
            ->lockForUpdate()
            ->get();

        foreach ($targets as $target) {
            $anterior = $target->plantilla_autorizada;
            $plazasLiberadas += $anterior;

            $target->update([
                'plantilla_autorizada' => 0,
                'fuente' => self::FUENTE_PLANTILLA,
                'fecha_corte' => FechaMexico::hoy(),
                'updated_by_id' => $actor?->id,
            ]);

            HeadcountTargetHistorial::query()->create([
                'headcount_target_id' => $target->id,
                'sucursal_id' => $target->sucursal_id,
                'puesto_id' => $puesto->id,
                'valor_anterior' => $anterior,
                'valor_nuevo' => 0,
                'fuente' => self::FUENTE_PLANTILLA,
                'motivo' => $motivo,
                'user_id' => $actor?->id,
            ]);
        }

        return [
            'vacantes_canceladas' => array_values($vacantes->map(fn (Vacante $v): int => (int) $v->id)->all()),
            'plazas_liberadas' => $plazasLiberadas,
        ];
    }

    /**
     * @return list<string>
     */
    private function estadosCandidatoActivos(): array
    {
        return array_values(array_map(
            fn (EstadoCandidato $e) => $e->value,
            array_filter(EstadoCandidato::cases(), fn (EstadoCandidato $e) => ! $e->esTerminal()),
        ));
    }
}
