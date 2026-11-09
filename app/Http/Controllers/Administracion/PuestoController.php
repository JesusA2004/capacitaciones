<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\EstadoUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\MoverColaboradoresPuestoRequest;
use App\Http\Requests\Administracion\StorePuestoRequest;
use App\Http\Requests\Administracion\UpdatePuestoRequest;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Headcount\PlantillaPuestoService;
use App\Services\Organigrama\PuestosSinUbicarService;
use App\Services\Puestos\EliminacionPuestoService;
use App\Services\Puestos\FichaPuestoService;
use App\Services\Puestos\MovimientoPuestoService;
use App\Services\Salarios\PosicionSalarialService;
use App\Support\Consultas\EstadisticasActivoInactivo;
use App\Support\Paginacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organización → «Puestos y salarios»: catálogo de puestos y su tabulador
 * (mínimo, medio, máximo) en un solo módulo. El catálogo se autoriza con
 * puestos.*, los importes con salarios.* (se omiten si no hay permiso).
 */
class PuestoController extends Controller
{
    public function __construct(
        private readonly FichaPuestoService $ficha,
        private readonly MovimientoPuestoService $movimientos,
        private readonly PosicionSalarialService $posiciones,
        private readonly AuditoriaService $auditoria,
        private readonly EliminacionPuestoService $eliminacion,
        private readonly PlantillaPuestoService $plantilla,
        private readonly PuestosSinUbicarService $sinUbicar,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $verSalarios = $usuario->can('salarios.ver');
        abort_unless($usuario->can('puestos.administrar') || $verSalarios, 403);

        $filas = $verSalarios ? $this->posiciones->posiciones($usuario) : collect();
        $tabulador = $verSalarios ? $this->ficha->tabuladorPorPuesto($filas) : collect();
        $estado = $request->string('estado')->toString();
        $departamentoId = $request->integer('departamento') ?: null;

        $puestos = Puesto::query()
            ->withCount(['colaboradores' => fn ($q) => $q->whereIn('estatus', EstadoUsuario::valoresVigentes())])
            ->with('departamento:id,nombre')
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->where('nombre', 'like', "%{$busqueda}%"))
            ->when($departamentoId !== null, fn ($query) => $query->where('departamento_id', $departamentoId))
            ->when(in_array($estado, ['activos', 'inactivos'], true), fn ($query) => $query->where('activo', $estado === 'activos'))
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->paginate(Paginacion::porPagina())
            ->withQueryString();

        // Plazas autorizadas / vacantes de los puestos de esta página (Headcount).
        $plazas = $this->plantilla->resumenPorPuestos($puestos->getCollection()->pluck('id')->map(fn ($id) => (int) $id));

        $puestos = $puestos
            ->through(function (Puesto $puesto) use ($tabulador, $verSalarios, $plazas): array {
                /** @var array<string, mixed>|null $renglon */
                $renglon = $tabulador->get($puesto->id);

                return [
                    'id' => $puesto->id,
                    'nombre' => $puesto->nombre,
                    'departamento_id' => $puesto->departamento_id,
                    'departamento' => $puesto->departamento?->only(['id', 'nombre']),
                    'descripcion' => $puesto->descripcion,
                    'activo' => $puesto->activo,
                    'colaboradores_count' => (int) $puesto->getAttribute('colaboradores_count'),
                    'meses_periodo_prueba' => $puesto->meses_periodo_prueba,
                    'plazas' => $plazas[$puesto->id] ?? ['autorizadas' => 0, 'ocupadas' => 0, 'vacantes' => 0],
                    'tabulador' => $verSalarios && $renglon !== null && $renglon['version'] !== null ? [
                        'minimo' => $renglon['minimo'],
                        'medio' => $renglon['medio'],
                        'maximo' => $renglon['maximo'],
                        'compa_ratio' => $renglon['compa_ratio'],
                    ] : null,
                ];
            });

        if ($verSalarios) {
            $this->auditoria->registrar('salarios.consultado', null, $usuario, ['puesto_id' => null, 'sucursal_id' => null]);
        }

