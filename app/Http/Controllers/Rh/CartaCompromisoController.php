<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\CartaCompromisoService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PDF de la carta compromiso de documentos pendientes desde el expediente
 * web. Mismo criterio de acceso que ver el expediente
 * (AlcanceOrganizacionalService::puedeVerExpediente).
 */
class CartaCompromisoController extends Controller
{
    public function __construct(
        private readonly CartaCompromisoService $cartas,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function pdf(Request $request, Colaborador $colaborador): Response
    {
        abort_unless($this->alcance->puedeVerExpediente($request->user(), $colaborador), 403);

        $carta = $this->cartas->ultima($colaborador);

        abort_if($carta === null, 404, 'Este colaborador no tiene carta compromiso.');

        return response($this->cartas->pdf($carta, $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="Carta compromiso %s.pdf"', (string) $carta->folio),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
