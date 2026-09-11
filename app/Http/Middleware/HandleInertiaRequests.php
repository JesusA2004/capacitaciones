<?php

namespace App\Http\Middleware;

use App\Services\Navigation\NavigationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private readonly NavigationService $navegacion) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    ...$user->toArray(),
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Modo de navegacion (seccion 1 de la reestructuracion): separa
            // la experiencia personal ("Mi portal") de la operativa, ver
            // App\Services\Navigation\NavigationService.
            'navegacion' => $user ? [
                'modoActual' => $this->navegacion->modoActual($user, $request->cookie(NavigationService::nombreCookie())),
                'modosDisponibles' => $this->navegacion->modosDisponibles($user),
            ] : null,
            // Para que el frontend pueda ocultar por completo navegacion de
            // modulos detras de un feature flag (ver config/features.php),
            // en vez de mostrar un acceso "falso" a todo el mundo.
            'features' => [
                'capacitacion' => (bool) config('features.capacitacion'),
            ],
            'environment' => app()->environment(),
        ];
    }
}
