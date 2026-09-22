<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ArchivoLaboralRequest;
use App\Http\Requests\CicloLaboral\DecisionRequest;
use App\Http\Requests\CicloLaboral\FiniquitoCierreRequest;
use App\Http\Requests\CicloLaboral\IniciarCierreRequest;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\FiniquitoConcepto;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\DocumentosLaborales\DocumentoLaboralConsultaService;
use App\Services\Finiquitos\FiniquitoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Cierre laboral: inicio → aviso/renuncia → finiquito (conceptos, revisión,
 * documento, firma, pago) → baja → expediente cerrado.
 */
class CierreLaboralController extends Controller
{
    use RespondePaginado;

    public function __construct(
        private readonly CierreLaboralService $cierres,
        private readonly FiniquitoService $finiquitos,
        private readonly DocumentoLaboralConsultaService $documentos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('cierres.ver'), 403);

        return $this->paginado(
            $this->cierres->listar($request->user(), $request->only(['estado', 'per_page'])),
            fn (CierreLaboral $c) => $this->cierres->aArray($c),
        );
    }

    public function store(IniciarCierreRequest $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('iniciarCierre', $colaborador);

        $cierre = $this->cierres->iniciar($colaborador, $request->validated(), $request->user());

        return response()->json(['data' => $this->cierres->aArray($cierre, true)], 201);
    }

    public function show(CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('ver', $cierre);

        return $this->respuesta($cierre);
    }

    public function aviso(ArchivoLaboralRequest $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('gestionar', $cierre);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        return $this->respuesta($this->cierres->registrarAviso($cierre, $archivo, $request->user()));
    }

    public function generarAviso(Request $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('gestionar', $cierre);

        return response()->json(['data' => $this->documentos->aArray($this->cierres->generarAviso($cierre, $request->user()), true)], 201);
    }

    public function calcularFiniquito(FiniquitoCierreRequest $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        $request->validate(['sueldo_mensual' => ['required']]);

        $this->cierres->calcularFiniquito($cierre, $request->user(), (float) $request->validated('sueldo_mensual'), (float) ($request->validated('sueldo_pendiente') ?? 0));

        return $this->respuesta($cierre->refresh());
    }

    public function agregarConcepto(FiniquitoCierreRequest $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        $request->validate(['tipo' => ['required'], 'concepto' => ['required'], 'importe' => ['required']]);

        $this->finiquitos->agregarConcepto($this->cierres->exigirFiniquito($cierre), $request->safe()->only(['tipo', 'concepto', 'cantidad', 'importe', 'observaciones']), $request->user());

        return $this->respuesta($cierre->refresh());
    }

    public function actualizarConcepto(FiniquitoCierreRequest $request, CierreLaboral $cierre, FiniquitoConcepto $concepto): JsonResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        abort_unless($concepto->finiquito_calculo_id === $this->cierres->exigirFiniquito($cierre)->id, 404);

        $this->finiquitos->actualizarConcepto($concepto, $request->safe()->only(['tipo', 'concepto', 'cantidad', 'importe', 'observaciones']), $request->user());

        return $this->respuesta($cierre->refresh());
    }

    public function eliminarConcepto(Request $request, CierreLaboral $cierre, FiniquitoConcepto $concepto): JsonResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        abort_unless($concepto->finiquito_calculo_id === $this->cierres->exigirFiniquito($cierre)->id, 404);

        $this->finiquitos->eliminarConcepto($concepto, $request->user());

        return $this->respuesta($cierre->refresh());
    }

    public function revisarFiniquito(Request $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('revisarFiniquito', $cierre);

        $this->finiquitos->aprobarCalculo($this->cierres->exigirFiniquito($cierre), $request->user());

        return $this->respuesta($cierre->refresh());
    }

    public function generarFiniquito(Request $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('calcularFiniquito', $cierre);

        $finiquito = $this->finiquitos->generarPdf($this->cierres->exigirFiniquito($cierre), $request->user());

        return response()->json([
            'data' => $this->cierres->aArray($cierre->refresh(), true),
            'documento_id' => $finiquito->generated_document_id,
        ]);
    }

    public function finiquitoFirmado(ArchivoLaboralRequest $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        return $this->respuesta($this->cierres->registrarFiniquitoFirmado($cierre, $archivo, $request->user()));
    }

    public function confirmarPago(FiniquitoCierreRequest $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('confirmarPago', $cierre);
        $request->validate(['referencia_pago' => ['required']]);

        return $this->respuesta($this->cierres->confirmarPago($cierre, $request->user(), (string) $request->validated('referencia_pago')));
    }

    public function ejecutarBaja(Request $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('ejecutarBaja', $cierre);

        return $this->respuesta($this->cierres->ejecutarBaja($cierre, $request->user()));
    }

    public function cerrarExpediente(Request $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('ejecutarBaja', $cierre);

        return $this->respuesta($this->cierres->cerrarExpediente($cierre, $request->user()));
    }

    public function cancelar(DecisionRequest $request, CierreLaboral $cierre): JsonResponse
    {
        $this->authorize('gestionar', $cierre);
        $request->validate(['motivo' => ['required']]);

        return $this->respuesta($this->cierres->cancelar($cierre, $request->user(), (string) $request->validated('motivo')));
    }

    private function respuesta(CierreLaboral $cierre): JsonResponse
    {
        return response()->json(['data' => $this->cierres->aArray($cierre, true)]);
    }
}
