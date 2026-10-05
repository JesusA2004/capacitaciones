<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\Autenticacion\CredencialesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Generar credenciales» desde la app de RH: mismo servicio que la web
 * (Rh\CredencialesController). La contraseña temporal solo viaja en esta
 * respuesta; nunca se guarda en claro.
 */
class CredencialesController extends Controller
{
    public function __construct(private readonly CredencialesService $credenciales) {}

    public function store(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($this->credenciales->puedeGenerar($request->user(), $colaborador), 403);

        return response()->json(['data' => $this->credenciales->generar($colaborador, $request->user())])
            ->header('Cache-Control', 'no-store');
    }
}
