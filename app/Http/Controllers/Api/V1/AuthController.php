<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoUsuario;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación por token personal (Sanctum) para la app móvil de
 * colaboradores. No usa cookies ni sesión de Laravel: cada dispositivo
 * recibe un token propio, revocable de forma independiente.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credenciales = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        if (! Auth::once(['email' => $credenciales['email'], 'password' => $credenciales['password']])) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        /** @var User $usuario */
        $usuario = Auth::user();

        // `estatus` vive en Colaborador, no en User (separación
        // Usuario/Colaborador) — un User sin colaborador enlazado no puede
        // entrar. Un colaborador EnIncorporacion si puede entrar: solo vera
        // su checklist de expediente documental (GET /colaborador/incorporacion)
        // hasta que RH apruebe y quede Activo — ver
        // App\Services\Incorporacion\IncorporacionService.
        $colaborador = $usuario->colaborador;

        if ($colaborador === null
            || ! in_array($colaborador->estatus, [EstadoUsuario::Activo, EstadoUsuario::EnIncorporacion], true)
            || $usuario->acceso_bloqueado_en !== null) {
            throw ValidationException::withMessages([
                'email' => 'Tu cuenta no está activa. Contacta a Recursos Humanos.',
            ]);
        }

        $token = $usuario->createToken($credenciales['device_name'] ?? 'app-movil');

        return response()->json([
            'token' => $token->plainTextToken,
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->name,
                'apellidos' => $usuario->apellidos,
                'correo' => $usuario->email,
                'estatus' => $colaborador->estatus->value,
                'roles' => $usuario->getRoleNames(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()->delete();

        return response()->json(['estado' => 'ok']);
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'id' => $usuario->id,
            'nombre' => $usuario->name,
            'apellidos' => $usuario->apellidos,
            'correo' => $usuario->email,
            'roles' => $usuario->getRoleNames(),
            'permisos' => $usuario->getAllPermissions()->pluck('name'),
        ]);
    }
}
