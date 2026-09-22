<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\DecisionRequest;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\ReciboNomina;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Colaboradores\JerarquiaColaboradorService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\DocumentosLaborales\DocumentoLaboralConsultaService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Nomina\PrestamoAutorizacionService;
use App\Services\Nomina\ReciboNominaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Autoservicio del colaborador en la app (siempre SU propia información: el
 * colaborador sale de la sesión, nunca de un id en la URL; los recursos por
 * id se validan con su Policy para impedir IDOR).
 */
class CicloLaboralColaboradorController extends Controller
{
    use RespondePaginado;

    public function __construct(
        private readonly ExpedienteService $expediente,
        private readonly AltaColaboradorService $altas,
        private readonly DocumentoLaboralConsultaService $documentos,
        private readonly FlujoDocumentalService $flujo,
        private readonly MotorDocumentalService $motor,
        private readonly ReciboNominaService $recibos,
        private readonly PrestamoAutorizacionService $prestamos,
        private readonly ContratoLaboralService $contratos,
        private readonly JerarquiaColaboradorService $jerarquia,
    ) {}

    public function expediente(Request $request): JsonResponse
    {
        $colaborador = $this->colaborador($request);

        return response()->json(['data' => [
            'estado_alta' => $colaborador->estado_alta?->value,
            'estado_alta_etiqueta' => $colaborador->estado_alta?->etiqueta(),
            'expediente' => $this->expediente->estadoDocumental($colaborador),
            'expediente_cerrado' => $colaborador->expediente_cerrado_en !== null,
        ]]);
    }

    public function documentosPendientes(Request $request): JsonResponse
    {
        $colaborador = $this->colaborador($request);
        $documental = $this->expediente->estadoDocumental($colaborador);

        return response()->json(['data' => [
            'por_cargar' => array_values(array_filter($documental['documentos'], fn (array $d) => in_array($d['estado'], ['pendiente', 'rechazado', 'requiere_correccion', 'vencido', 'cambio_autorizado'], true))),
            'por_firmar' => array_map(fn (GeneratedDocument $d) => $this->documentos->aArray($d), $this->documentos->delColaborador($colaborador, 'pendientes_firma')->items()),
        ]]);
    }

    public function documentosLaborales(Request $request): JsonResponse
    {
        return $this->paginado(
            $this->documentos->delColaborador($this->colaborador($request), $request->string('estado')->toString() ?: null),
            fn (GeneratedDocument $d) => $this->documentos->aArray($d),
        );
    }

    public function descargarDocumento(GeneratedDocument $documento): StreamedResponse
    {
        $this->authorize('descargar', $documento);

        return $this->motor->respuesta($documento);
    }

    /**
     * Aceptación / firma digital del colaborador (registra fecha, IP, user
     * agent y hash del PDF aceptado). Distinta de la firma física + huella.
     */
    public function firmarDocumento(DecisionRequest $request, GeneratedDocument $documento): JsonResponse
    {
        $this->authorize('firmar', $documento);
        $request->validate(['acepto' => ['accepted']], ['acepto.accepted' => 'Confirma que leíste y aceptas el documento.']);

        $documento = $this->flujo->firmarDigitalmente($documento, $request->user(), $request->validated('comentario'));

        return response()->json(['data' => $this->documentos->aArray($documento->refresh())]);
    }

    public function contratos(Request $request): JsonResponse
    {
        $colaborador = $this->colaborador($request);

        return response()->json(['data' => $colaborador->contratos()->with('documento')->get()->map(fn (ContratoLaboral $c) => $this->contratos->aArray($c))->values()]);
    }

    public function recibos(Request $request): JsonResponse
    {
        return $this->paginado(
            $this->recibos->delColaborador($this->colaborador($request), $request->integer('per_page', 20)),
            fn (ReciboNomina $r) => $this->recibos->aArray($r),
        );
    }

    public function recibo(ReciboNomina $recibo): JsonResponse
    {
        $this->authorize('ver', $recibo);

        return response()->json(['data' => $this->recibos->aArray($recibo, true)]);
    }

    public function reciboPdf(ReciboNomina $recibo): StreamedResponse
    {
        $this->authorize('ver', $recibo);

        return $this->recibos->respuestaPdf($recibo);
    }

    public function prestamos(Request $request): JsonResponse
    {
        $colaborador = $this->colaborador($request);

        return response()->json(['data' => $colaborador->prestamos()->get()->map(fn (Prestamo $p) => $this->prestamos->aArray($p))->values()]);
    }

    public function prestamo(Prestamo $prestamo): JsonResponse
    {
        $this->authorize('ver', $prestamo);

        return response()->json(['data' => $this->prestamos->aArray($prestamo, true)]);
    }

    public function jerarquia(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->jerarquia->jerarquia($this->colaborador($request))]);
    }

    public function alta(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->altas->checklist($this->colaborador($request))]);
    }

    private function colaborador(Request $request): Colaborador
    {
        $colaborador = $request->user()->colaborador;

        if ($colaborador === null) {
            abort(404, 'Tu cuenta no está vinculada a un expediente de colaborador.');
        }

        return $colaborador;
    }
}
