<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Enums\MotivoCobertura;
use App\Enums\TipoNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Coberturas temporales: alguien actúa en un puesto de otra sucursal o
 * región SIN dejar el suyo (el gerente de Córdoba cubre Cuernavaca; la
 * gerente regional de Q1 cubre también Q3). Nunca toca
 * `colaboradores.puesto_id` — el organigrama lo muestra en ambos lugares.
 */
class CoberturaPuestoService
{
    /**
     * @param  array{colaborador_id: int, puesto_id: int, sucursal_id?: int|null, region_id?: int|null, motivo: string, nota?: string|null, fecha_inicio?: string|null}  $datos
     */
    public function asignar(array $datos, User $actor): CoberturaPuesto
    {
        $colaborador = Colaborador::query()->where('id', $datos['colaborador_id'])->first();

        if ($colaborador === null || $colaborador->estatus !== EstadoUsuario::Activo) {
            throw ValidationException::withMessages(['colaborador_id' => 'Solo un colaborador activo puede cubrir un puesto.']);
        }

        $sucursalId = $datos['sucursal_id'] ?? null;
        $regionId = $datos['region_id'] ?? null;

        if ($sucursalId === null && $regionId === null) {
            throw ValidationException::withMessages(['sucursal_id' => 'Indica la sucursal o la región que se va a cubrir.']);
        }

        if ($regionId !== null && ! NodoComercial::query()->whereKey($regionId)->where('tipo', TipoNodoComercial::Region->value)->exists()) {
            throw ValidationException::withMessages(['region_id' => 'La región indicada no existe en la matriz comercial.']);
        }

        if ($sucursalId !== null && $colaborador->puesto_id === $datos['puesto_id'] && $colaborador->sucursal_principal_id === $sucursalId) {
            throw ValidationException::withMessages(['colaborador_id' => 'Esa persona ya es titular de ese puesto en esa sucursal.']);
        }

        // Bloqueo + verificación dentro de la misma transacción: dos RH
        // asignando a la vez no pueden dejar dos coberturas vigentes del
        // mismo puesto en el mismo ámbito.
        $cobertura = DB::transaction(function () use ($datos, $colaborador, $sucursalId, $regionId, $actor): CoberturaPuesto {
            $vigente = $this->vigenteEn($datos['puesto_id'], $sucursalId, $regionId, bloquear: true);

            if ($vigente !== null) {
                throw ValidationException::withMessages([
                    'colaborador_id' => sprintf(
                        'Ese puesto ya lo está cubriendo %s. Termina esa cobertura antes de asignar otra.',
                        trim(sprintf('%s %s', $vigente->colaborador->name, $vigente->colaborador->apellidos ?? '')),
                    ),
                ]);
            }

            return CoberturaPuesto::query()->create([
                'colaborador_id' => $colaborador->id,
                'puesto_id' => $datos['puesto_id'],
                'sucursal_id' => $sucursalId,
                'region_id' => $regionId,
                'motivo' => MotivoCobertura::from($datos['motivo']),
                'nota' => $datos['nota'] ?? null,
                'fecha_inicio' => $datos['fecha_inicio'] ?? now()->toDateString(),
                'activa' => true,
                'registrada_por' => $actor->id,
            ]);
        });

        activity('organigrama')
            ->performedOn($cobertura)
            ->causedBy($actor)
            ->withProperties(['colaborador_id' => $colaborador->id, 'puesto_id' => $datos['puesto_id'], 'sucursal_id' => $sucursalId, 'region_id' => $regionId])
            ->log('cobertura_asignada');

        return $cobertura;
    }

    public function finalizar(CoberturaPuesto $cobertura, User $actor): CoberturaPuesto
    {
        if (! $cobertura->activa) {
            return $cobertura;
        }

        $cobertura->update([
            'activa' => false,
            'fecha_fin' => now()->toDateString(),
            'finalizada_por' => $actor->id,
        ]);

        activity('organigrama')
            ->performedOn($cobertura)
            ->causedBy($actor)
            ->log('cobertura_finalizada');

        return $cobertura;
    }

    /**
     * Coberturas vigentes con su colaborador activo (si el que cubría ya se
     * dio de baja, su cobertura deja de mostrarse).
     *
     * @return Collection<int, CoberturaPuesto>
     */
    public function vigentes(): Collection
    {
        return CoberturaPuesto::query()
            ->where('activa', true)
            ->whereHas('colaborador', fn ($query) => $query->where('estatus', EstadoUsuario::Activo->value))
            ->with(['colaborador.sucursalPrincipal:id,nombre', 'puesto:id,nombre'])
            ->get();
    }

    private function vigenteEn(int $puestoId, ?int $sucursalId, ?int $regionId, bool $bloquear = false): ?CoberturaPuesto
    {
        return CoberturaPuesto::query()
            ->with('colaborador:id,name,apellidos')
            ->where('activa', true)
            ->where('puesto_id', $puestoId)
            ->when($sucursalId !== null, fn ($query) => $query->where('sucursal_id', $sucursalId))
            ->when($regionId !== null, fn ($query) => $query->where('region_id', $regionId))
            ->when($bloquear, fn ($query) => $query->lockForUpdate())
            ->first();
    }
}
