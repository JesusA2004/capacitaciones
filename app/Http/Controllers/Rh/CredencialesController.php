<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\Autenticacion\CredencialesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Generar credenciales» (web): usuario + contraseña temporal para copiar.
 * Sin correo. La lógica vive en CredencialesService (la API móvil usa el
 * mismo servicio en Api\V1\Rh\CredencialesController).
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
