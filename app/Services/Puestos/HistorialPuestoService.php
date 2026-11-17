<?php

namespace App\Services\Puestos;

use App\Enums\TipoMovimientoLaboral;
use App\Models\Colaborador;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Salarios\AccesoSueldoService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Spatie\Activitylog\Models\Activity;

/**
 * Historial de un puesto para el panel lateral del árbol de jerarquía (ver
 * docs/JERARQUIA_PUESTOS.md): cambios de jerarquía (activity log),
 * movimientos laborales hacia/desde el puesto y vacantes que ha generado.
 * Los últimos 20 de cada uno, dentro del alcance de quien mira.
 */
class HistorialPuestoService
{
    private const LIMITE = 20;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AccesoSueldoService $sueldos,
    ) {}

    /**
     * @return array{cambiosJerarquia: SupportCollection<int, array{id: mixed, descripcion: mixed, cambios: mixed, fecha: mixed}>, movimientos: Collection<int, MovimientoLaboral>, vacantes: Collection<int, Vacante>}
     */
    public function historial(Puesto $puesto, User $usuario): array
    {
        $cambiosJerarquia = Activity::query()
            ->where('subject_type', Puesto::class)
            ->where('subject_id', $puesto->id)
            ->orderByDesc('created_at')
            ->limit(self::LIMITE)
            ->get(['id', 'description', 'properties', 'causer_id', 'created_at'])
            ->map(fn ($registro) => [
                'id' => $registro->id,
                'descripcion' => $registro->description,
                'cambios' => $registro->properties,
                'fecha' => $registro->created_at?->toDateTimeString(),
            ]);

        // Mismo set de relaciones que el expediente para que el frontend use
        // un único tipo MovimientoLaboralItem sin huecos según el tipo de
        // movimiento (ver docs/MOVIMIENTOS_LABORALES.md).
        $movimientos = MovimientoLaboral::query()
            ->where(fn ($query) => $query->where('puesto_anterior_id', $puesto->id)->orWhere('puesto_nuevo_id', $puesto->id))
            // Solo movimientos de personas de su alcance (expedientes) y,
            // sin acceso a sueldos, sin cambios de sueldo.
            ->whereIn('colaborador_id', $this->alcance->limitarExpedientesPorAlcance(Colaborador::withTrashed(), $usuario)->select('colaboradores.id'))
            ->when(! $this->sueldos->puedeVer($usuario), fn ($query) => $query->where('tipo_movimiento', '!=', TipoMovimientoLaboral::CambioSueldo->value))
            ->with([
                'colaborador:id,name,apellidos',
                'puestoAnterior:id,nombre', 'puestoNuevo:id,nombre',
                'sucursalAnterior:id,nombre', 'sucursalNueva:id,nombre',
                'departamentoAnterior:id,nombre', 'departamentoNuevo:id,nombre',
                'empresaAnterior:id,nombre', 'empresaNueva:id,nombre',
                'jefeAnterior:id,name,apellidos', 'jefeNuevo:id,name,apellidos',
                'vacante:id,puesto_id', 'vacante.puesto:id,nombre',
                'documento:id,original_name',
                'registradoPor:id,name,apellidos',
            ])
            ->orderByDesc('fecha_movimiento')
            ->limit(self::LIMITE)
            ->get();

        $vacantes = $this->alcance->limitarPorSucursal($puesto->vacantes()->getQuery(), $usuario, 'sucursal_id', 'vacantes')
            ->with(['empresa:id,nombre', 'sucursal:id,nombre'])
            ->orderByDesc('fecha_apertura')
            ->limit(self::LIMITE)
            ->get(['id', 'empresa_id', 'sucursal_id', 'motivo', 'estado', 'fecha_apertura', 'puesto_id']);

        return ['cambiosJerarquia' => $cambiosJerarquia, 'movimientos' => $movimientos, 'vacantes' => $vacantes];
    }
}
