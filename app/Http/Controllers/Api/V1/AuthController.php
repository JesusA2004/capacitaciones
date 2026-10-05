<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Services\Autenticacion\AutenticacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación por token personal (Sanctum) para la app móvil de
 * colaboradores. No usa cookies ni sesión de Laravel: cada dispositivo
 * recibe un token propio, revocable de forma independiente.
 *
 * Se entra con `username` («Jesus Arizmendi») + `password`, con las mismas
 * reglas que el login web (App\Services\Autenticacion\AutenticacionService,
 * docs/AUTENTICACION.md). `email` se acepta temporalmente en lugar de
 * `username` para apps que aún no se actualizan; ya no es la fuente oficial.
 */
class AuthController extends Controller
{
    use PasswordValidationRules;

    public function __construct(private readonly AutenticacionService $autenticacion) {}

    public function login(Request $request): JsonResponse
    {
        $credenciales = $request->validate([
            'username' => ['required_without:email', 'nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [
            'username.required_without' => 'Escribe tu usuario.',
        ]);

        $campo = filled($credenciales['username'] ?? null) ? 'username' : 'email';
        $usuario = $this->autenticacion->verificar((string) $credenciales[$campo], (string) $request->input('password'));

        if ($usuario === null) {
            throw ValidationException::withMessages([
                $campo => 'El usuario o la contraseña no son correctos.',
            ]);
        }

        // `estatus` vive en Colaborador, no en User — un User sin
        // colaborador enlazado no puede entrar. EnIncorporacion sí entra:
        // solo verá su checklist de expediente (GET /colaborador/incorporacion)
        // hasta que RH apruebe — ver App\Services\Incorporacion\IncorporacionService.
        if (! $this->autenticacion->puedeIniciarSesion($usuario)) {
            throw ValidationException::withMessages([
                $campo => 'Tu cuenta no está activa. Contacta a Recursos Humanos.',
            ]);
        }

        $this->autenticacion->registrarAcceso($usuario);
        $colaborador = $usuario->colaborador;
        $token = $usuario->createToken($credenciales['device_name'] ?? 'app-movil');

        return response()->json([
            'token' => $token->plainTextToken,
            // Con contraseña temporal la app debe mandar a la pantalla de
            // cambio (POST /cambiar-contrasena); el resto de la API responde
            // 403 «cambio_contrasena_requerido» mientras tanto.
            'debe_cambiar_contrasena' => $usuario->debe_cambiar_contrasena,
            'usuario' => [
                'id' => $usuario->id,
                'username' => $usuario->username,
                'nombre' => $usuario->name,
                'apellidos' => $usuario->apellidos,
                'correo' => $usuario->email,
                'estatus' => $colaborador?->estatus->value,
                'roles' => $usuario->getRoleNames(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()->delete();

        return response()->json(['estado' => 'ok']);
    }

    /**
     * Reautenticación para desbloquear la app (LockScreen) sin crear ni
     * revocar tokens: el usuario ya está autenticado por el Bearer actual,
     * esto solo confirma que sigue siendo quien dice ser. A diferencia de
     * login(), nunca llama a createToken() ni a currentAccessToken()->delete().
     */
    public function reautenticar(Request $request): Response
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! $this->autenticacion->contrasenaCoincide($request->user(), (string) $request->input('password'))) {
            throw ValidationException::withMessages([
                'password' => 'La contraseña no es válida.',
            ]);
        }

        return response()->noContent();
    }

    /**
     * Cambio de la contraseña temporal (o cualquier cambio propio): pide la
     * actual y una nueva que cumpla Password::defaults().
     */
    public function cambiarContrasena(Request $request): JsonResponse
    {
        $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => $this->passwordRules(),
        ]);

        $usuario = $request->user();

        if (! $this->autenticacion->contrasenaCoincide($usuario, (string) $request->input('password_actual'))) {
            throw ValidationException::withMessages(['password_actual' => 'La contraseña actual no es correcta.']);
        }

        if ($this->autenticacion->contrasenaCoincide($usuario, (string) $request->input('password'))) {
            throw ValidationException::withMessages(['password' => 'La nueva contraseña debe ser distinta de la actual.']);
        }

        $this->autenticacion->cambiarContrasena($usuario, (string) $request->input('password'));

        return response()->json(['estado' => 'ok', 'debe_cambiar_contrasena' => false]);
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'id' => $usuario->id,
            'username' => $usuario->username,
            'nombre' => $usuario->name,
            'apellidos' => $usuario->apellidos,
            'correo' => $usuario->email,
            'debe_cambiar_contrasena' => $usuario->debe_cambiar_contrasena,
            'roles' => $usuario->getRoleNames(),
            'permisos' => $usuario->getAllPermissions()->pluck('name'),
        ]);
    }
}
