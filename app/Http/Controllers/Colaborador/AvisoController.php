<?php

namespace App\Http\Controllers\Colaborador;

use App\Enums\AlcanceAviso;
use App\Http\Controllers\Controller;
use App\Models\Aviso;
use App\Services\Avisos\AvisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Para el colaborador, un aviso de RH es una notificación más: ya no existe
 * un módulo "Avisos" separado para el usuario final (ver
 * NotificationFeedService). `index()` solo conserva el enlace antiguo (app
 * vieja, favoritos guardados) y redirige al Centro de Notificaciones
 * unificado; marcar leído/archivo siguen existiendo porque el feed
 * unificado los sigue usando.
 */
class AvisoController extends Controller
{
    public function __construct(private readonly AvisoService $avisos) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('portal.notificaciones');
    }

    public function marcarLeido(Request $request, Aviso $aviso): JsonResponse
    {
        $this->exigirPuedeVer($request, $aviso);
        $this->avisos->marcarLeido($aviso, $request->user());

        return response()->json(['ok' => true]);
    }

    /**
     * Adjunto del aviso (imagen o PDF), inline y solo para quien lo recibe.
     */
    public function archivo(Request $request, Aviso $aviso): StreamedResponse
    {
        $this->exigirPuedeVer($request, $aviso);

        return $this->avisos->archivo($aviso);
    }

    /**
     * Compatibilidad con versiones anteriores de la app (solo imágenes).
     */
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
