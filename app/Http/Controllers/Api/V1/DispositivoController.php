<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegistrarPushTokenRequest;
use App\Http\Requests\Api\V1\RevocarPushTokenRequest;
use App\Services\MobilePush\PushTokenService;
use Illuminate\Http\JsonResponse;

/**
 * Registro/revocacion de dispositivos moviles del usuario autenticado para
 * push (Expo). No requiere permiso especial mas alla de auth:sanctum: un
 * usuario siempre puede administrar sus propios dispositivos. Ver
 * docs/PUSH_NOTIFICATIONS.md.
 */
class DispositivoController extends Controller
{
    public function __construct(private readonly PushTokenService $dispositivos) {}

    public function registrarPushToken(RegistrarPushTokenRequest $request): JsonResponse
    {
        $dispositivo = $this->dispositivos->registrar($request->user(), $request->validated());

        return response()->json([
            'message' => 'Dispositivo registrado.',
            'data' => [
                'id' => $dispositivo->id,
                'platform' => $dispositivo->platform,
                'device_name' => $dispositivo->device_name,
            ],
        ], 201);
    }

    public function revocarPushToken(RevocarPushTokenRequest $request): JsonResponse
    {
        $revocado = $this->dispositivos->revocar($request->user(), $request->validated('token'));

        if (! $revocado) {
            return response()->json(['message' => 'Dispositivo no encontrado.'], 404);
        }

        return response()->json(['message' => 'Dispositivo revocado.']);
    }
}
