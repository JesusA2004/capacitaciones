<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegistrarPushTokenRequest;
use App\Http\Requests\Api\V1\RevocarPushTokenRequest;
use App\Models\MobileDevice;
use App\Services\MobilePush\PushNotifier;
use App\Services\MobilePush\PushTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro/revocacion de dispositivos moviles del usuario autenticado para
 * push (Expo). No requiere permiso especial mas alla de auth:sanctum: un
 * usuario siempre puede administrar sus propios dispositivos. Ver
 * docs/PUSH_NOTIFICATIONS.md.
 */
class DispositivoController extends Controller
{
    public function __construct(
        private readonly PushTokenService $dispositivos,
        private readonly PushNotifier $push,
    ) {}

    /**
     * Push de prueba para validar un APK real (Diagnostico Push de la app).
     * Solo a los dispositivos ACTIVOS de la cuenta autenticada: nunca acepta
     * un token del cliente, asi que no sirve para enviar a terceros. Ver
     * config('expo.push_prueba') para cuando esta habilitado.
     */
    public function pushPrueba(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless(config('expo.push_prueba') || $usuario->can('app_releases.publicar'), 403, 'El envío de notificaciones de prueba no está habilitado.');

        $dispositivos = MobileDevice::query()->where('user_id', $usuario->id)->activos()->count();

        if ($dispositivos === 0) {
            return response()->json(['message' => 'Tu cuenta no tiene dispositivos registrados para notificaciones.'], 422);
        }

        $this->push->aUsuarioConDatos($usuario, 'Notificación de prueba', 'Las notificaciones de Mr. Lana People funcionan correctamente.', [
            'type' => 'push_test',
            'resource_id' => null,
        ]);

        return response()->json(['message' => 'Notificación de prueba enviada.', 'data' => ['dispositivos' => $dispositivos]], 202);
    }

    public function registrarPushToken(RegistrarPushTokenRequest $request): JsonResponse
    {
        $dispositivo = $this->dispositivos->registrar($request->user(), [
            'token' => $request->string('token')->toString(),
            'platform' => $request->string('platform')->toString(),
            'device_name' => $request->filled('device_name') ? $request->string('device_name')->toString() : null,
            'app_version' => $request->filled('app_version') ? $request->string('app_version')->toString() : null,
        ]);

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
