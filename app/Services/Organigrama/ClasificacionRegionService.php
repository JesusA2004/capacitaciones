<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Enums\TipoNodoComercial;
use App\Models\ClasificacionRegion;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\User;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Solicitudes\TareasSolicitudService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Q1 / Q3 = las dos REGIONES de la matriz comercial («Región Q1», «Región
 * Q3», nodos_comerciales tipo region; MatrizComercialSeeder), cada una ligada
 * a su puesto «Gerente Regional Q1/Q3» (nodos_comerciales.puesto_id). El
 * Gerente de Sucursal reporta a «el gerente regional de su región»: aquí RH
 * CLASIFICA de qué lado está cada gerente, con un clic.
 *
 * - Sin clasificar = «Por clasificar» (visible en el organigrama). Mientras
 *   tanto su jefe directo sale, provisionalmente, de la región de su
 *   sucursal en la matriz (la regla de siempre); nunca se clasifica solo.
 * - Clasificar recalcula el jefe directo (JefeDirectoService) y, si cambia,
 *   registra el movimiento «Cambio de jefe» en su expediente. Auditable:
 *   quién y cuándo (clasificada_por/updated_at + activity_log 'organigrama').
 */
class ClasificacionRegionService
{
    public function __construct(
        private readonly OrganigramaPersonasService $organigrama,
        private readonly JefeDirectoService $jefes,
        private readonly MovimientoLaboralService $movimientos,
    ) {}

    /**
     * Regiones de la matriz (Q1, Q3) para el selector y los chips.
     *
     * @return list<array{id: int, nombre: string, codigo: string}>
     */
    public function regiones(): array
    {
        return array_values(NodoComercial::query()
            ->where('tipo', TipoNodoComercial::Region->value)
            ->where('activa', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'region'])
            ->map(fn (NodoComercial $region): array => [
                'id' => $region->id,
                'nombre' => $region->nombre,
                'codigo' => $region->region ?? trim(str_ireplace(['Región', 'Region'], '', $region->nombre)),
            ])
            ->all());
    }

    /**
     * Clasifica (o reclasifica) a un gerente en Q1/Q3. `$regionId` null lo
     * regresa a «Por clasificar».
     */
    public function clasificar(Colaborador $colaborador, ?int $regionId, User $actor): ?ClasificacionRegion
    {
        if (! in_array($colaborador->estatus, [EstadoUsuario::Activo, EstadoUsuario::EnIncorporacion], true)) {
            throw ValidationException::withMessages(['colaborador_id' => 'Solo se clasifica a personal vigente.']);
        }

        if ($colaborador->puesto_id === null || ! $this->organigrama->esPuestoClasificable($colaborador->puesto_id)) {
            throw ValidationException::withMessages(['colaborador_id' => 'Solo se clasifica en Q1/Q3 a quien reporta a un gerente regional (Gerente de Sucursal).']);
        }

        if ($regionId !== null && ! NodoComercial::query()->whereKey($regionId)->where('tipo', TipoNodoComercial::Region->value)->exists()) {
            throw ValidationException::withMessages(['region_id' => 'Elige Q1 o Q3 (una región de la matriz comercial).']);
        }

        $anterior = ClasificacionRegion::query()->where('colaborador_id', $colaborador->id)->first();
        $regionAnterior = $anterior?->region_id;

        if ($regionAnterior === $regionId) {
            return $anterior;
        }

        $clasificacion = DB::transaction(function () use ($colaborador, $regionId, $actor, $anterior): ?ClasificacionRegion {
            $antes = $this->movimientos->snapshot($colaborador);

            if ($regionId === null) {
                $anterior?->delete();
                $resultado = null;
            } else {
                $resultado = ClasificacionRegion::query()->updateOrCreate(
                    ['colaborador_id' => $colaborador->id],
                    ['region_id' => $regionId, 'clasificada_por' => $actor->id],
                );
            }

            // El jefe sale del organigrama: se recalcula YA para que el
            // movimiento registre el cambio de jefe (si lo hubo).
            $this->jefes->sincronizar($actor);
            $this->movimientos->registrarCambioPuesto(
                $colaborador->fresh() ?? $colaborador,
                $antes,
                $actor,
                $this->motivo($regionId),
            );

            return $resultado;
        });

        activity('organigrama')
            ->performedOn($colaborador)
            ->causedBy($actor)
            ->withProperties(['antes' => ['region_id' => $regionAnterior], 'despues' => ['region_id' => $regionId]])
            ->log('region_clasificada');

        // Sus solicitudes (y las de su sucursal) detenidas por «Pendiente de
        // asignar región de aprobación» avanzan: se avisa al regional que
        // ahora puede actuar. Nunca revierte la clasificación.
        try {
            $ids = Colaborador::query()
                ->where('sucursal_principal_id', $colaborador->sucursal_principal_id)
                ->pluck('id')
                ->push($colaborador->id)
                ->all();
            app(TareasSolicitudService::class)->reevaluarPendientes($ids);
        } catch (Throwable $e) {
            Log::warning('ClasificacionRegionService: no se pudieron reevaluar las solicitudes pendientes.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
        }

        return $clasificacion;
    }

    private function motivo(?int $regionId): string
    {
        if ($regionId === null) {
            return 'Organigrama: regresa a «Por clasificar» (Q1/Q3)';
        }

        $nombre = NodoComercial::query()->whereKey($regionId)->value('nombre');

        return sprintf('Organigrama: clasificado en %s', is_string($nombre) ? $nombre : 'su región');
    }
}
