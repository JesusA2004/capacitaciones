<?php

namespace App\Http\Middleware;

use App\Services\Navigation\NavigationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
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

        // Puente de toasts: casi todos los controllers avisan con
        // `back()->with('toast', [...])` (flash de sesión), pero el frontend
        // (resources/js/lib/flashToast.ts) solo escucha el canal `flash` de
        // Inertia 3. Sin este puente esos mensajes nunca se mostraban.
        $toast = $request->hasSession() ? $request->session()->get('toast') : null;

        if (is_array($toast) && isset($toast['type'], $toast['message'])) {
            Inertia::flash('toast', $toast);
        }

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
                'modoActual' => $this->navegacion->modoActual($user, $this->cookieModoNavegacion($request)),
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

    /**
     * `Request::cookie()` puede devolver `array|string|null` (un mismo
     * nombre de cookie repetido en la petición HTTP se agrupa en arreglo);
     * la cookie de modo de navegación siempre es un valor simple, así que
     * cualquier otra forma se trata como "sin cookie".
     */
    private function cookieModoNavegacion(Request $request): ?string
    {
        $valor = $request->cookie(NavigationService::nombreCookie());

        return is_string($valor) ? $valor : null;
    }
}
