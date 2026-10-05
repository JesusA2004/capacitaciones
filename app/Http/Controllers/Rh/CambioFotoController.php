<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Colaboradores\RechazarCambioFotoRequest;
use App\Models\CambioFotoPerfil;
use App\Services\Colaboradores\FotoColaboradorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RH revisa los cambios de foto de perfil que pidieron los colaboradores:
 * ve la foto actual junto a la propuesta y aprueba o rechaza (con motivo).
 * Toda la regla vive en FotoColaboradorService; la API móvil
 * (Api\V1\Rh\CambioFotoController) llama a lo mismo.
 */
class CambioFotoController extends Controller
{
    public function __construct(private readonly FotoColaboradorService $fotos) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CambioFotoPerfil::class);

        return Inertia::render('Rh/CambiosFoto/Index', [
            'cambios' => $this->fotos->pendientesPara($request->user())
                ->map(fn (CambioFotoPerfil $c) => $this->fotos->filaRevision($c))
                ->values(),
        ]);
    }

    public function propuesta(Request $request, CambioFotoPerfil $cambio): StreamedResponse
    {
        $this->authorize('verPropuesta', $cambio);

        return $this->fotos->respuestaPropuesta($cambio);
    }

    public function aprobar(Request $request, CambioFotoPerfil $cambio): RedirectResponse
    {
        $this->authorize('revisar', $cambio);
        $this->fotos->aprobar($cambio, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Foto aprobada: ya es la foto oficial.']);
    }

    public function rechazar(RechazarCambioFotoRequest $request, CambioFotoPerfil $cambio): RedirectResponse
    {
        $this->authorize('revisar', $cambio);
        $this->fotos->rechazar($cambio, $request->user(), $request->validated('motivo'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Cambio de foto rechazado. Se avisó al colaborador.']);
    }
}
