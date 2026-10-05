<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Services\Autenticacion\AutenticacionService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Login web por USUARIO («Jesus Arizmendi»), no por correo — mismas
        // reglas que Api\V1\AuthController::login() para la app móvil, en
        // App\Services\Autenticacion\AutenticacionService (docs/AUTENTICACION.md).
        // Bloquea al colaborador dado de baja/suspendido o con el acceso
        // revocado (`en_incorporacion` sí entra): sin esto Fortify solo
        // validaría credenciales. `estatus` vive en Colaborador — un User
        // sin colaborador enlazado no puede iniciar sesión.
        Fortify::authenticateUsing(function (Request $request) {
            $autenticacion = app(AutenticacionService::class);
            $usuario = $autenticacion->verificar((string) $request->input(Fortify::username()), (string) $request->input('password'));

            if ($usuario === null) {
                return null;
            }

            // Se rechaza aquí, sin abrir sesión ni marcar `ultimo_acceso`.
            if (! $autenticacion->puedeIniciarSesion($usuario)) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Tu cuenta está desactivada. Contacta a Recursos Humanos.',
                ]);
            }

            // Sin esto, Administración > Usuarios mostraría "Nunca" para siempre.
            $autenticacion->registrarAcceso($usuario);

            return $usuario;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        // Misma llave para «Jesus Arizmendi», « jesus  arizmendi », etc.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            app(AutenticacionService::class)->llaveLimite($request->input(Fortify::username()), $request->ip()),
        ));
    }
}
