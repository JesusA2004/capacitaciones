<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incorporacion\GuardarSeccionSolicitudEmpleoRequest;
use App\Models\Colaborador;
use App\Services\Incorporacion\SolicitudEmpleo\SolicitudEmpleoRegistroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Solicitud de empleo + Estudio socioeconómico de la cuenta en sesión
 * (siempre la propia, nunca por id). Durante el registro se captura todo;
 * después solo se llena lo que sigue vacío. Mismas reglas que la web
 * (App\Http\Controllers\Colaborador\MiSolicitudEmpleoController) vía
 * SolicitudEmpleoRegistroService. Contrato JSON en
 * docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md.
 */
class SolicitudEmpleoRegistroController extends Controller
{
    public function __construct(private readonly SolicitudEmpleoRegistroService $solicitud) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->solicitud->estadoPropio($request->user(), $this->colaboradorDe($request))]);
    }

    public function guardar(GuardarSeccionSolicitudEmpleoRequest $request, string $seccion): JsonResponse
    {
        $usuario = $request->user();
        $colaborador = $this->colaboradorDe($request);

        $personales = $this->solicitud->guardarPropio($usuario, $colaborador, $seccion, $request->datos(), $request->noAplica());

        return response()->json(['data' => $this->solicitud->estadoPropio($usuario, $colaborador->refresh()), 'message' => $this->solicitud->mensajeGuardado($usuario, $personales)]);
    }

    private function colaboradorDe(Request $request): Colaborador
    {
        $colaborador = $request->user()?->colaborador;
        abort_if($colaborador === null, 422, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }
}
