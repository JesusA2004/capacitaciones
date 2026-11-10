<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\EstadoUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\ActualizarPlantillaAutorizadaRequest;
use App\Http\Requests\Administracion\ConfigurarVolantesSucursalRequest;
use App\Http\Requests\Administracion\StoreSucursalRequest;
use App\Http\Requests\Administracion\UpdateSucursalRequest;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Headcount\HeadcountService;
use App\Services\Headcount\PlantillaAutorizadaService;
use App\Services\Headcount\VolantesSucursalService;
use App\Services\Sucursales\SucursalDetalleService;
use App\Support\Consultas\EstadisticasActivoInactivo;
use App\Support\Paginacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    public function __construct(
        private readonly HeadcountService $headcount,
        private readonly SucursalDetalleService $detalle,
        private readonly PlantillaAutorizadaService $plantilla,
        private readonly VolantesSucursalService $volantes,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Sucursal::class);

        $sucursales = Sucursal::query()
            // Personas vigentes (activo / en incorporación): mismo criterio
            // que la plantilla ocupada de HeadcountService.
            // y sin bajas registradas cuyo último día ya pasó (finiquito pendiente).
            ->withCount(['colaboradores' => fn ($q) => $q->whereIn('estatus', EstadoUsuario::valoresVigentes())->whereNotIn('id', $this->headcount->bajasAplicadas(true)->select('colaborador_id'))])
            ->with(['responsable:id,name,apellidos', 'empresa:id,nombre'])
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda) {
                    $sub->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('clave', 'like', "%{$busqueda}%");
                });
            })
            ->when($request->integer('empresa_id'), fn ($query, int $empresaId) => $query->where('empresa_id', $empresaId))
            ->orderBy('nombre')
            ->paginate(Paginacion::porPagina())
            ->withQueryString();

        // Solo las sucursales de esta página, no la plantilla global.
        $plantillaPorSucursal = $this->headcount->resumenPorSucursal($sucursales->getCollection()->pluck('id'))->keyBy('sucursal_id');
        $volantes = $this->volantes->autorizadasPorSucursal(array_values($sucursales->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all()));
        $sucursales->getCollection()->transform(function (Sucursal $sucursal) use ($plantillaPorSucursal, $volantes) {
            $fila = $plantillaPorSucursal->get($sucursal->id);
            $sucursal->setAttribute('plantilla_permitida', (int) ($fila['plantilla_autorizada'] ?? 0));
            $sucursal->setAttribute('plantilla_vacantes', (int) ($fila['vacantes'] ?? 0));
            // Plazas de Gestor volante autorizadas (null = nunca se ha definido).
            $sucursal->setAttribute('volantes_autorizados', $volantes[$sucursal->id] ?? null);

            return $sucursal;
        });

        return Inertia::render('Administracion/Sucursales/Index', [
            'sucursales' => $sucursales,
            // Acciones del catálogo (config/permisos_modulos.php → sucursales).
            'puede' => [
                'crear' => $request->user()->can('create', Sucursal::class),
                'editar' => $request->user()->can('sucursales.administrar') && $request->user()->can('sucursales.editar'),
                'eliminar' => $request->user()->can('sucursales.administrar') && $request->user()->can('sucursales.eliminar'),
                // «¿Esta sucursal tendrá volantes?»: plantilla autorizada, solo RH.
                'editar_plantilla' => $request->user()->can('headcount.editar'),
            ],
            'filtros' => $request->only('busqueda', 'empresa_id'),
            // Solo lo usa el diálogo de alta/edición: se pide con una recarga
            // parcial al abrirlo, no en cada visita al listado.
            'responsablesDisponibles' => Inertia::optional(fn () => User::query()->orderBy('name')->get(['id', 'name', 'apellidos'])),
            'empresasDisponibles' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'estadisticas' => EstadisticasActivoInactivo::de(Sucursal::query()),
        ]);
    }

    /**
     * Detalle de sucursal: plantilla por puesto (permitida/cubierta/
     * vacantes/cobertura), igual que la sección "Plantilla" del encargo.
     */
    public function show(Request $request, Sucursal $sucursal): Response
    {
        $this->authorize('view', $sucursal);

        $sucursal->load('empresa:id,nombre');

        $plantillaPorPuesto = $this->headcount->resumenPorPuesto($sucursal->id);
        // La MISMA fila que la lista «Plantilla por sucursal» del tablero
        // (HeadcountService::poblacionPorSucursal): mismas cifras siempre.
        $fila = $this->headcount->poblacionPorSucursal(collect([$sucursal->id]))[0] ?? null;
        $totales = [
            'plantilla_autorizada' => $fila['autorizadas'] ?? 0,
            'plantilla_actual' => $fila['ocupadas'] ?? 0,
            'vacantes' => $fila['vacantes'] ?? 0,
            'cobertura' => $fila['cobertura'] ?? 0.0,
            // Personas vigentes que NO ocupan plaza autorizada (puesto sin
            // plazas aquí, excedentes o sin puesto): se reportan (auditable)
            // para que RH las reubique o capture la plantilla.
            'fuera_de_plantilla' => $fila !== null ? $fila['fuera_de_plantilla'] + $fila['excedentes'] + $fila['sin_ubicacion'] : 0,
            'activos' => $fila['activos'] ?? 0,
            'en_incorporacion' => $fila['en_incorporacion'] ?? 0,
            'bajas_en_tramite' => $fila['bajas_en_tramite'] ?? 0,
        ];

        return Inertia::render('Administracion/Sucursales/Show', [
            'sucursal' => $sucursal,
            'plantillaPorPuesto' => $plantillaPorPuesto,
            'totales' => $totales,
            // Quien responde por la sucursal es su Gerente de Sucursal: no
            // hay otra figura de "responsable".
            'gerente' => $this->detalle->gerente($sucursal),
            'porDepartamento' => $this->detalle->porDepartamento($sucursal),
            'departamentos' => $sucursal->colaboradores()->whereIn('estatus', EstadoUsuario::valoresVigentes())->whereNotNull('departamento_id')->distinct('departamento_id')->count('departamento_id'),
            // Gestores fijos (con ruta, Matriz comercial) vs. volantes.
            'gestores' => $this->volantes->resumen($sucursal),
            // Solo RH captura plantilla; el histórico (quién, cuándo, antes,
            // después, motivo) lo ve cualquiera que pueda ver la sucursal.
            'puedeEditarPlantilla' => $request->user()->can('editarPlantilla', $sucursal),
            'puestosCapturables' => fn () => $this->plantilla->puestosCapturables(),
            'historialPlantilla' => $this->plantilla->historial($sucursal),
        ]);
    }

    /**
     * Captura de plantilla autorizada de un puesto (solo RH). La regla y el
     * histórico viven en PlantillaAutorizadaService.
     */
    public function actualizarPlantilla(ActualizarPlantillaAutorizadaRequest $request, Sucursal $sucursal, Puesto $puesto): RedirectResponse
    {
        $this->plantilla->actualizar(
            $sucursal,
            $puesto,
            $request->integer('plantilla_autorizada'),
            $request->string('motivo')->toString(),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Plantilla autorizada de %s actualizada.', $puesto->nombre)]);
    }

    /**
     * «¿Esta sucursal tendrá volantes?» Sí/No + cuántos: fija las plazas de
     * Gestor volante (0 = no tendrá) y abre/cierra sus vacantes «Gestor
     * volante». Regla en VolantesSucursalService (solo RH).
     */
    public function configurarVolantes(ConfigurarVolantesSucursalRequest $request, Sucursal $sucursal): RedirectResponse
    {
        $cantidad = $request->cantidad();

        $this->volantes->configurar($sucursal, $cantidad, $request->string('motivo')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => $cantidad > 0
            ? sprintf('%s tendrá %d %s. Las vacantes «Gestor volante» se ajustaron solas.', $sucursal->nombre, $cantidad, $cantidad === 1 ? 'volante' : 'volantes')
            : sprintf('%s no tendrá volantes: sus plazas de Gestor volante quedan en 0.', $sucursal->nombre)]);
    }

    public function store(StoreSucursalRequest $request): RedirectResponse
    {
        Sucursal::create($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal creada correctamente.']);
    }

    public function update(UpdateSucursalRequest $request, Sucursal $sucursal): RedirectResponse
    {
        $sucursal->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal actualizada correctamente.']);
    }

    public function destroy(Sucursal $sucursal): RedirectResponse
    {
        $this->authorize('delete', $sucursal);

        $sucursal->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal eliminada correctamente.']);
    }
}
