<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Enums\EstadoUsuario;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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

        // Bloquea el login web de un colaborador dado de baja/suspendido
        // (mismo criterio que Api\V1\AuthController::login() para la app
        // móvil): `en_incorporacion` sigue pudiendo entrar. Sin esto,
        // Fortify solo valida credenciales y deja entrar a cualquier
        // usuario sin importar su `estatus`.
        Fortify::authenticateUsing(function (Request $request) {
            $usuario = User::query()->where('email', $request->email)->first();

            if ($usuario === null || ! Hash::check((string) $request->password, $usuario->password)) {
                return null;
            }

            if (! in_array($usuario->estatus, [EstadoUsuario::Activo, EstadoUsuario::EnIncorporacion], true)) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Tu cuenta está desactivada. Contacta a Recursos Humanos.',
                ]);
            }

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

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

    }
}
