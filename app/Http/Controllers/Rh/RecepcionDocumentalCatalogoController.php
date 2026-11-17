<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecepcionDocumental\ActualizarCatalogoRecepcionRequest;
use App\Models\Empresa;
use App\Models\RecepcionDocumentalCatalogo;
use App\Models\Sucursal;
use App\Services\RecepcionDocumental\CatalogoReglasRecepcion;
use App\Services\RecepcionDocumental\ConfiguracionCatalogoRecepcionService;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo configurable de «lo que recibo» en Recepción documental (web).
 * Delgado: autoriza con RecepcionDocumentalCatalogoPolicy y delega en
 * ConfiguracionCatalogoRecepcionService (guarda, audita y recalcula el mes
 * en curso). Las acciones responden JSON para que la pantalla actualice sin
 * recargar (encendido rápido, arrastrar para ordenar, vista previa).
 */
class RecepcionDocumentalCatalogoController extends Controller
{
    public function __construct(
        private readonly CatalogoReglasRecepcion $catalogo,
        private readonly ConfiguracionCatalogoRecepcionService $configuracion,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RecepcionDocumentalCatalogo::class);
        $periodo = PeriodoRecepcion::actual();

        return Inertia::render('Rh/RecepcionDocumental/Catalogo', [
            'entradas' => $this->catalogo->resumen(),
            'previas' => $this->configuracion->vistaPreviaGeneral($periodo),
            'periodoActual' => ['clave' => $periodo->clave(), 'etiqueta' => ucfirst($periodo->etiqueta())],
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id'])
                ->map(fn (Sucursal $s): array => ['value' => $s->id, 'label' => $s->nombre, 'empresa_id' => $s->empresa_id])->values()->all(),
            'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Empresa $e): array => ['value' => $e->id, 'label' => (string) $e->nombre])->values()->all(),
            'puedeConfigurar' => $request->user()->can('configurar', RecepcionDocumentalCatalogo::class),
        ]);
    }

    public function update(ActualizarCatalogoRecepcionRequest $request, RecepcionDocumentalCatalogo $entrada): JsonResponse
    {
        ['entrada' => $actualizada, 'recalculo' => $recalculo] = $this->configuracion->actualizar($entrada, $request->datos(), $request->user());

        return response()->json($this->respuesta($recalculo, $actualizada->id));
    }

    public function reordenar(Request $request): JsonResponse
    {
        $this->authorize('configurar', RecepcionDocumentalCatalogo::class);
        $datos = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['integer']], ['ids.required' => 'Manda el nuevo orden.']);
        $this->configuracion->reordenar(array_values(array_map('intval', $datos['ids'])), $request->user());

        return response()->json($this->respuesta(null, null));
    }

    /** Cuánto se esperaría este mes con la configuración en borrador (no guarda). */
    public function vistaPrevia(Request $request, RecepcionDocumentalCatalogo $entrada): JsonResponse
    {
        $this->authorize('configurar', RecepcionDocumentalCatalogo::class);
        $datos = $request->validate([
            'periodo' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'documentos' => ['sometimes', 'array', 'max:40'],
            'documentos.*' => ['string', 'max:60'],
            'activa' => ['sometimes', 'boolean'],
            'sucursal_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'sucursal_ids.*' => ['integer'],
            'empresa_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'empresa_ids.*' => ['integer'],
        ]);
        $clave = is_string($datos['periodo'] ?? null) && PeriodoRecepcion::esClaveValida($datos['periodo']) ? $datos['periodo'] : null;
        $periodo = $clave !== null ? PeriodoRecepcion::desdeClave($clave) : PeriodoRecepcion::actual();
        $borrador = [];

        foreach (['documentos', 'sucursal_ids', 'empresa_ids'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $borrador[$campo] = array_values((array) $datos[$campo]);
            }
        }

        if (array_key_exists('activa', $datos)) {
            $borrador['activa'] = $request->boolean('activa');
        }

        return response()->json(['data' => $this->configuracion->vistaPrevia($entrada, $periodo, $borrador)]);
    }

    /**
     * @param  array<string, mixed>|null  $recalculo
     * @return array<string, mixed>
     */
    private function respuesta(?array $recalculo, ?int $entradaId): array
    {
        return [
            'entradas' => $this->catalogo->resumen(),
            'previas' => $this->configuracion->vistaPreviaGeneral(PeriodoRecepcion::actual()),
            'recalculo' => $recalculo,
            'entrada_id' => $entradaId,
        ];
    }
}
