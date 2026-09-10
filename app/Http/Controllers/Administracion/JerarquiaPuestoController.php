<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\ActualizarJerarquiaPuestoRequest;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Administracion\JerarquiaPuestoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class JerarquiaPuestoController extends Controller
{
    public function __construct(
        private readonly JerarquiaPuestoService $jerarquia,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Puesto::class);

        return Inertia::render('Administracion/JerarquiaPuestos/Index', [
            'puestos' => $this->jerarquia->arbol($request),
            'filtros' => $request->only($this->jerarquia->filtrosAceptados()),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            ],
        ]);
    }

    public function actualizar(ActualizarJerarquiaPuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $datos = $request->safe()->except(['respaldos', 'puestos_que_puede_cubrir']);

        $puesto->update($datos);

        if ($request->has('respaldos')) {
            $puesto->respaldos()->sync($request->input('respaldos', []));
        }

        if ($request->has('puestos_que_puede_cubrir')) {
            $puesto->puestosQuePuedeCubrir()->sync($request->input('puestos_que_puede_cubrir', []));
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Jerarquía del puesto actualizada correctamente.']);
    }

    /**
     * Historial relacionado con este puesto para el panel lateral del
     * árbol (ver docs/JERARQUIA_PUESTOS.md): cambios de jerarquía
     * (activity log), movimientos laborales hacia/desde este puesto, y
     * vacantes que ha generado.
     */
    public function historial(Puesto $puesto): JsonResponse
    {
        $this->authorize('view', $puesto);

        $cambiosJerarquia = Activity::query()
            ->where('subject_type', Puesto::class)
            ->where('subject_id', $puesto->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'description', 'properties', 'causer_id', 'created_at'])
            ->map(fn ($registro) => [
                'id' => $registro->id,
                'descripcion' => $registro->description,
                'cambios' => $registro->properties,
                'fecha' => $registro->created_at?->toDateTimeString(),
            ]);

        // Mismo set de relaciones que ExpedienteController::renderExpediente()
        // para que el frontend use un único tipo MovimientoLaboralItem sin
        // huecos según el tipo de movimiento (ver docs/MOVIMIENTOS_LABORALES.md).
        $movimientos = MovimientoLaboral::query()
            ->where(fn ($query) => $query->where('puesto_anterior_id', $puesto->id)->orWhere('puesto_nuevo_id', $puesto->id))
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
            ->limit(20)
            ->get();

        $vacantes = $puesto->vacantes()
            ->with(['empresa:id,nombre', 'sucursal:id,nombre'])
            ->orderByDesc('fecha_apertura')
            ->limit(20)
            ->get(['id', 'empresa_id', 'sucursal_id', 'motivo', 'estado', 'fecha_apertura', 'puesto_id']);

        return response()->json([
            'cambiosJerarquia' => $cambiosJerarquia,
            'movimientos' => $movimientos,
            'vacantes' => $vacantes,
        ]);
    }
}
