<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\MotivoCobertura;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\ActualizarJerarquiaPuestoRequest;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Administracion\JerarquiaPuestoService;
use App\Services\Organigrama\ClasificacionRegionService;
use App\Services\Organigrama\LaminasOrganigramaService;
use App\Services\Organigrama\MiniOrganigramaService;
use App\Services\Organigrama\OrganigramaPersonasService;
use App\Services\Organigrama\PuestosSinUbicarService;
use App\Services\Organigrama\ResponsableAprobacionService;
use App\Services\Puestos\HistorialPuestoService;
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
        private readonly OrganigramaPersonasService $organigramaPersonas,
        private readonly MiniOrganigramaService $miniOrganigrama,
        private readonly LaminasOrganigramaService $laminas,
        private readonly ClasificacionRegionService $clasificaciones,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Puesto::class);

        $usuario = $request->user();
        // El organigrama (con ocupantes) es de organigrama.ver, igual que su
        // entrada del menú; puestos.administrar solo abre el catálogo de puestos.
        abort_unless($usuario->can('organigrama.ver'), 403);
        $puestos = $this->jerarquia->arbol($request);
        $this->jerarquia->agregarOcupantes($puestos, $usuario, $request);
        // Editar el organigrama (coberturas, Q1/Q3, subir/bajar/mover): solo
        // RH / super admin; el backend lo vuelve a validar con Policies.
        // Cada acción tiene su permiso (config/permisos_modulos.php →
        // organigrama): coberturas, mover/clasificar y editar jerarquía.
        $puedeEditar = $usuario->can('create', CoberturaPuesto::class);
        $puedeMover = $usuario->can('organigrama.ver') && $usuario->can('organigrama.mover_personas');

        return Inertia::render('Administracion/JerarquiaPuestos/Index', [
            'permisosOrganigrama' => [
                'cubrir' => $puedeEditar,
                'mover' => $puedeMover,
                'editarJerarquia' => $usuario->can('editarJerarquia', new Puesto),
            ],
            'puestos' => $puestos,
            // Vista por personas: una tarjeta por colaborador, rama por sucursal.
            // `aprobador`: responsable de aprobación explícito (≠ reporte
            // organizacional), para distinguirlo visualmente.
            'personas' => app(ResponsableAprobacionService::class)->anotarOrganigrama($this->organigramaPersonas->arbol($usuario, $request)),
            // Coberturas temporales: quién puede asignarlas y con qué datos.
            'coberturas' => [
                'puedeEditar' => $puedeEditar,
                'motivos' => MotivoCobertura::opciones(),
                'colaboradores' => $puedeEditar ? $this->organigramaPersonas->candidatosCobertura($usuario) : [],
            ],
            // Q1 / Q3: regiones de la matriz comercial (chips y clasificación).
            'regiones' => $this->clasificaciones->regiones(),
            // Secciones como el PDF de Inducción (cap. 4): General (todo),
            // Corporativo, Dirección Comercial y Sucursal — cada una es la
            // rama real de su puesto raíz (config organigrama.vistas_induccion);
            // una sección cuyo puesto ya no existe no se ofrece.
            'secciones' => $this->secciones(),
            // Láminas como el PDF de Inducción (hojas 17-19) con las personas reales.
            'laminas' => $this->laminas->laminas($usuario),
            // Puestos activos que no cuelgan de Dirección General (sin «reporta
            // a», superior inactivo o rama suelta): bandeja para ubicarlos.
            'puestosSinUbicar' => app(PuestosSinUbicarService::class)->listar(),
            'filtros' => $request->only($this->jerarquia->filtrosAceptados()),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            ],
        ]);
    }

    /**
     * @return list<array{valor: string, etiqueta: string, raiz_puesto_id: int|null, profundidad: int|null}>
     */
    private function secciones(): array
    {
        $secciones = [['valor' => 'general', 'etiqueta' => 'General', 'raiz_puesto_id' => null, 'profundidad' => null]];

        // Corporativo: Dirección General y las áreas que le reportan directo
        // (un nivel, como la lámina del PDF); las demás, su rama completa.
        foreach (['corporativo' => ['Corporativo', 1], 'comercial' => ['Dirección Comercial', null], 'sucursal' => ['Sucursal', null]] as $vista => [$etiqueta, $profundidad]) {
            $raiz = $this->miniOrganigrama->raizDeVista($vista);

            if ($raiz !== null) {
                $secciones[] = ['valor' => $vista, 'etiqueta' => $etiqueta, 'raiz_puesto_id' => $raiz, 'profundidad' => $profundidad];
            }
        }

        return $secciones;
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
    public function historial(Request $request, Puesto $puesto): JsonResponse
    {
        $this->authorize('view', $puesto);

        return response()->json(app(HistorialPuestoService::class)->historial($puesto, $request->user()));
    }
}
