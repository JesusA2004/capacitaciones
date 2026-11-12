<?php

namespace App\Http\Controllers\Registro;

use App\Exceptions\Incorporacion\InvitacionInvalidaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegistrarDesdeQrRequest;
use App\Services\Autenticacion\AutenticacionService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * «Registrarme aquí en la web» desde la pantalla del QR (quien no puede
 * instalar la APK: iPhone, Mac). Mismas reglas y mismo servicio que la app
 * (POST api/v1/incorporacion/invitaciones/{token}/registrar →
 * IncorporacionInvitacionService::registrarUsuario); en vez de un token de
 * Sanctum abre la sesión web y lleva al asistente de registro (mi-registro).
 */
class RegistroQrWebController extends Controller
{
    public function __construct(
        private readonly IncorporacionInvitacionService $invitaciones,
        private readonly AutenticacionService $autenticacion,
    ) {}

    public function registrar(RegistrarDesdeQrRequest $request, string $token): RedirectResponse
    {
        try {
            $invitacion = $this->invitaciones->validar($token);
            $usuario = $this->invitaciones->registrarUsuario($invitacion, $request->validated());
        } catch (InvitacionInvalidaException $e) {
            return back()->withErrors(['registro' => $e->getMessage()])->withInput($request->except(['password', 'password_confirmation']));
        }

        Auth::guard('web')->login($usuario);
        $request->session()->regenerate();
        $this->autenticacion->registrarAcceso($usuario);

        return redirect()->route('mi-registro.show')->with('toast', [
            'type' => 'success',
            'message' => sprintf('Tu cuenta quedó creada. Para entrar después usa tu usuario «%s» y tu contraseña.', (string) $usuario->username),
        ]);
    }
}
