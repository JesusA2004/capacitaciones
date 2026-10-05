<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\EstadoReciboNomina;
use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ImportarRecibosRequest;
use App\Http\Requests\CicloLaboral\ReciboNominaRequest;
use App\Http\Requests\Nomina\ActualizarReciboNominaRequest;
use App\Http\Requests\Nomina\CambioMasivoRecibosRequest;
use App\Models\Colaborador;
use App\Models\ReciboNomina;
use App\Services\Nomina\NominaQuincenalService;
use App\Services\Nomina\ReciboNominaImportService;
use App\Services\Nomina\ReciboNominaService;
use Carbon\CarbonImmutable;
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
        private readonly NominaQuincenalService $quincenas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.ver'), 403);

        return $this->paginado(
            $this->recibos->listar($request->user(), $request->only(['periodo_inicio', 'periodo_fin', 'colaborador_id', 'lote', 'estado', 'q', 'per_page'])),
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

    /**
     * Resumen de una quincena (`periodo=2026-10-1`, por defecto la actual).
     */
    public function quincena(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.ver'), 403);
        $periodo = $this->periodo($request);
        $base = $this->quincenas->recibosDelPeriodo($periodo, $request->user());
        $total = (clone $base)->count();
        $borradores = (clone $base)->where('estado', EstadoReciboNomina::Borrador->value)->count();

        return response()->json(['data' => [
            'clave' => $periodo['clave'],
            'etiqueta' => $periodo['etiqueta'],
            'periodo_inicio' => $periodo['inicio']->toDateString(),
            'periodo_fin' => $periodo['fin']->toDateString(),
            'fecha_pago' => $periodo['pago']->toDateString(),
            'total' => $total,
            'borradores' => $borradores,
            'emitidos' => $total - $borradores,
            'neto' => round((float) (clone $base)->sum('neto'), 2),
            'emision_automatica' => (bool) config('nomina.quincenal.emision_automatica', true),
            'periodos' => $this->quincenas->periodosParaSelector(),
        ]]);
    }

    public function prepararQuincena(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);

        return response()->json(['data' => $this->quincenas->preparar($this->periodo($request), $request->user())]);
    }

    public function emitirQuincena(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);
        $ids = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']])['ids'] ?? null;

        return response()->json(['data' => ['emitidos' => $this->quincenas->emitirPeriodo($this->periodo($request), $request->user(), $ids !== null ? array_map('intval', $ids) : null)]]);
    }

    public function masivo(CambioMasivoRecibosRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('nomina.recibos.crear'), 403);
        $datos = $request->validated();

        $modificados = $this->quincenas->aplicarMasivo($this->quincenas->periodoPorClave($datos['periodo']), $request->user(), [
            'accion' => $datos['accion'],
            'tipo' => $datos['tipo'],
            'concepto' => $datos['concepto'],
            'importe' => $datos['importe'] ?? null,
        ], isset($datos['ids']) ? array_map('intval', $datos['ids']) : null);

        return response()->json(['data' => ['modificados' => $modificados]]);
    }

    public function update(ActualizarReciboNominaRequest $request, ReciboNomina $recibo): JsonResponse
    {
        $this->authorize('editar', $recibo);

        return response()->json(['data' => $this->recibos->aArray($this->recibos->actualizar($recibo, $request->validated(), $request->user()), true)]);
    }

    public function emitir(Request $request, ReciboNomina $recibo): JsonResponse
    {
        $this->authorize('editar', $recibo);

        return response()->json(['data' => $this->recibos->aArray($this->recibos->emitir($recibo, $request->user()), true)]);
    }

    /**
     * @return array{clave: string, inicio: CarbonImmutable, fin: CarbonImmutable, pago: CarbonImmutable, numero: int, etiqueta: string}
     */
    private function periodo(Request $request): array
    {
        return $request->filled('periodo')
            ? $this->quincenas->periodoPorClave($request->string('periodo')->toString())
            : $this->quincenas->periodo(now('America/Mexico_City'));
    }

    public function regenerarPdf(Request $request, ReciboNomina $recibo): JsonResponse
    {
        $this->authorize('regenerar', $recibo);

        return response()->json(['data' => $this->recibos->aArray($this->recibos->regenerarPdf($recibo, $request->user()), true)]);
    }
}
