<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\EvaluacionInduccionIntento;
use App\Services\Onboarding\EvaluacionInduccionPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * PDF del formato FO-RH-EI de un intento de evaluación de inducción. Lleva
 * la clave de respuestas: solo RH con permiso de calificar y alcance sobre
 * el colaborador (EvaluacionInduccionPolicy::calificar) — el colaborador
 * nunca ve el suyo. Misma regla que la API (EvaluacionInduccionPdfService).
 */
class EvaluacionInduccionPdfController extends Controller
{
    public function __construct(private readonly EvaluacionInduccionPdfService $pdf) {}

    /** PDF real en el navegador; no guarda nada. */
    public function vistaPrevia(EvaluacionInduccionIntento $intento): Response
    {
        $intento->loadMissing('evaluacion');
        $this->authorize('descargarPdf', $intento->evaluacion);

        return $this->pdf->vistaPrevia($intento);
    }

    public function descargar(EvaluacionInduccionIntento $intento): Response
    {
        $intento->loadMissing('evaluacion');
        $this->authorize('descargarPdf', $intento->evaluacion);

        return $this->pdf->descargar($intento);
    }

    public function guardarEnExpediente(Request $request, EvaluacionInduccionIntento $intento): RedirectResponse
    {
        $intento->loadMissing('evaluacion');
        $this->authorize('descargarPdf', $intento->evaluacion);

        try {
            $this->pdf->guardarEnExpediente($intento, $request->user());
        } catch (ValidationException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => (string) collect($e->errors())->flatten()->first()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Evaluación guardada en el expediente del colaborador.']);
    }
}
