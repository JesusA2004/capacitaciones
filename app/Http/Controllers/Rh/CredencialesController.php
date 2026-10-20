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
        $datos = $request->validate(CredencialesService::reglasManual());

        return response()->json(['data' => $this->credenciales->generar($colaborador, $request->user(), $datos['password'] ?? null)])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Vista previa del correo de accesos (HTML, contraseña enmascarada) antes
     * de enviarlo: no envía nada.
     */
    public function vistaPreviaCorreo(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($this->credenciales->puedeGenerar($request->user(), $colaborador), 403);

        return response()->json(['data' => $this->credenciales->vistaPreviaCorreo($colaborador)])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * «Enviar accesos por correo» tras mostrar la contraseña: si el correo
     * falla, la contraseña sigue actualizada y se dice explícitamente.
     */
    public function enviarCorreo(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($this->credenciales->puedeGenerar($request->user(), $colaborador), 403);
        $datos = $request->validate(['password' => ['required', 'string']]);

        return $this->credenciales->enviarPorCorreo($colaborador, $request->user(), $datos['password'])
            ? response()->json(['enviado' => true])
            : response()->json(['enviado' => false, 'message' => 'No se pudo enviar el correo. La contraseña sí quedó actualizada: entrégala en privado.'], 502);
    }
}
