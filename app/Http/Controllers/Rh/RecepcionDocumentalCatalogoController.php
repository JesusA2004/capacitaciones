<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecepcionDocumental\ActualizarCatalogoRecepcionRequest;
use App\Http\Requests\RecepcionDocumental\CrearCatalogoRecepcionRequest;
use App\Http\Requests\RecepcionDocumental\SimularCatalogoRecepcionRequest;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\RecepcionDocumentalCatalogo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\RecepcionDocumental\CatalogoReglasRecepcion;
use App\Services\RecepcionDocumental\ConfiguracionCatalogoRecepcionService;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use App\Services\RecepcionDocumental\RecepcionDocumentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo configurable de «lo que recibo» en Recepción documental (web).
 * Delgado: autoriza con RecepcionDocumentalCatalogoPolicy y delega en
 * ConfiguracionCatalogoRecepcionService (agrega, cambia, quita, simula el
 * recálculo y lo aplica en los cortes abiertos). Las acciones responden
 * JSON para que la pantalla actualice sin recargar.
 */
class RecepcionDocumentalCatalogoController extends Controller
{
    public function __construct(
        private readonly CatalogoReglasRecepcion $catalogo,
        private readonly ConfiguracionCatalogoRecepcionService $configuracion,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RecepcionDocumentalCatalogo::class);
        $periodo = PeriodoRecepcion::actual();
        $usuario = $request->user();

        return Inertia::render('Rh/RecepcionDocumental/Catalogo', [
            'entradas' => $this->catalogo->resumen(),
            'previas' => $this->configuracion->vistaPreviaGeneral($periodo),
            'periodoActual' => ['clave' => $periodo->clave(), 'etiqueta' => ucfirst($periodo->etiqueta())],
            'sucursales' => $this->alcance->sucursalesParaFiltro($usuario, RecepcionDocumentalService::MODULO, ['id', 'nombre', 'empresa_id'])
                ->map(fn ($s): array => ['value' => (int) $s->id, 'label' => (string) $s->nombre, 'empresa_id' => $s->empresa_id !== null ? (int) $s->empresa_id : null])->values()->all(),
            'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Empresa $e): array => ['value' => $e->id, 'label' => (string) $e->nombre])->values()->all(),
            'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Departamento $d): array => ['value' => $d->id, 'label' => (string) $d->nombre])->values()->all(),
            'opciones' => $this->catalogo->opciones(),
            'puedeConfigurar' => $usuario->can('configurar', RecepcionDocumentalCatalogo::class),
        ]);
    }

    /** Agrega algo que debe llegar cada mes y recalcula los cortes abiertos. */
    public function store(CrearCatalogoRecepcionRequest $request): JsonResponse
    {
        $resultado = $this->configuracion->crear($request->datos(), $request->user());

        return response()->json($this->respuesta($resultado), 201);
    }

    public function update(ActualizarCatalogoRecepcionRequest $request, RecepcionDocumentalCatalogo $entrada): JsonResponse
    {
        return response()->json($this->respuesta($this->configuracion->actualizar($entrada, $request->datos(), $request->user())));
    }

    /** Borra una entrada agregada que nunca se usó (si ya se usó: 422, solo desactivar). */
    public function destroy(Request $request, RecepcionDocumentalCatalogo $entrada): JsonResponse
    {
        $this->authorize('delete', $entrada);
        $this->configuracion->eliminar($entrada, $request->user());

        return response()->json($this->respuesta(['entrada' => null, 'recalculo' => null, 'cortes' => [], 'conflictos' => []]));
    }

    public function reordenar(Request $request): JsonResponse
    {
        $this->authorize('configurar', RecepcionDocumentalCatalogo::class);
        $datos = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer']], ['ids.required' => 'Manda el nuevo orden.']);
        $this->configuracion->reordenar(array_values(array_map('intval', $datos['ids'])), $request->user());

        return response()->json($this->respuesta(['entrada' => null, 'recalculo' => null, 'cortes' => [], 'conflictos' => []]));
    }

    /** Antes / después del «No. de papeles» en cada corte abierto (no guarda). */
    public function simular(SimularCatalogoRecepcionRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->configuracion->simular($request->accion(), $request->entrada(), $request->datos(), $request->user())]);
    }

    /** Cuánto se esperaría este mes con la configuración en borrador (no guarda). */
    public function vistaPrevia(Request $request, RecepcionDocumentalCatalogo $entrada): JsonResponse
    {
        $this->authorize('configurar', RecepcionDocumentalCatalogo::class);

        return response()->json(['data' => $this->previa($request, $entrada)]);
    }

    /** Vista previa en vivo de una entrada NUEVA (no guarda). */
    public function vistaPreviaNueva(Request $request): JsonResponse
    {
        $this->authorize('configurar', RecepcionDocumentalCatalogo::class);

        return response()->json(['data' => $this->previa($request, null)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function previa(Request $request, ?RecepcionDocumentalCatalogo $entrada): array
    {
        $datos = $request->validate([
            'periodo' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'documentos' => ['sometimes', 'nullable', 'array', 'max:40'],
            'documentos.*' => ['string', 'max:60'],
            'copias' => ['sometimes', 'nullable', 'array', 'max:40'],
            'copias.*' => ['integer', 'min:1', 'max:500'],
            'activa' => ['sometimes', 'boolean'],
            'modo_calculo' => ['sometimes', 'nullable', 'string', 'max:30'],
            'cantidad' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'regla_base' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sucursal_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'sucursal_ids.*' => ['integer'],
            'empresa_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'empresa_ids.*' => ['integer'],
            'departamento_ids' => ['sometimes', 'nullable', 'array', 'max:200'],
            'departamento_ids.*' => ['integer'],
        ]);
        $clave = is_string($datos['periodo'] ?? null) && PeriodoRecepcion::esClaveValida($datos['periodo']) ? $datos['periodo'] : null;
        $periodo = $clave !== null ? PeriodoRecepcion::desdeClave($clave) : PeriodoRecepcion::actual();
        $borrador = [];

        foreach (['documentos', 'sucursal_ids', 'empresa_ids', 'departamento_ids'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $borrador[$campo] = array_values((array) $datos[$campo]);
            }
        }

        foreach (['modo_calculo', 'regla_base', 'cantidad', 'copias'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $borrador[$campo] = $datos[$campo];
            }
        }

        if (array_key_exists('activa', $datos)) {
            $borrador['activa'] = $request->boolean('activa');
        }

        return $this->configuracion->vistaPrevia($entrada, $periodo, $borrador);
    }

    /**
     * @param  array{entrada: RecepcionDocumentalCatalogo|null, recalculo: array<string, mixed>|null, cortes: list<array<string, mixed>>, conflictos: list<array<string, mixed>>}  $resultado
     * @return array<string, mixed>
     */
    private function respuesta(array $resultado): array
    {
        return [
            'entradas' => $this->catalogo->resumen(),
            'previas' => $this->configuracion->vistaPreviaGeneral(PeriodoRecepcion::actual()),
            'opciones' => $this->catalogo->opciones(),
            'recalculo' => $resultado['recalculo'],
            'cortes' => $resultado['cortes'],
            'conflictos' => $resultado['conflictos'],
            'entrada_id' => $resultado['entrada']?->id,
        ];
    }
}
