<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VistaPreviaCartaCompromisoRequest;
use App\Models\Colaborador;
use App\Services\Expedientes\BloqueoDocumentosService;
use App\Services\Expedientes\CartaCompromisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Carta compromiso de documentos pendientes del colaborador autenticado
 * (siempre la propia, nunca por id). Reglas en
 * App\Services\Expedientes\CartaCompromisoService y BloqueoDocumentosService.
 */
class CartaCompromisoController extends Controller
{
    public function __construct(
        private readonly CartaCompromisoService $cartas,
        private readonly BloqueoDocumentosService $bloqueo,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->bloqueo->resumen($request->user(), $this->colaboradorDe($request))]);
    }

    public function pdf(Request $request): Response
    {
        $carta = $this->cartas->ultima($this->colaboradorDe($request));

        abort_if($carta === null, 404, 'No tienes una carta compromiso.');

        return $this->respuestaPdf($this->cartas->pdf($carta, $request->user()), sprintf('Carta compromiso %s.pdf', (string) $carta->folio));
    }

    public function vistaPrevia(VistaPreviaCartaCompromisoRequest $request): Response
    {
        return $this->respuestaPdf($this->cartas->vistaPrevia($this->colaboradorDe($request), $request->documentos()), 'Vista previa carta compromiso.pdf');
    }

    private function respuestaPdf(string $pdf, string $nombre): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $nombre),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function colaboradorDe(Request $request): Colaborador
    {
        $colaborador = $request->user()->colaborador;

        abort_if($colaborador === null, 422, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }
}
