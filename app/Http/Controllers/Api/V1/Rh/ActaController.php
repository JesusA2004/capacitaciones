<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ArchivoLaboralRequest;
use App\Http\Requests\CicloLaboral\DecisionRequest;
use App\Http\Requests\CicloLaboral\GuardarActaRequest;
use App\Models\ActaAdministrativa;
use App\Models\ActaAnexo;
use App\Models\Colaborador;
use App\Services\Actas\ActaPdfService;
use App\Services\Actas\ActaService;
use App\Services\DocumentosLaborales\DocumentoLaboralConsultaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActaController extends Controller
{
    use RespondePaginado;

    public function __construct(
        private readonly ActaService $actas,
        private readonly DocumentoLaboralConsultaService $documentos,
        private readonly ActaPdfService $pdf,
    ) {}

    /** Mismo buscador de testigos que la web (ActaService::buscarTestigos). */
    public function buscarTestigos(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('actas.crear'), 403);
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'excluir' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->actas->buscarTestigos($request->user(), (string) ($datos['q'] ?? ''), isset($datos['excluir']) ? (int) $datos['excluir'] : null)]);
    }

    /** Vista previa del PDF real (mismo servicio que el final); no guarda nada. */
    public function vistaPrevia(ActaAdministrativa $acta): HttpResponse
    {
        $this->authorize('ver', $acta);
        abort_unless(ActaPdfService::aplica($acta), 404);

        if (($motivo = $this->pdf->faltante($acta)) !== null) {
            return response()->json(['message' => $motivo], 409);
        }

        return response($this->pdf->vistaPrevia($acta), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="Vista previa %s.pdf"', $acta->folio),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('actas.ver'), 403);

        return $this->paginado(
            $this->actas->listar($request->user(), $request->only(['tipo', 'estado', 'colaborador_id', 'per_page'])),
            fn (ActaAdministrativa $a) => $this->actas->aArray($a),
        );
    }

    public function store(GuardarActaRequest $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('crearActa', $colaborador);

        return response()->json(['data' => $this->actas->aArray($this->actas->crear($colaborador, $request->validated(), $request->user()), true)], 201);
    }

    public function show(ActaAdministrativa $acta): JsonResponse
    {
        $this->authorize('ver', $acta);

        return response()->json(['data' => $this->actas->aArray($acta, true), 'catalogos' => ActaService::catalogos()]);
    }

    public function update(GuardarActaRequest $request, ActaAdministrativa $acta): JsonResponse
    {
        $this->authorize('gestionar', $acta);

        return response()->json(['data' => $this->actas->aArray($this->actas->actualizar($acta, $request->validated(), $request->user()), true)]);
    }

    public function anexo(ArchivoLaboralRequest $request, ActaAdministrativa $acta): JsonResponse
    {
        $this->authorize('gestionar', $acta);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $extra = $request->validate([
            'fecha_identificacion' => ['nullable', 'string', 'max:160'],
            'relacion_hechos' => ['nullable', 'string', 'max:500'],
        ]);
        $this->actas->agregarAnexo($acta, $archivo, $request->validated('descripcion'), $request->user(), $extra['fecha_identificacion'] ?? null, $extra['relacion_hechos'] ?? null);

        return response()->json(['data' => $this->actas->aArray($acta->refresh(), true)], 201);
    }

    public function descargarAnexo(ActaAdministrativa $acta, ActaAnexo $anexo): StreamedResponse
    {
        $this->authorize('ver', $acta);

        return $this->actas->descargarAnexo($acta, $anexo);
    }

    public function generarDocumento(Request $request, ActaAdministrativa $acta): JsonResponse
    {
        $this->authorize('gestionar', $acta);

        return response()->json(['data' => $this->documentos->aArray($this->actas->generarDocumento($acta, $request->user()), true)], 201);
    }

    /** Constancia de entrega de copia (mismo ActaService que la web). */
    public function entrega(Request $request, ActaAdministrativa $acta): JsonResponse
    {
        abort_unless($request->user()->can('gestionar', $acta) || $acta->creado_por === $request->user()->id, 403, 'Solo RH o quien levantó el acta registran la entrega de la copia.');
        $datos = $request->validate([
            'estado' => ['required', 'in:entregada,no_posible'],
            'motivo' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->actas->aArray($this->actas->registrarEntrega($acta, $datos['estado'], $datos['motivo'] ?? null, $request->user()), true)]);
    }

    public function negativaFirma(DecisionRequest $request, ActaAdministrativa $acta): JsonResponse
    {
        $this->authorize('gestionar', $acta);
        $request->validate(['motivo' => ['required']]);

        return response()->json(['data' => $this->actas->aArray($this->actas->registrarNegativaFirma($acta, (string) $request->validated('motivo'), $request->user()), true)]);
    }

    public function seguimiento(DecisionRequest $request, ActaAdministrativa $acta): JsonResponse
    {
        $this->authorize('gestionar', $acta);
        $request->validate(['nota' => ['required']]);

        return response()->json(['data' => $this->actas->aArray($this->actas->agregarSeguimiento($acta, (string) $request->validated('nota'), $request->user()), true)]);
    }

    public function cerrar(Request $request, ActaAdministrativa $acta): JsonResponse
    {
        $this->authorize('gestionar', $acta);

        return response()->json(['data' => $this->actas->aArray($this->actas->cerrar($acta, $request->user()), true)]);
    }
}
