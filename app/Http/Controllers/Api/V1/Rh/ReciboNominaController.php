<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ImportarRecibosRequest;
use App\Http\Requests\CicloLaboral\ReciboNominaRequest;
use App\Models\Colaborador;
use App\Models\ReciboNomina;
use App\Services\Nomina\ReciboNominaImportService;
use App\Services\Nomina\ReciboNominaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recibos INTERNOS de nómina (no fiscales, sin integración con NOI):
 * captura individual, importación administrativa CSV/XLSX, consulta y PDF.
 */
class ReciboNominaController extends Controller
{
    use RespondePaginado;

    public function __construct(
        private readonly ReciboNominaService $recibos,
        private readonly ReciboNominaImportService $importacion,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.ver'), 403);

        return $this->paginado(
            $this->recibos->listar($request->user(), $request->only(['periodo_inicio', 'periodo_fin', 'colaborador_id', 'lote', 'per_page'])),
            fn (ReciboNomina $r) => [...$this->recibos->aArray($r), 'colaborador' => ['id' => $r->colaborador->id, 'nombre' => $r->colaborador->nombreCompleto(), 'numero_empleado' => $r->colaborador->numero_empleado]],
        );
    }

    public function store(ReciboNominaRequest $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('crearRecibo', $colaborador);

        $recibo = $this->recibos->generar($colaborador, $request->validated(), $request->user());

        return response()->json(['data' => $this->recibos->aArray($recibo, true)], 201);
    }

    public function importar(ImportarRecibosRequest $request): JsonResponse
    {
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $resultado = $this->importacion->importar($archivo, $request->safe()->only(['periodo_inicio', 'periodo_fin', 'fecha_pago', 'simular']), $request->user());

        return response()->json(['data' => $resultado], $resultado['simulacion'] ? 200 : 201);
    }

    public function show(ReciboNomina $recibo): JsonResponse
    {
        $this->authorize('ver', $recibo);

        return response()->json(['data' => $this->recibos->aArray($recibo, true)]);
    }

    public function pdf(ReciboNomina $recibo): StreamedResponse
    {
        $this->authorize('ver', $recibo);

        return $this->recibos->respuestaPdf($recibo);
    }

    public function regenerarPdf(Request $request, ReciboNomina $recibo): JsonResponse
    {
        $this->authorize('regenerar', $recibo);

        return response()->json(['data' => $this->recibos->aArray($this->recibos->regenerarPdf($recibo, $request->user()), true)]);
    }
}
