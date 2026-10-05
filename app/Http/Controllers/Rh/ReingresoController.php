<?php

namespace App\Http\Controllers\Rh;

use App\Enums\TipoContratacion;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\Puesto;
use App\Models\Reingreso;
use App\Models\Sucursal;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\ReingresoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reingreso de un excolaborador (Etapa 6 → Etapa 2): busca a la MISMA
 * persona, muestra su historial y RH decide. Toda la regla vive en
 * ReingresoService (el mismo que usa la API móvil).
 */
class ReingresoController extends Controller
{
    public function __construct(
        private readonly ReingresoService $reingresos,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->canAny([ReingresoService::PERMISO_GESTIONAR, ReingresoService::PERMISO_SOLICITAR]), 403);

        $busqueda = trim((string) $request->query('busqueda', ''));
        $colaboradorId = $request->integer('colaborador');
        $historial = null;

        if ($colaboradorId > 0) {
            $persona = Colaborador::withTrashed()->where('id', $colaboradorId)->first();

            if ($persona !== null && ($this->alcance->tieneAlcanceGlobal($usuario) || $this->alcance->alcanzaColaborador($usuario, $persona))) {
                $historial = $this->reingresos->historial($persona, $usuario);
                $busqueda = $busqueda !== '' ? $busqueda : (string) ($persona->numero_empleado ?? $persona->nombreCompleto());
            }
        }

        $listado = $this->reingresos->listar($usuario, $request->only(['estado', 'per_page']))->withQueryString();
        $listado->getCollection()->transform(fn (Reingreso $r) => $this->reingresos->aArray($r, $usuario));

        return Inertia::render('Rh/Reingresos/Index', [
            'busqueda' => $busqueda,
            'resultados' => $this->reingresos->buscar($busqueda, $usuario),
            'historial' => $historial,
            'reingresos' => $listado,
            'opciones' => [
                'puestos' => Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->whereIn('id', $this->alcance->sucursalesVisiblesIds($usuario))->orderBy('nombre')->get(['id', 'nombre']),
                'tiposDocumento' => DocumentType::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'tiposContratacion' => array_map(fn (TipoContratacion $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta()], TipoContratacion::seleccionables()),
            ],
            'puedeSolicitar' => $usuario->canAny([ReingresoService::PERMISO_GESTIONAR, ReingresoService::PERMISO_SOLICITAR]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'colaborador_id' => ['required', 'integer'],
            'motivo' => ['required', 'string', 'max:2000'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'jefe_id' => ['nullable', 'integer', 'exists:colaboradores,id'],
            'tipo_contratacion' => ['nullable', 'string', 'in:'.implode(',', [...array_column(TipoContratacion::seleccionables(), 'value'), TipoContratacion::PeriodoPrueba->value])],
            'sueldo_mensual' => ['nullable', 'numeric', 'min:0'],
            'fecha_reingreso' => ['nullable', 'date'],
            'documentos_adicionales' => ['nullable', 'array'],
            'documentos_adicionales.*' => ['integer', 'exists:document_types,id'],
        ], [], ['colaborador_id' => 'persona', 'motivo' => 'motivo']);

        $persona = Colaborador::withTrashed()->where('id', (int) $datos['colaborador_id'])->firstOrFail();
        unset($datos['colaborador_id']);

        $this->reingresos->solicitar($persona, $datos, $request->user());

        return to_route('rh.reingresos.index')->with('toast', ['type' => 'success', 'message' => 'Reingreso enviado a RH para su decisión.']);
    }

    public function decidir(Request $request, Reingreso $reingreso): RedirectResponse
    {
        $datos = $request->validate([
            'viable' => ['required', 'boolean'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->reingresos->decidir($reingreso, $request->user(), (bool) $datos['viable'], $datos['comentario'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => (bool) $datos['viable'] ? 'Reingreso autorizado: la misma persona regresa a contratación.' : 'Reingreso marcado como no viable.']);
    }
}
