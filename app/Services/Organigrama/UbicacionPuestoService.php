<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Ubicar / mover puesto»: cambia a quién reporta un PUESTO (su lugar en el
 * árbol) y su departamento. Una sola regla para el árbol General, la vista
 * Por puestos y la bandeja «Puestos sin ubicar».
 *
 *  1. «¿Debajo de quién va?»: cualquier puesto activo que no sea él mismo ni
 *     uno de los que le reportan (directa o indirectamente: sería un ciclo).
 *  2. Departamento: se propone el del superior elegido; RH lo confirma o lo
 *     cambia.
 *  3. Vista previa sin guardar: superior y departamento antes → después,
 *     personas en el puesto y cómo cambia el jefe directo de quien sea
 *     (OrganigramaPersonasService::jefesDerivados con el «reporta a»
 *     simulado en memoria).
 *  4. Aplicar: en una transacción; queda en la bitácora del puesto
 *     (`puesto_reubicado` + el activity log de Puesto) y el jefe directo se
 *     recalcula al momento (JefeDirectoService), como al cambiar datos
 *     laborales.
 *
 * Nunca cambia personas ni expedientes: quien ocupa el puesto se mueve con él.
 *
 * @phpstan-type CambioJefe array{colaborador: string, puesto: string, antes: string|null, despues: string|null}
 */
class UbicacionPuestoService
{
    public function __construct(
        private readonly OrganigramaPersonasService $organigrama,
        private readonly MiniOrganigramaService $miniOrganigrama,
        private readonly JefeDirectoService $jefes,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Superiores posibles con su área y ocupantes (para el buscador) y los
     * departamentos para el paso 2.
     *
     * @return array{superiores: list<array{value: string, label: string, departamento_id: int|null, departamento: string|null, ocupantes: list<string>}>, departamentos: list<array{value: string, label: string}>, actual: array{superior_id: int|null, departamento_id: int|null}}
     */
    public function opciones(Puesto $puesto): array
    {
        $excluidos = $this->miniOrganigrama->descendientes($puesto->id);
        $candidatos = Puesto::query()
            ->where('activo', true)
            ->whereNotIn('id', $excluidos)
            ->with('departamento:id,nombre')
            ->orderByRaw('COALESCE(nivel_jerarquico, 99)')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'departamento_id', 'nivel_jerarquico']);

        $ocupantes = Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereIn('puesto_id', $candidatos->pluck('id'))
            ->orderBy('name')
            ->orderBy('apellidos')
            ->get(['id', 'name', 'apellidos', 'puesto_id'])
            ->groupBy('puesto_id');

        $superiores = [];

        foreach ($candidatos as $candidato) {
            $nombres = array_values(($ocupantes->get($candidato->id) ?? collect())->map(fn (Colaborador $c): string => $c->nombreCompleto())->all());
            $resumen = count($nombres) > 3 ? sprintf('%s y %d más', implode(', ', array_slice($nombres, 0, 3)), count($nombres) - 3) : implode(', ', $nombres);

            $superiores[] = [
                'value' => sprintf('%d', $candidato->id),
                'label' => sprintf(
                    '%s%s — %s',
                    $candidato->nombre,
                    $candidato->departamento !== null ? sprintf(' · %s', $candidato->departamento->nombre) : '',
                    $resumen !== '' ? $resumen : 'Vacante',
                ),
                'departamento_id' => $candidato->departamento_id,
                'departamento' => $candidato->departamento?->nombre,
                'ocupantes' => $nombres,
            ];
        }

        $departamentos = [];

        foreach (Departamento::query()->orderBy('nombre')->get(['id', 'nombre']) as $departamento) {
            $departamentos[] = ['value' => sprintf('%d', $departamento->id), 'label' => $departamento->nombre];
        }

