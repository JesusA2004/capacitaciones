<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\SesionInduccionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «Presentar inducción»: el PDF OFICIAL de la inducción en el visor (modo
 * presentación, p. ej. RH por Meet). El visor no registra progreso de
 * nadie; para RH (onboarding.gestionar) la pantalla además lleva la sesión
 * de presentación — a quién se la presenta, «Enviar evaluación» y el
 * seguimiento — vía SesionInduccionService. El avance del colaborador por
 * autoestudio vive en Portal\MiProcesoController.
 */
class InduccionPresentacionController extends Controller
{
    public function __construct(
        private readonly InduccionPdfService $pdf,
        private readonly SesionInduccionService $sesiones,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Portal/Induccion', [
            'modoPresentacion' => true,
            'avanceId' => null,
            'pdf' => $this->pdf->datosVisor(),
            'resumen' => null,
            'sesionRh' => $this->sesiones->datosPantalla($request->user()),
        ]);
    }

    /**
     * El PDF oficial, solo con sesión (nunca una URL pública).
     */
    public function pdf(): BinaryFileResponse
    {
        return $this->pdf->respuesta();
    }
}
