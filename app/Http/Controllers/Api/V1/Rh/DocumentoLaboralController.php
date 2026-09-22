<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ArchivoLaboralRequest;
use App\Http\Requests\CicloLaboral\GenerarDocumentoLaboralRequest;
use App\Http\Requests\CicloLaboral\OperacionDocumentoRequest;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\DocumentosLaborales\DocumentoLaboralConsultaService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos laborales (motor documental + flujo de firmas + control del
 * original físico) para RH. Cada acción autoriza con
 * GeneratedDocumentPolicy (permiso + alcance) antes de llamar al servicio.
 */
class DocumentoLaboralController extends Controller
{
    use RespondePaginado;

    public function __construct(
        private readonly MotorDocumentalService $motor,
        private readonly FlujoDocumentalService $flujo,
        private readonly DocumentoLaboralConsultaService $consulta,
        private readonly ContratoLaboralService $contratos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('documentos_laborales.ver'), 403);

        return $this->paginado(
            $this->consulta->listar($request->user(), $request->only(['etapa', 'estado', 'colaborador_id', 'clave', 'categoria', 'per_page'])),
            fn (GeneratedDocument $d) => $this->consulta->aArray($d),
        );
    }

    /**
     * Pendientes del control físico: imprimir, firma del colaborador, firma
     * física, enviar, recibir y escanear.
     */
    public function pendientes(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('documentos_laborales.ver'), 403);

        return response()->json(['data' => $this->consulta->conteosPendientes($request->user())]);
    }

    public function store(GenerarDocumentoLaboralRequest $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('generarDocumento', $colaborador);

        $contratoId = $request->validated('contrato_id');

        if ($contratoId !== null) {
            $contrato = ContratoLaboral::query()->where('id', $contratoId)->where('colaborador_id', $colaborador->id)->firstOrFail();
            $documento = $this->contratos->generarDocumento($contrato, (string) $request->validated('clave'), $request->user());
        } else {
            $plantilla = $request->validated('plantilla_id') !== null
                ? DocumentTemplate::query()->findOrFail((int) $request->validated('plantilla_id'))
                : (string) $request->validated('clave');

            $extra = array_map('strval', array_filter((array) $request->validated('extra', []), fn ($v) => $v !== null));
            $documento = $this->motor->generar($colaborador, $plantilla, $request->user(), $extra, null, $request->validated('titulo'));
        }

        return response()->json(['data' => $this->consulta->aArray($documento, true)], 201);
    }

    public function show(GeneratedDocument $documento): JsonResponse
    {
        $this->authorize('ver', $documento);

        return response()->json(['data' => $this->consulta->aArray($documento->load(['colaborador', 'seguimientoFisico']), true)]);
    }

    public function descargar(GeneratedDocument $documento): StreamedResponse
    {
        $this->authorize('descargar', $documento);

        return $this->motor->respuesta($documento);
    }

    public function imprimir(OperacionDocumentoRequest $request, GeneratedDocument $documento): JsonResponse
    {
        $this->authorize('operar', $documento);

        return $this->respuesta($this->flujo->marcarImpreso($documento, $request->user(), $request->validated('observaciones')));
    }

    public function firmaFisica(OperacionDocumentoRequest $request, GeneratedDocument $documento): JsonResponse
    {
        $this->authorize('operar', $documento);

        return $this->respuesta($this->flujo->registrarFirmaFisica($documento, $request->user(), $request->safe()->only(['huella_registrada', 'testigos', 'observaciones'])));
    }

    public function envio(OperacionDocumentoRequest $request, GeneratedDocument $documento): JsonResponse
    {
        $this->authorize('operar', $documento);
        $request->validate(['paqueteria' => ['required'], 'numero_guia' => ['required']]);
        $comprobante = $request->file('comprobante');

        return $this->respuesta($this->flujo->registrarEnvio(
            $documento,
            $request->user(),
            [
                'paqueteria' => (string) $request->validated('paqueteria'),
                'numero_guia' => (string) $request->validated('numero_guia'),
                'observaciones' => $request->validated('observaciones'),
            ],
            $comprobante instanceof UploadedFile ? $comprobante : null,
        ));
    }

    public function recepcion(OperacionDocumentoRequest $request, GeneratedDocument $documento): JsonResponse
    {
        $this->authorize('operar', $documento);

        return $this->respuesta($this->flujo->registrarRecepcion($documento, $request->user(), $request->validated('observaciones')));
    }

    public function escaneo(ArchivoLaboralRequest $request, GeneratedDocument $documento): JsonResponse
    {
        $this->authorize('operar', $documento);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        return $this->respuesta($this->flujo->registrarEscaneo($documento, $request->user(), $archivo, $request->validated('observaciones')));
    }

    public function archivar(OperacionDocumentoRequest $request, GeneratedDocument $documento): JsonResponse
    {
        $this->authorize('operar', $documento);

        return $this->respuesta($this->flujo->archivar($documento, $request->user(), $request->validated('observaciones')));
    }

    public function cancelar(OperacionDocumentoRequest $request, GeneratedDocument $documento): JsonResponse
    {
        $this->authorize('cancelar', $documento);
        $request->validate(['motivo' => ['required']]);

        return $this->respuesta($this->flujo->cancelar($documento, $request->user(), (string) $request->validated('motivo')));
    }

    private function respuesta(GeneratedDocument $documento): JsonResponse
    {
        return response()->json(['data' => $this->consulta->aArray($documento->refresh()->load(['colaborador', 'seguimientoFisico']), true)]);
    }
}
