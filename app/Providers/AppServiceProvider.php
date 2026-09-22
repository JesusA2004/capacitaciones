<?php

namespace App\Providers;

use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Observers\CicloLaboralDocumentoObserver;
use App\Policies\RolPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        Schema::defaultStringLength(191);

        Gate::policy(Role::class, RolPolicy::class);

        // Ciclo laboral: el estado del alta y los pendientes de "documento
        // rechazado" se sincronizan sin importar por qué camino cambió un
        // documento (web, API, flujo documental). Ver CicloLaboralDocumentoObserver.
        EmployeeDocument::saved(fn (EmployeeDocument $d) => app(CicloLaboralDocumentoObserver::class)->savedEmployeeDocument($d));
        GeneratedDocument::updated(fn (GeneratedDocument $d) => app(CicloLaboralDocumentoObserver::class)->updatedGeneratedDocument($d));

        $this->configureRateLimiting();
    }

    /**
     * Límites de la API móvil (sección 25: rate limiting): general por
     * cuenta, login por correo+IP y operaciones pesadas (cargas/importaciones).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(10)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('api-cargas', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
