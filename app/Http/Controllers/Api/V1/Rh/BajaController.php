<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bajas\AdjuntarDocumentoBajaRequest;
use App\Http\Requests\Bajas\GuardarBajaRequest;
use App\Http\Requests\Bajas\MotivoBajaRequest;
use App\Models\Baja;
use App\Models\DocumentoRequeridoBaja;
use App\Services\Bajas\BajaConsultaService;
use App\Services\Bajas\BajaDocumentosService;
use App\Services\Bajas\BajaService;
use App\Services\Bajas\CatalogoBajasService;
use App\Services\CierreLaboral\CausalRotacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Módulo de bajas en la API v1 (Sanctum): MISMO BajaService que la web.
 * Endpoints nuevos y aditivos: las APK instaladas siguen usando
 * /rh/colaboradores/{colaborador}/cierres (atajo), que también registra la
 * baja «En revisión RH».
 */
class BajaController extends Controller
{
    public function __construct(
        private readonly BajaService $bajas,
        private readonly BajaConsultaService $consulta,
        private readonly BajaDocumentosService $documentos,
        private readonly CatalogoBajasService $catalogo,
        private readonly CausalRotacionService $causales,
    ) {}

    public function catalogo(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Baja::class);

        return response()->json(['data' => [
            'formas' => $this->catalogo->opciones($this->causales->formasPermitidas($request->user())),
            'documentos' => DocumentoRequeridoBaja::query()->where('activo', true)->orderBy('orden')->get(['id', 'clave', 'forma_salida', 'causal_rotacion_id', 'nombre', 'obligatorio_para_cerrar', 'documento_sistema']),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Baja::class);

        return response()->json($this->consulta->bandeja($request->user(), $request->only(['estatus', 'sucursal_id', 'forma', 'busqueda', 'per_page'])));
    }

    public function show(Request $request, Baja $baja): JsonResponse
    {
        $this->authorize('view', $baja);

        return response()->json(['data' => $this->bajas->detalle($baja, $request->user())]);
    }

    public function store(GuardarBajaRequest $request): JsonResponse
    {
        $this->authorize('create', Baja::class);
        $baja = $this->bajas->guardar(null, $request->datos(), $request->user());

        if ($request->boolean('enviar')) {
            $baja = $this->bajas->enviar($baja, $request->user());
        }

        return response()->json(['data' => $this->bajas->detalle($baja, $request->user())], 201);
    }

    public function update(GuardarBajaRequest $request, Baja $baja): JsonResponse
    {
        $this->authorize('update', $baja);
        $baja = $this->bajas->guardar($baja, $request->datos(), $request->user());

        if ($request->boolean('enviar')) {
            $baja = $this->bajas->enviar($baja, $request->user());
        }

        return response()->json(['data' => $this->bajas->detalle($baja, $request->user())]);
    }

    public function enviar(Request $request, Baja $baja): JsonResponse
    {
        $this->authorize('update', $baja);

        return response()->json(['data' => $this->bajas->detalle($this->bajas->enviar($baja, $request->user()), $request->user())]);
    }

    public function rechazar(MotivoBajaRequest $request, Baja $baja): JsonResponse
    {
        $this->authorize('rechazar', $baja);

        return response()->json(['data' => $this->bajas->detalle($this->bajas->rechazar($baja, $request->user(), $request->motivo()), $request->user())]);
    }

    public function adjuntar(AdjuntarDocumentoBajaRequest $request, Baja $baja): JsonResponse
    {
        $this->authorize('view', $baja);
        abort_unless(in_array('adjuntar', $this->bajas->acciones($baja, $request->user()), true), 403, 'No puedes adjuntar documentos a esta baja.');
        $requerido = is_numeric($request->validated('documento_requerido_id')) ? DocumentoRequeridoBaja::query()->where('id', (int) $request->validated('documento_requerido_id'))->first() : null;
        $archivo = $request->file('archivo');
        abort_if($archivo === null || is_array($archivo), 422, 'Elige un archivo.');
        $documento = $this->documentos->adjuntar($baja, $requerido, $archivo, $request->user());

        return response()->json(['data' => $this->documentos->archivoArray($documento)], 201);
    }
}
