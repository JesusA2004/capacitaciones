<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\EstadoUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\MoverColaboradoresDepartamentoRequest;
use App\Http\Requests\Administracion\StoreDepartamentoRequest;
use App\Http\Requests\Administracion\UpdateDepartamentoRequest;
use App\Models\Departamento;
use App\Services\Departamentos\MovimientoDepartamentoService;
use App\Support\Consultas\EstadisticasActivoInactivo;
use App\Support\Paginacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartamentoController extends Controller
{
    public function __construct(private readonly MovimientoDepartamentoService $movimientos) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Departamento::class);

        $departamentos = Departamento::query()
            // Personas vigentes (activo / en incorporación): la misma lista que
            // se ve —y se puede mover— al abrir el departamento.
            ->withCount(['puestos', 'colaboradores' => fn ($q) => $q->whereIn('estatus', EstadoUsuario::valoresVigentes())])
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->where('nombre', 'like', "%{$busqueda}%"))
            ->orderBy('nombre')
            ->paginate(Paginacion::porPagina())
            ->withQueryString();

        return Inertia::render('Administracion/Departamentos/Index', [
            'departamentos' => $departamentos,
            'filtros' => $request->only('busqueda'),
            'estadisticas' => EstadisticasActivoInactivo::de(Departamento::query()),
        ]);
    }

    /**
     * Detalle del departamento: sus personas vigentes (en el alcance de quien
     * mira) y, para RH, el flujo «mover personas a otro departamento».
     */
    public function show(Request $request, Departamento $departamento): Response
    {
        $this->authorize('view', $departamento);

        $usuario = $request->user();
        $puedeMover = $usuario->can('moverColaboradores', $departamento);

        return Inertia::render('Administracion/Departamentos/Show', [
            'departamento' => [
                'id' => $departamento->id,
                'nombre' => $departamento->nombre,
                'descripcion' => $departamento->descripcion,
                'activo' => $departamento->activo,
                'puestos' => $departamento->puestos()->count(),
            ],
            'colaboradores' => $this->movimientos->colaboradoresDe($departamento, $usuario),
            'destinos' => $puedeMover ? $this->movimientos->destinos($departamento) : [],
            'puedeMover' => $puedeMover,
        ]);
    }

    /**
     * Mueve en lote a las personas seleccionadas a otro departamento. El
     * resultado (movidos / omitidos con motivo) regresa como flash para
     * que la pantalla lo muestre sin perder el contexto.
     */
    public function moverColaboradores(MoverColaboradoresDepartamentoRequest $request, Departamento $departamento): RedirectResponse
    {
        $destino = Departamento::query()->whereKey($request->integer('departamento_destino_id'))->firstOrFail();

        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', (array) $request->validated('colaborador_ids')));

        $resultado = $this->movimientos->mover(
            $departamento,
            $destino,
            $ids,
            $request->user(),
            $request->validated('motivo'),
        );

        $movidos = count($resultado['movidos']);
        $omitidos = count($resultado['omitidos']);

        Inertia::flash('movimientoDepartamento', $resultado);

        return back()->with('toast', [
            'type' => $movidos > 0 ? 'success' : 'warning',
            'message' => $movidos > 0
                ? sprintf('%d %s a %s%s.', $movidos, $movidos === 1 ? 'persona movida' : 'personas movidas', $destino->nombre, $omitidos > 0 ? sprintf(' · %d omitida(s)', $omitidos) : '')
                : 'Nadie se movió: revisa los motivos de las personas omitidas.',
        ]);
    }

    public function store(StoreDepartamentoRequest $request): RedirectResponse
    {
        Departamento::create($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Departamento creado correctamente.']);
    }

    public function update(UpdateDepartamentoRequest $request, Departamento $departamento): RedirectResponse
    {
        $departamento->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Departamento actualizado correctamente.']);
    }

    public function destroy(Departamento $departamento): RedirectResponse
    {
        $this->authorize('delete', $departamento);

        $departamento->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Departamento eliminado correctamente.']);
    }
}
