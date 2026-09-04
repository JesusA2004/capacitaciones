<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Incorporacion\InvitacionInvalidaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegistrarDesdeQrRequest;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints públicos (sin auth:sanctum) para el flujo de registro por QR
 * temporal: el token del QR es la única puerta de entrada, validado en cada
 * acción. Un colaborador nunca puede registrarse sin una invitación activa
 * generada antes por RH — ver docs/API_MOVIL.md, "Registro por QR temporal".
 */
class IncorporacionInvitacionController extends Controller
{
    public function __construct(private readonly IncorporacionInvitacionService $invitaciones) {}

    public function validar(Request $request, string $token): JsonResponse
    {
        try {
            $invitacion = $this->invitaciones->validar($token);
        } catch (InvitacionInvalidaException $e) {
            return $this->respuestaInvalida($e);
        }

        return response()->json($this->invitaciones->payloadValidacion($invitacion));
    }

    /** Alias opcional: solo la hoja de ruta de fases, sin repetir los datos prellenados. */
    public function fases(Request $request, string $token): JsonResponse
    {
        try {
            $this->invitaciones->validar($token);
        } catch (InvitacionInvalidaException $e) {
            return $this->respuestaInvalida($e);
        }

        return response()->json(['fases' => $this->invitaciones->fases()]);
    }

    public function registrar(RegistrarDesdeQrRequest $request, string $token): JsonResponse
    {
        try {
            $invitacion = $this->invitaciones->validar($token);
            $usuario = $this->invitaciones->registrarUsuario($invitacion, $request->validated());
        } catch (InvitacionInvalidaException $e) {
            return $this->respuestaInvalida($e);
        }

        $tokenSanctum = $usuario->createToken('app-movil');

        return response()->json([
            'token' => $tokenSanctum->plainTextToken,
            'usuario' => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'apellidos' => $usuario->apellidos,
                'email' => $usuario->email,
                'estatus' => $usuario->estatus->value,
                'roles' => $usuario->getRoleNames(),
                'permisos' => $usuario->getAllPermissions()->pluck('name'),
            ],
            'siguiente_paso' => 'incorporacion',
        ], 201);
    }

    private function respuestaInvalida(InvitacionInvalidaException $e): JsonResponse
    {
        return response()->json([
            'valida' => false,
            'estado' => $e->motivo,
            'message' => $e->getMessage(),
        ], $e->status);
    }
}
