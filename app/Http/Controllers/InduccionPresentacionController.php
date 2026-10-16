<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\SesionInduccionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «Presentar inducción» (modo operativo, solo RH con onboarding.gestionar):
 * el PDF OFICIAL de la inducción en el visor, p. ej. por Meet, más la sesión
 * de presentación — a quién se la presenta, «Enviar evaluación» y el
 * seguimiento — vía SesionInduccionService. El colaborador nunca presenta:
 * la ve en «Mi espacio» solo durante la sesión que lo incluye
 * (Portal\MiProcesoController::verInduccion).
 */
class InduccionPresentacionController extends Controller
{
    public function __construct(
        private readonly InduccionPdfService $pdf,
        private readonly SesionInduccionService $sesiones,
    ) {}

    /**
     * @throws AuthorizationException sin onboarding.gestionar
     */
    public function show(Request $request): Response
    {
        $usuario = $request->user();

        if (! $this->sesiones->puedeGestionar($usuario)) {
            throw new AuthorizationException('Solo Recursos Humanos presenta la inducción.');
        }

        return Inertia::render('Portal/Induccion', [
            'modoPresentacion' => true,
            'pdf' => $this->pdf->datosVisor(),
            'sesionRh' => $this->sesiones->datosPantalla($usuario),
        ]);
    }

    /**
     * El PDF oficial, solo con sesión (nunca una URL pública): RH, o el
     * colaborador mientras está en la sesión de RH que lo incluye.
     */
    public function pdf(Request $request): BinaryFileResponse
    {
        return $this->pdf->respuesta($request->user());
    }
}
