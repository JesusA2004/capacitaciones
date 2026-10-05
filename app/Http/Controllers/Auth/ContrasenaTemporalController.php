<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Services\Autenticacion\AutenticacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cambio obligatorio de la contraseña temporal en el primer inicio de
 * sesión (App\Http\Middleware\ExigirCambioContrasena). La nueva sigue las
 * reglas normales del sistema (Password::defaults()).
 */
class ContrasenaTemporalController extends Controller
{
    use PasswordValidationRules;

    public function __construct(private readonly AutenticacionService $autenticacion) {}

    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->debe_cambiar_contrasena) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/CambiarContrasena', [
            'username' => $request->user()->username,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate(['password' => $this->passwordRules()]);
        $usuario = $request->user();

        if ($this->autenticacion->contrasenaCoincide($usuario, (string) $request->input('password'))) {
            throw ValidationException::withMessages(['password' => 'La nueva contraseña debe ser distinta de la temporal.']);
        }

        $this->autenticacion->cambiarContrasena($usuario, (string) $request->input('password'));

        return redirect()->route('dashboard')->with('toast', ['type' => 'success', 'message' => 'Contraseña actualizada. ¡Bienvenido!']);
    }
}