        return Inertia::render('Administracion/Puestos/Index', [
            'puestos' => $puestos,
            'puede' => [
                'crear' => $usuario->can('create', Puesto::class),
                'editar' => $usuario->can('puestos.administrar') && $usuario->can('puestos.editar'),
                'eliminar' => $usuario->can('puestos.administrar') && $usuario->can('puestos.eliminar'),
                'ver_salarios' => $verSalarios,
                'editar_salarios' => $usuario->can('salarios.editar'),
                'exportar_salarios' => $usuario->can('salarios.exportar'),
                'importar_salarios' => $usuario->can('salarios.importar'),
            ],
            'filtros' => [
                'busqueda' => $request->string('busqueda')->toString(),
                'departamento' => $departamentoId,
                'estado' => $estado,
            ],
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'estadisticas' => EstadisticasActivoInactivo::de(Puesto::query()),
            'salarios' => $verSalarios ? $this->ficha->salarios($filas, $usuario, $request->integer('importacion') ?: null) : null,
        ]);
    }

    /**
     * Ficha del puesto: quién lo ocupa (todos, con traslado en lote), su
     * tabulador y sus datos del catálogo, en una sola pantalla.
     */
    public function show(Request $request, Puesto $puesto): Response
    {
        $usuario = $request->user();
        $verSalarios = $usuario->can('salarios.ver');
        abort_unless($usuario->can('puestos.administrar') || $verSalarios, 403);

        $puesto->load('departamento:id,nombre');
        $puedeMover = $usuario->can('moverColaboradores', $puesto);

        if ($verSalarios) {
            $this->auditoria->registrar('salarios.consultado', null, $usuario, ['puesto_id' => $puesto->id, 'sucursal_id' => null]);
        }

        return Inertia::render('Administracion/Puestos/Show', [
            'puesto' => [
                'id' => $puesto->id,
                'nombre' => $puesto->nombre,
                'descripcion' => $puesto->descripcion,
                'departamento_id' => $puesto->departamento_id,
                'departamento' => $puesto->departamento?->nombre,
                'activo' => $puesto->activo,
                'meses_periodo_prueba' => $puesto->meses_periodo_prueba,
            ],
            'personas' => $this->ficha->personas($puesto, $usuario, $verSalarios),
            'destinos' => $puedeMover ? $this->movimientos->destinos($puesto, $verSalarios) : [],
            'rango' => $verSalarios ? $this->ficha->rangoVigente($puesto) : null,
            'versiones' => $verSalarios ? $this->ficha->versiones($puesto) : [],
            'sucursales' => Sucursal::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            // «Plantilla autorizada» por ubicación (Corporativo incluido); se
            // captura con PlantillaPuestoController (solo headcount.editar).
            'plantilla' => $this->plantilla->dePuesto($puesto),
            // Si el puesto no cuelga de Dirección General, la ficha pide
            // ubicarlo («¿debajo de quién va?») para que sus plazas y
            // vacantes salgan en el organigrama.
            'sinUbicar' => collect($this->sinUbicar->listar())->firstWhere('id', $puesto->id),
            'abrirUbicacion' => $request->boolean('ubicar'),
            'puede' => [
                'ubicar' => $usuario->can('editarJerarquia', $puesto),
                'editar_plantilla' => $usuario->can('headcount.editar'),
                'editar' => $usuario->can('update', $puesto),
                'eliminar' => $usuario->can('delete', $puesto),
                'mover' => $puedeMover,
                'ver_salarios' => $verSalarios,
                'editar_salarios' => $usuario->can('salarios.editar'),
                'exportar_salarios' => $usuario->can('salarios.exportar'),
            ],
        ]);
    }

    /**
     * Traslada en lote a las personas seleccionadas a otro puesto. El
     * resultado (trasladados / omitidos con motivo) regresa como flash.
     */
    public function moverColaboradores(MoverColaboradoresPuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $destino = Puesto::query()->whereKey($request->integer('puesto_destino_id'))->firstOrFail();

        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', (array) $request->validated('colaborador_ids')));

        $resultado = $this->movimientos->mover($puesto, $destino, $ids, $request->user(), $request->validated('motivo'));

        $movidos = count($resultado['movidos']);
        $omitidos = count($resultado['omitidos']);

        Inertia::flash('movimientoPuesto', $resultado);

        return back()->with('toast', [
            'type' => $movidos > 0 ? 'success' : 'warning',
            'message' => $movidos > 0
                ? sprintf('%d %s a %s%s.', $movidos, $movidos === 1 ? 'persona trasladada' : 'personas trasladadas', $destino->nombre, $omitidos > 0 ? sprintf(' · %d omitida(s)', $omitidos) : '')
                : 'Nadie se trasladó: revisa los motivos de las personas omitidas.',
        ]);
    }

    public function store(StorePuestoRequest $request): RedirectResponse
    {
        $puesto = Puesto::create($request->validated());

        // Un puesto nuevo aún no tiene lugar en el árbol: la ficha abre de
        // inmediato «¿Debajo de quién va?» (UbicacionPuestoService).
        return to_route('administracion.puestos.show', ['puesto' => $puesto, 'ubicar' => 1])
            ->with('toast', ['type' => 'success', 'message' => 'Puesto creado. Indica debajo de quién va en el organigrama y después captura sus plazas.']);
    }

    public function update(UpdatePuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $puesto->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Puesto actualizado correctamente.']);
    }

    /**
     * Bloquea si el puesto sigue en la estructura (personas, subordinados,
     * coberturas, matriz, candidatos); si no, libera sus plazas y cancela
     * sus vacantes en la misma transacción (EliminacionPuestoService).
     */
    public function destroy(Request $request, Puesto $puesto): RedirectResponse
    {
        $this->authorize('delete', $puesto);

        $resultado = $this->eliminacion->eliminar($puesto, $request->user());
        $canceladas = count($resultado['vacantes_canceladas']);

        return to_route('administracion.puestos.index')->with('toast', [
            'type' => 'success',
            'message' => $canceladas > 0
                ? sprintf('Puesto eliminado. %s: %d.', $canceladas === 1 ? 'Vacante abierta cancelada' : 'Vacantes abiertas canceladas', $canceladas)
                : 'Puesto eliminado correctamente.',
        ]);
    }
}
