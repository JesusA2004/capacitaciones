<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\EstadoUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreUsuarioRequest;
use App\Http\Requests\Administracion\UpdateUsuarioRequest;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Asignaciones\AsignacionService;
use App\Services\RolPermisoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UsuarioController extends Controller
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly RolPermisoService $rolPermisoService,
        private readonly AsignacionService $asignacionService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $usuarios = User::query()
            ->tap(fn ($query) => $this->alcance->limitarUsuariosPorAlcance($query, $request->user()))
            ->with(['sucursalPrincipal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda) {
                    $sub->where('name', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('email', 'like', "%{$busqueda}%")
                        ->orWhere('numero_empleado', 'like', "%{$busqueda}%");
                });
            })
            ->when($request->integer('sucursal_id'), fn ($query, int $sucursalId) => $query->where('sucursal_principal_id', $sucursalId))
            ->when($request->string('estatus')->toString(), fn ($query, string $estatus) => $query->where('estatus', $estatus))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $usuariosVisibles = fn () => $this->alcance->limitarUsuariosPorAlcance(User::query(), $request->user());

        return Inertia::render('Administracion/Usuarios/Index', [
            'usuarios' => $usuarios,
            'filtros' => $request->only('busqueda', 'sucursal_id', 'estatus'),
            'sucursalesDisponibles' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestosDisponibles' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
            'rolesDisponibles' => Role::query()->orderBy('name')->pluck('name'),
            'estados' => array_map(fn (EstadoUsuario $estado) => ['value' => $estado->value, 'etiqueta' => $estado->etiqueta()], EstadoUsuario::cases()),
            // Acotadas por el mismo alcance que la tabla: un gerente de sucursal
            // no debe ver totales de toda la organización en estas tarjetas.
            'estadisticas' => [
                'total' => $usuariosVisibles()->count(),
                'activos' => $usuariosVisibles()->where('estatus', EstadoUsuario::Activo->value)->count(),
                'inactivos' => $usuariosVisibles()->where('estatus', '!=', EstadoUsuario::Activo->value)->count(),
            ],
        ]);
    }

    public function store(StoreUsuarioRequest $request): RedirectResponse
    {
        $datos = $request->safe()->except(['sucursales_adicionales', 'roles']);

        $usuario = User::create([
            ...$datos,
            'password' => Hash::make(Str::random(40)),
        ]);

        $usuario->sucursalesAdicionales()->sync($request->input('sucursales_adicionales', []));
        $this->rolPermisoService->asignarRoles($usuario, $request->input('roles', []));
        $this->asignacionService->aplicarVigentesA($usuario);

        Password::broker()->sendResetLink(['email' => $usuario->email]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Colaborador creado. Se envió un correo para que establezca su contraseña.',
        ]);
    }

    public function update(UpdateUsuarioRequest $request, User $usuario): RedirectResponse
    {
        $datos = $request->safe()->except(['sucursales_adicionales', 'roles']);

        $usuario->update($datos);
        $usuario->sucursalesAdicionales()->sync($request->input('sucursales_adicionales', []));
        $this->rolPermisoService->asignarRoles($usuario, $request->input('roles', []));

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador actualizado correctamente.']);
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('delete', $usuario);

        $usuario->update(['estatus' => EstadoUsuario::Inactivo]);
        $usuario->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador desactivado correctamente.']);
    }
}
