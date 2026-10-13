<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\InduccionPdfService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «Presentar inducción»: el PDF OFICIAL de la inducción en el visor (modo
 * presentación, p. ej. RH por Meet). No registra progreso de nadie; el
 * avance del colaborador vive en Portal\MiProcesoController.
 */
class InduccionPresentacionController extends Controller
{
    public function __construct(private readonly InduccionPdfService $pdf) {}

    public function show(): Response
    {
        return Inertia::render('Portal/Induccion', [
            'modoPresentacion' => true,
            'avanceId' => null,
            'pdf' => $this->pdf->datosVisor(),
            'resumen' => null,
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
