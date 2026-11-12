<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartasCompromiso\GenerarCartaCompromisoRequest;
use App\Services\Expedientes\SolicitudCartaCompromisoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * «Mi expediente» → «Pedir carta compromiso» (modo colaborador): siempre la
 * propia persona ($request->user()), nunca por id. Reglas en
 * SolicitudCartaCompromisoService (mismo servicio que la app).
 */
class MiCartaCompromisoController extends Controller
{
    public function __construct(private readonly SolicitudCartaCompromisoService $solicitudes) {}

    /** PDF de exactamente la carta que se pediría (no guarda nada). */
    public function vistaPrevia(GenerarCartaCompromisoRequest $request): Response
    {
        return response($this->solicitudes->vistaPrevia($request->user(), $request->documentos()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Vista previa carta compromiso.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function store(GenerarCartaCompromisoRequest $request): RedirectResponse
    {
        $carta = $this->solicitudes->solicitar($request->user(), $request->documentos());

        return to_route('mi-expediente', ['tab' => 'documentos'])->with('toast', [
            'type' => 'success',
            'message' => sprintf('Enviaste tu carta compromiso %s. Recursos Humanos la revisará; al aprobarla empieza a correr tu plazo.', (string) $carta->folio),
        ]);
    }
}
