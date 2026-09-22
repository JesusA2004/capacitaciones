<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\EstadoUsuario;
use App\Enums\Genero;
use App\Enums\TipoContratacion;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogos para el alta de colaborador desde la app (POST
 * /api/v1/rh/colaboradores): la app nunca hardcodea IDs de sucursal, puesto,
 * departamento ni jefe. Todo sale acotado al alcance organizacional de quien
 * consulta (mismo criterio que AltaColaboradorController::exigirSucursalEnAlcance):
 * un gerente de sucursal solo recibe sus sucursales y colaboradores.
 */
class CatalogoController extends Controller
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function __invoke(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('colaboradores.alta'), 403);

        $sucursalesIds = $this->alcance->sucursalesVisiblesIds($usuario);

        $sucursales = Sucursal::query()
            ->whereIn('id', $sucursalesIds)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'empresa_id']);

        $empresas = Empresa::query()
            ->whereIn('id', $sucursales->pluck('empresa_id')->filter()->unique())
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $departamentos = Departamento::query()
            ->whereIn('id', $this->alcance->departamentosVisiblesIds($usuario))
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        // Puestos: catálogo compartido entre sucursales (no tiene sucursal propia).
        $puestos = Puesto::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'departamento_id']);

        // Posibles jefes/gerentes: colaboradores activos dentro del alcance.
        $jefes = $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)
            ->where('estatus', EstadoUsuario::Activo->value)
            ->with('puesto:id,nombre')
            ->orderBy('name')
            ->limit(1000)
            ->get(['id', 'name', 'apellidos', 'puesto_id', 'sucursal_principal_id']);

        return response()->json(['data' => [
            'empresas' => $empresas->map(fn (Empresa $e) => ['id' => $e->id, 'nombre' => $e->nombre])->values(),
            'sucursales' => $sucursales->map(fn (Sucursal $s) => ['id' => $s->id, 'nombre' => $s->nombre, 'empresa_id' => $s->empresa_id])->values(),
            'departamentos' => $departamentos->map(fn (Departamento $d) => ['id' => $d->id, 'nombre' => $d->nombre])->values(),
            'puestos' => $puestos->map(fn (Puesto $p) => ['id' => $p->id, 'nombre' => $p->nombre, 'departamento_id' => $p->departamento_id])->values(),
            'jefes' => $jefes->map(fn (Colaborador $c) => [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'puesto' => $c->puesto?->nombre,
                'sucursal_id' => $c->sucursal_principal_id,
            ])->values(),
            'tipos_contratacion' => collect(TipoContratacion::cases())->map(fn (TipoContratacion $t) => [
                'value' => $t->value,
                'label' => $t->etiqueta(),
                'requiere_fecha_fin' => $t->tieneVencimiento(),
            ])->values(),
            'generos' => collect(Genero::cases())->map(fn (Genero $g) => ['value' => $g->value, 'label' => $g->etiqueta()])->values(),
        ]]);
    }
}
