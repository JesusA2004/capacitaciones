<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\EvaluacionInduccionIntento;
use App\Services\Onboarding\EvaluacionInduccionPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PDF del formato FO-RH-EI para RH desde la app. Mismo servicio y misma
 * autorización que la web (Rh\EvaluacionInduccionPdfController): solo quien
 * puede calificar al colaborador; el colaborador nunca ve el suyo.
 */
class EvaluacionInduccionPdfController extends Controller
{
    public function __construct(private readonly EvaluacionInduccionPdfService $pdf) {}

    public function vistaPrevia(EvaluacionInduccionIntento $intento): Response
    {
        $intento->loadMissing('evaluacion');
        $this->authorize('calificar', $intento->evaluacion);

        return $this->pdf->vistaPrevia($intento);
    }

    public function descargar(EvaluacionInduccionIntento $intento): Response
    {
        $intento->loadMissing('evaluacion');
        $this->authorize('calificar', $intento->evaluacion);

        return $this->pdf->descargar($intento);
    }

    public function guardarEnExpediente(Request $request, EvaluacionInduccionIntento $intento): JsonResponse
    {
        $intento->loadMissing('evaluacion');
        $this->authorize('calificar', $intento->evaluacion);

        $documento = $this->pdf->guardarEnExpediente($intento, $request->user());

        return response()->json([
            'message' => 'Evaluación guardada en el expediente del colaborador.',
            'data' => [
                'documento_id' => $documento->id,
                'titulo' => $documento->titulo,
                'categoria' => $documento->categoria?->value,
                'estado' => $documento->estado_flujo?->value,
            ],
        ]);
    }
}
