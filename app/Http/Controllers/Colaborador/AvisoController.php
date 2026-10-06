<?php

namespace App\Http\Controllers\Colaborador;

use App\Enums\AlcanceAviso;
use App\Http\Controllers\Controller;
use App\Models\Aviso;
use App\Services\Avisos\AvisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Avisos que le llegaron a ESTE colaborador (web). Nunca ve los de otros
 * ni el panel de envío de RH (App\Http\Controllers\Rh\AvisoController).
 */
class AvisoController extends Controller
{
    public function __construct(private readonly AvisoService $avisos) {}

    public function index(Request $request): Response
    {
        $colaborador = $request->user()->colaborador;
        abort_if($colaborador === null, 403, 'Tu cuenta no tiene un colaborador enlazado.');

        return Inertia::render('Portal/Avisos', [
            'avisos' => $this->avisos->paraColaborador($colaborador),
        ]);
    }

    public function marcarLeido(Request $request, Aviso $aviso): JsonResponse
    {
        $this->exigirPuedeVer($request, $aviso);
        $this->avisos->marcarLeido($aviso, $request->user());

        return response()->json(['ok' => true]);
    }

    public function imagen(Request $request, Aviso $aviso): StreamedResponse
    {
        $this->exigirPuedeVer($request, $aviso);

        return $this->avisos->imagen($aviso);
    }

    private function exigirPuedeVer(Request $request, Aviso $aviso): void
    {
        $colaborador = $request->user()->colaborador;
        $esSuyo = $aviso->alcance === AlcanceAviso::Todos || $aviso->colaborador_objetivo_id === $colaborador?->id;
        abort_unless($esSuyo, 403);
    }
}
