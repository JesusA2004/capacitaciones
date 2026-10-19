<?php

namespace App\Http\Middleware;

use App\Services\Navigation\NavigationService;
use Database\Seeders\RolesYPermisosSeeder;
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
                    // super_admin ve en el menú todas las herramientas
                    // operativas aunque su rol en producción no tenga aún algún
                    // permiso nuevo (mismo criterio que su Gate::before).
                    'permissions' => $user->hasRole('super_admin')
                        ? $user->getAllPermissions()->pluck('name')->merge(RolesYPermisosSeeder::permisosOperativos())->unique()->values()
                        : $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Modo de navegacion (seccion 1 de la reestructuracion): separa
            // la experiencia personal ("Mi portal") de la operativa, ver
            // App\Services\Navigation\NavigationService.
            'navegacion' => $user ? [
                'modoActual' => $this->navegacion->modoActual($user, NavigationService::cookieDe($request)),
                'modosDisponibles' => $this->navegacion->modosDisponibles($user),
            ] : null,
            // Para que el frontend pueda ocultar por completo navegacion de
            // modulos detras de un feature flag (ver config/features.php),
            // en vez de mostrar un acceso "falso" a todo el mundo.
            'features' => [
                'capacitacion' => (bool) config('features.capacitacion'),
                'recibos_colaborador' => (bool) config('features.recibos_colaborador'),
                // Modulos de Desarrollo (etapa 6) aun no liberados: el sidebar
                // los oculta hasta tener pantalla real (ver docs/FEATURE_FLAGS.md).
                'evaluacion_desempeno' => (bool) config('features.evaluacion_desempeno'),
                'nine_box' => (bool) config('features.nine_box'),
                'certificacion' => (bool) config('features.certificacion'),
            ],
            'environment' => app()->environment(),
        ];
    }
}
