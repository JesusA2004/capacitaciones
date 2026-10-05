<?php

namespace App\Providers;

use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\User;
use App\Observers\CicloLaboralDocumentoObserver;
use App\Policies\RolPolicy;
use App\Services\Autenticacion\AutenticacionService;
use App\Services\Configuracion\ConfiguracionSistemaService;
use App\Services\Navigation\NavigationService;
use App\Services\Organigrama\JefeDirectoService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
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

        // Administración → Configuración: los parámetros que el negocio
        // cambió (calificación mínima, días de aviso...) reemplazan su
        // config() de fábrica. Sin tabla todavía = se quedan los de fábrica.
        $this->app->booted(fn () => app(ConfiguracionSistemaService::class)->aplicarAConfig());

        // Capacidad central de "Mi espacio" (ver NavigationService): la usan
        // las rutas personales (`can:modo-colaborador`), el dashboard y el
        // selector del sidebar — una sola definición, no `portal.ver` suelto.
        Gate::define(NavigationService::GATE_MODO_COLABORADOR, fn (User $usuario): bool => app(NavigationService::class)->puedeUsarModoColaborador($usuario));

        // Ciclo laboral: el estado del alta y los pendientes de "documento
        // rechazado" se sincronizan sin importar por qué camino cambió un
        // documento (web, API, flujo documental). Ver CicloLaboralDocumentoObserver.
        EmployeeDocument::saved(fn (EmployeeDocument $d) => app(CicloLaboralDocumentoObserver::class)->savedEmployeeDocument($d));
        GeneratedDocument::updated(fn (GeneratedDocument $d) => app(CicloLaboralDocumentoObserver::class)->updatedGeneratedDocument($d));

        // Jefe directo = organigrama (JefeDirectoService): cualquier cambio
        // que mueva a alguien en el árbol lo recalcula al terminar la
        // petición, sin importar por qué pantalla, API o servicio entró.
        Colaborador::saved(function (Colaborador $c): void {
            // Alguien sin puesto no ocupa lugar en el organigrama: crearlo
            // no mueve ningún jefe.
            if (($c->wasRecentlyCreated && $c->puesto_id !== null) || $c->wasChanged(['puesto_id', 'sucursal_principal_id', 'estatus'])) {
                app(JefeDirectoService::class)->programar();
            }
        });
        Puesto::saved(function (Puesto $p): void {
            if ($p->wasRecentlyCreated || $p->wasChanged(['puesto_superior_id', 'activo'])) {
                app(JefeDirectoService::class)->programar();
            }
        });
        NodoComercial::saved(function (NodoComercial $n): void {
            if ($n->wasChanged(['puesto_id', 'parent_id', 'sucursal_id'])) {
                app(JefeDirectoService::class)->programar();
            }
        });
        CoberturaPuesto::saved(fn () => app(JefeDirectoService::class)->programar());
        CoberturaPuesto::deleted(fn () => app(JefeDirectoService::class)->programar());

        $this->configureRateLimiting();
    }

    /**
     * Límites de la API móvil (sección 25: rate limiting): general por
     * cuenta, login por correo+IP y operaciones pesadas (cargas/importaciones).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(10)->by(app(AutenticacionService::class)->llaveLimite($request->input('username') ?? $request->input('email'), $request->ip())));
        RateLimiter::for('api-cargas', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('api-reautenticar', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id.'|'.$request->ip()));
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
