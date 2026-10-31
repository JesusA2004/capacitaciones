<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Enums\MotivoCobertura;
use App\Enums\TipoMovimientoLaboral;
use App\Enums\TipoNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\MovimientoLaboral;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Headcount\PlazasEmpresaService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Support\FechaMexico;
use Illuminate\Support\Carbon;
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
    public function __construct(
        private readonly MovimientoLaboralService $movimientos,
        private readonly PlazasEmpresaService $plazas,
    ) {}

    /**
     * @param  array{colaborador_id: int, puesto_id: int, sucursal_id?: int|null, region_id?: int|null, motivo?: string|null, nota?: string|null, fecha_inicio?: string|null}  $datos
     */
    public function asignar(array $datos, User $actor): CoberturaPuesto
    {
        $colaborador = Colaborador::query()->where('id', $datos['colaborador_id'])->first();

        if ($colaborador === null || $colaborador->estatus !== EstadoUsuario::Activo) {
            throw ValidationException::withMessages(['colaborador_id' => 'Solo un colaborador activo puede cubrir un puesto.']);
        }

        $sucursalId = $datos['sucursal_id'] ?? null;
        $regionId = $datos['region_id'] ?? null;

        // Un puesto CORPORATIVO (Asistente de Dirección General, Contraloría…)
        // se cubre sin sucursal ni región; uno de sucursal o de región, no.
        // (Se resuelve al vuelo: OrganigramaPersonasService depende de este
        // servicio y no puede inyectarse aquí.)
        if ($sucursalId === null && $regionId === null
            && app(OrganigramaPersonasService::class)->ambitoDePuesto($datos['puesto_id']) !== 'corporativo') {
            throw ValidationException::withMessages(['sucursal_id' => 'Indica la sucursal o la región que se va a cubrir.']);
        }

        if ($sucursalId === null && $regionId === null && $colaborador->puesto_id === $datos['puesto_id']) {
            throw ValidationException::withMessages(['colaborador_id' => 'Esa persona ya es titular de ese puesto.']);
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

            $nueva = CoberturaPuesto::query()->create([
                'colaborador_id' => $colaborador->id,
                'puesto_id' => $datos['puesto_id'],
                'sucursal_id' => $sucursalId,
                'region_id' => $regionId,
                // «Cubre temporalmente este puesto» no pide motivo ni fechas:
                // sin motivo es «Puesto vacante» y empieza hoy.
                'motivo' => MotivoCobertura::tryFrom((string) ($datos['motivo'] ?? '')) ?? MotivoCobertura::Vacante,
                'nota' => $datos['nota'] ?? null,
                'fecha_inicio' => $datos['fecha_inicio'] ?? FechaMexico::hoy(),
                'activa' => true,
                'registrada_por' => $actor->id,
            ]);

            // Auditable también en la línea de tiempo del expediente de quien
            // cubre (movimiento «Cobertura temporal», sin fecha de fin).
            $puesto = Puesto::query()->whereKey($datos['puesto_id'])->first();

            if ($puesto !== null) {
                $this->movimientos->registrarCoberturaTemporal($colaborador, $puesto, $actor, Carbon::parse($nueva->fecha_inicio->toDateString()), null, $nueva->nota);
            }

            return $nueva;
        });

        activity('organigrama')
            ->performedOn($cobertura)
            ->causedBy($actor)
            ->withProperties(['colaborador_id' => $colaborador->id, 'puesto_id' => $datos['puesto_id'], 'sucursal_id' => $sucursalId, 'region_id' => $regionId])
            ->log('cobertura_asignada');

        $this->plazas->resincronizar($datos['puesto_id']);

        return $cobertura;
    }

    public function finalizar(CoberturaPuesto $cobertura, User $actor): CoberturaPuesto
    {
        if (! $cobertura->activa) {
            return $cobertura;
        }

        DB::transaction(function () use ($cobertura, $actor): void {
            $cobertura->update([
                'activa' => false,
                'fecha_fin' => FechaMexico::hoy(),
                'finalizada_por' => $actor->id,
            ]);

            // Cierra el movimiento «Cobertura temporal» que la abrió.
            MovimientoLaboral::query()
                ->where('colaborador_id', $cobertura->colaborador_id)
                ->where('tipo_movimiento', TipoMovimientoLaboral::CoberturaTemporal->value)
                ->where('puesto_nuevo_id', $cobertura->puesto_id)
                ->whereNull('fecha_fin_cobertura')
                ->update(['fecha_fin_cobertura' => FechaMexico::hoy()]);
        });

        activity('organigrama')
            ->performedOn($cobertura)
            ->causedBy($actor)
            ->log('cobertura_finalizada');

        $this->plazas->resincronizar($cobertura->puesto_id);

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
            ->with(['colaborador.sucursalPrincipal:id,nombre', 'colaborador.puesto:id,nombre', 'puesto:id,nombre', 'registradaPor:id,name'])
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
            // Corporativo: el mismo puesto sin sucursal ni región.
            ->when($sucursalId === null && $regionId === null, fn ($query) => $query->whereNull('sucursal_id')->whereNull('region_id'))
            ->when($bloquear, fn ($query) => $query->lockForUpdate())
            ->first();
    }
}
