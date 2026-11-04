<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incorporacion\GuardarSeccionSolicitudEmpleoRequest;
use App\Models\Colaborador;
use App\Services\Incorporacion\SolicitudEmpleo\PerfilIngresoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RH: lo que la persona capturó para la Solicitud de empleo y el Estudio
 * socioeconómico, por sección, y completar SOLO lo que falta (JSON; mismo
 * controlador para la API v1 de RH). La vista previa y la generación de
 * ambos PDF son los «Documentos del proceso» (proceso incorporacion).
 */
class SolicitudEmpleoIngresoController extends Controller
{
    public function __construct(private readonly PerfilIngresoService $perfil) {}

    public function show(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($this->perfil->puedeGestionar($request->user(), $colaborador), 403, 'No puedes ver la solicitud de empleo de esta persona.');

        return response()->json(['data' => $this->perfil->estado($colaborador, PerfilIngresoService::ORIGEN_RH)]);
    }

    public function guardar(GuardarSeccionSolicitudEmpleoRequest $request, Colaborador $colaborador, string $seccion): JsonResponse
    {
        abort_unless($this->perfil->puedeGestionar($request->user(), $colaborador), 403, 'No puedes completar la solicitud de empleo de esta persona.');

        $this->perfil->guardarSeccion($colaborador, $seccion, $request->datos(), $request->noAplica(), $request->user(), PerfilIngresoService::ORIGEN_RH);

        return response()->json([
            'data' => $this->perfil->estado($colaborador->refresh(), PerfilIngresoService::ORIGEN_RH),
            'message' => 'Datos guardados.',
        ]);
    }
}
