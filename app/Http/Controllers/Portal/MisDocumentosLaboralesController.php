<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\DecisionRequest;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\DocumentosLaborales\DocumentoLaboralConsultaService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Mis documentos laborales» y «Mis contratos» en la web: lo mismo que la
 * app (Api\V1\CicloLaboralColaboradorController) con los mismos servicios.
 * Siempre la cuenta en sesión: un documento de otra persona, en borrador,
 * cancelado o fuera del autoservicio responde 404 (igual que el listado);
 * además la Policy (GeneratedDocumentPolicy) vuelve a autorizar cada acción.
 *
 * Firma digital: abrir el PDF en el visor registra la consulta (`ver`) y la
 * apertura (`apertura`); sin ambas, FlujoDocumentalService rechaza la firma.
 */
class MisDocumentosLaboralesController extends Controller
{
    public function __construct(
        private readonly DocumentoLaboralConsultaService $documentos,
        private readonly FlujoDocumentalService $flujo,
        private readonly MotorDocumentalService $motor,
        private readonly ContratoLaboralService $contratos,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $colaborador = $this->colaboradorDe($request);
        $filtro = $request->string('estado')->toString() === 'pendientes_firma' ? 'pendientes_firma' : null;

        return Inertia::render('Portal/DocumentosLaborales', [
            'documentos' => $this->documentos->delColaborador($colaborador, $filtro)
                ->withQueryString()
                ->through(fn (GeneratedDocument $d) => [
                    ...$this->documentos->aArray($d, false, $usuario),
                    'puede_firmar' => $usuario->can('firmar', $d),
                ]),
            'filtro' => $filtro,
            'porFirmar' => $this->documentos->delColaborador($colaborador, 'pendientes_firma')->total(),
        ]);
    }

    public function show(Request $request, GeneratedDocument $documento): Response
    {
        $this->exigirPropio($request, $documento);
        $this->authorize('ver', $documento);
        $usuario = $request->user();

        return Inertia::render('Portal/DocumentoLaboral', [
            'documento' => [
                ...$this->documentos->aArray($documento, false, $usuario),
                'puede_firmar' => $usuario->can('firmar', $documento),
            ],
        ]);
    }

    /** PDF en línea para el visor; si la firma está pendiente queda la consulta registrada. */
    public function ver(Request $request, GeneratedDocument $documento): StreamedResponse
    {
        $this->exigirPropio($request, $documento);
        $this->authorize('ver', $documento);
        $respuesta = $this->motor->respuestaConsulta($documento);
        $this->flujo->registrarConsultaParaFirma($documento, $request->user());

        return $respuesta;
    }

    public function descargar(Request $request, GeneratedDocument $documento): StreamedResponse
    {
        $this->exigirPropio($request, $documento);
        $this->authorize('descargar', $documento);

        return $this->motor->respuesta($documento, false);
    }

    /** El visor confirma que el PDF se mostró; sin esto no hay firma. */
    public function apertura(Request $request, GeneratedDocument $documento): JsonResponse
    {
        $this->exigirPropio($request, $documento);
        $this->authorize('firmar', $documento);
        $this->flujo->registrarAperturaParaFirma($documento, $request->user());

        return response()->json(['data' => ['apertura_registrada' => true]]);
    }

    /** Aceptación / firma digital (fecha, IP, navegador y hash del PDF aceptado). */
    public function firmar(DecisionRequest $request, GeneratedDocument $documento): RedirectResponse
    {
        $this->exigirPropio($request, $documento);
        $this->authorize('firmar', $documento);
        $request->validate(['acepto' => ['accepted']], ['acepto.accepted' => 'Confirma que leíste y aceptas el documento.']);

        $documento = $this->flujo->firmarDigitalmente($documento, $request->user(), $request->validated('comentario'));

        // Sin autoservicio documental, el documento ya firmado deja de estar
        // a la vista del colaborador: regresa a su inicio, no a un 404.
        $destino = $this->documentos->esVisibleParaTitular($this->colaboradorDe($request), $documento)
            ? back()
            : redirect()->route('portal.index');

        return $destino->with('toast', ['type' => 'success', 'message' => 'Documento firmado digitalmente.']);
    }

    public function contratos(Request $request): Response
    {
        // El contrato se entrega impreso y firmado (config('features.documentos_laborales_colaborador')).
        abort_unless((bool) config('features.documentos_laborales_colaborador'), 404, 'Tu contrato te lo entrega Recursos Humanos impreso y firmado.');

        $usuario = $request->user();

        return Inertia::render('Portal/MisContratos', [
            'contratos' => $this->colaboradorDe($request)->contratos()->with('documento')->get()
                ->map(fn (ContratoLaboral $c) => $this->contratos->aArray($c, $usuario))
                ->values()
                ->all(),
        ]);
    }

    private function exigirPropio(Request $request, GeneratedDocument $documento): void
    {
        abort_unless($this->documentos->esVisibleParaTitular($this->colaboradorDe($request), $documento), 404, 'Este documento ya no está disponible.');
    }

    private function colaboradorDe(Request $request): Colaborador
    {
        $colaborador = $request->user()?->colaborador;
        abort_if($colaborador === null, 403, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }
}
