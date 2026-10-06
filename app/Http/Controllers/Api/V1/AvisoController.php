<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AlcanceAviso;
use App\Http\Controllers\Controller;
use App\Models\Aviso;
use App\Services\Avisos\AvisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Avisos de RH para la app móvil del colaborador. Mismo AvisoService que el
 * sitio web (Rh\AvisoController / Colaborador\AvisoController): nunca se
 * duplica el cálculo de a quién le llega un aviso ni el envío de push.
 */
class AvisoController extends Controller
{
    public function __construct(private readonly AvisoService $avisos) {}

    public function index(Request $request): JsonResponse
    {
        $colaborador = $request->user()->colaborador;
        abort_if($colaborador === null, 403, 'Tu cuenta no tiene un colaborador enlazado.');

        $porPagina = min(50, max(1, $request->integer('per_page', 20)));
        $paginador = $this->avisos->paraColaborador($colaborador, $porPagina);

        return response()->json([
            'data' => $paginador->items(),
            'meta' => [
                'current_page' => $paginador->currentPage(),
                'last_page' => $paginador->lastPage(),
                'per_page' => $paginador->perPage(),
                'total' => $paginador->total(),
            ],
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
