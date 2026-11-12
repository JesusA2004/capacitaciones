<?php

namespace App\Http\Controllers\Colaborador;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incorporacion\GuardarSeccionSolicitudEmpleoRequest;
use App\Models\Colaborador;
use App\Services\Incorporacion\SolicitudEmpleo\SolicitudEmpleoRegistroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mi solicitud de empleo» en la web: lo mismo que pide la app (Solicitud de
 * empleo + Estudio socioeconómico) para quien no puede instalarla (iPhone,
 * Mac). Siempre la cuenta en sesión; reglas en SolicitudEmpleoRegistroService
 * (las mismas de App\Http\Controllers\Api\V1\SolicitudEmpleoRegistroController).
 */
class MiSolicitudEmpleoController extends Controller
{
    public function __construct(private readonly SolicitudEmpleoRegistroService $solicitud) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Portal/SolicitudEmpleo', [
            'estado' => $this->solicitud->estadoPropio($request->user(), $this->colaboradorDe($request)),
        ]);
    }

    public function guardar(GuardarSeccionSolicitudEmpleoRequest $request, string $seccion): JsonResponse
    {
        $usuario = $request->user();
        $colaborador = $this->colaboradorDe($request);

        $this->solicitud->guardarPropio($usuario, $colaborador, $seccion, $request->datos(), $request->noAplica());

        return response()->json(['data' => $this->solicitud->estadoPropio($usuario, $colaborador->refresh()), 'message' => 'Sección guardada.']);
    }

    private function colaboradorDe(Request $request): Colaborador
    {
        $colaborador = $request->user()?->colaborador;
        abort_if($colaborador === null, 403, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }
}