        return [
            'superiores' => $superiores,
            'departamentos' => $departamentos,
            'actual' => ['superior_id' => $puesto->puesto_superior_id, 'departamento_id' => $puesto->departamento_id],
        ];
    }

    /**
     * Lo que cambiaría, sin guardar nada.
     *
     * @return array{puesto: string, superior_antes: string|null, superior_despues: string, departamento_antes: string|null, departamento_despues: string|null, personas: list<string>, cambios_jefe: list<CambioJefe>, sin_cambios: bool}
     */
    public function vistaPrevia(Puesto $puesto, int $superiorId, ?int $departamentoId): array
    {
        $superior = $this->validar($puesto, $superiorId);
        $departamentoId ??= $superior->departamento_id;

        $antes = $this->organigrama->jefesDerivados();
        $despues = $this->organigrama->jefesDerivados([], [$puesto->id => $superior->id]);
        $ids = [];

        foreach ($despues as $colaboradorId => $jefeId) {
            if (($antes[$colaboradorId] ?? null) !== $jefeId) {
                $ids[] = $colaboradorId;
            }
        }

        $relacionados = array_values(array_unique(array_filter([...$ids, ...array_map(fn (int $id) => $antes[$id] ?? null, $ids), ...array_map(fn (int $id) => $despues[$id] ?? null, $ids)])));
        $personas = Colaborador::query()->whereIn('id', $relacionados)->with('puesto:id,nombre')->get(['id', 'name', 'apellidos', 'puesto_id'])->keyBy('id');
        $cambios = [];

        foreach ($ids as $id) {
            $colaborador = $personas->get($id);

            if ($colaborador === null) {
                continue;
            }

            $cambios[] = [
                'colaborador' => $colaborador->nombreCompleto(),
                'puesto' => $colaborador->puesto->nombre ?? '',
                'antes' => isset($antes[$id]) ? $personas->get($antes[$id])?->nombreCompleto() : null,
                'despues' => isset($despues[$id]) ? $personas->get($despues[$id])?->nombreCompleto() : null,
            ];
        }

        $nombres = Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->where('puesto_id', $puesto->id)
            ->orderBy('name')
            ->orderBy('apellidos')
            ->get(['id', 'name', 'apellidos']);

        return [
            'puesto' => $puesto->nombre,
            'superior_antes' => $puesto->puesto_superior_id !== null ? Puesto::withTrashed()->whereKey($puesto->puesto_superior_id)->value('nombre') : null,
            'superior_despues' => $superior->nombre,
            'departamento_antes' => $puesto->departamento_id !== null ? Departamento::query()->whereKey($puesto->departamento_id)->value('nombre') : null,
            'departamento_despues' => $departamentoId !== null ? Departamento::query()->whereKey($departamentoId)->value('nombre') : null,
            'personas' => array_values($nombres->map(fn (Colaborador $c): string => $c->nombreCompleto())->all()),
            'cambios_jefe' => $cambios,
            'sin_cambios' => $puesto->puesto_superior_id === $superior->id && $puesto->departamento_id === $departamentoId,
        ];
    }

    /**
     * Aplica el cambio (auditable) y recalcula el jefe directo de todos.
     */
    public function ubicar(Puesto $puesto, int $superiorId, ?int $departamentoId, User $actor): Puesto
    {
        $superior = $this->validar($puesto, $superiorId);
        $departamentoId ??= $superior->departamento_id;
        $antes = ['puesto_superior_id' => $puesto->puesto_superior_id, 'departamento_id' => $puesto->departamento_id];

        DB::transaction(function () use ($puesto, $superior, $departamentoId, $antes, $actor): void {
            $puesto->update(['puesto_superior_id' => $superior->id, 'departamento_id' => $departamentoId]);

            $this->auditoria->registrar('puesto_reubicado', $puesto, $actor, [
                'antes' => $antes,
                'despues' => ['puesto_superior_id' => $superior->id, 'departamento_id' => $departamentoId],
                'superior' => $superior->nombre,
            ]);
        });

        // El «reporta a» ya quedó; si recalcular falla, la red diaria
        // (organigrama:sincronizar-jefes) lo corrige: nunca deshace el cambio.
        try {
            $this->jefes->sincronizar($actor);
        } catch (Throwable $e) {
            Log::warning('UbicacionPuestoService: no se pudo recalcular el jefe directo al mover el puesto.', ['puesto_id' => $puesto->id, 'error' => $e->getMessage()]);
        }

        return $puesto->refresh();
    }

    private function validar(Puesto $puesto, int $superiorId): Puesto
    {
        $superior = Puesto::query()->where('id', $superiorId)->where('activo', true)->first();

        if ($superior === null) {
            throw ValidationException::withMessages(['puesto_superior_id' => 'Elige un puesto activo del catálogo.']);
        }

        if ($superior->id === $puesto->id) {
            throw ValidationException::withMessages(['puesto_superior_id' => 'Un puesto no puede reportarse a sí mismo.']);
        }

        if (in_array($superior->id, $this->miniOrganigrama->descendientes($puesto->id), true)) {
            throw ValidationException::withMessages(['puesto_superior_id' => sprintf('«%s» le reporta (directa o indirectamente) a «%s»: se formaría un ciclo.', $superior->nombre, $puesto->nombre)]);
        }

        return $superior;
    }
}
