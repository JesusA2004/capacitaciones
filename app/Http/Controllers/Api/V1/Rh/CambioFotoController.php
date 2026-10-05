<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Colaboradores\RechazarCambioFotoRequest;
use App\Models\CambioFotoPerfil;
use App\Services\Colaboradores\FotoColaboradorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cambios de foto de perfil por revisar, desde la app (RH / gerentes con
 * expedientes.revisar). Misma regla que la web: FotoColaboradorService.
 */
class CambioFotoController extends Controller
{
    public function __construct(private readonly FotoColaboradorService $fotos) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CambioFotoPerfil::class);

        return response()->json([
            'data' => $this->fotos->pendientesPara($request->user())
                ->map(fn (CambioFotoPerfil $c) => $this->fotos->filaRevision($c, api: true))
                ->values(),
        ]);
    }

    public function propuesta(Request $request, CambioFotoPerfil $cambio): StreamedResponse
    {
        $this->authorize('verPropuesta', $cambio);

        return $this->fotos->respuestaPropuesta($cambio);
    }

    public function aprobar(Request $request, CambioFotoPerfil $cambio): JsonResponse
    {
        $this->authorize('revisar', $cambio);
        $cambio = $this->fotos->aprobar($cambio, $request->user());

        return response()->json(['message' => 'Foto aprobada: ya es la foto oficial.', 'data' => $this->fotos->filaRevision($cambio, api: true)]);
    }

    public function rechazar(RechazarCambioFotoRequest $request, CambioFotoPerfil $cambio): JsonResponse
    {
        $this->authorize('revisar', $cambio);
        $cambio = $this->fotos->rechazar($cambio, $request->user(), $request->validated('motivo'));

        return response()->json(['message' => 'Cambio de foto rechazado. Se avisó al colaborador.', 'data' => $this->fotos->filaRevision($cambio, api: true)]);
    }
}
