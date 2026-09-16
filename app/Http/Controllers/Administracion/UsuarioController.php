<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreUsuarioRequest;
use App\Http\Requests\Administracion\UpdateUsuarioRequest;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\RolPermisoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Administra únicamente CUENTAS DE ACCESO (correo, roles, estado de acceso,
 * contraseña). Los datos de persona/empleo (sucursal, departamento, puesto,
 * fecha de ingreso, IMSS, periodo de prueba, baja laboral) viven en
 * App\Models\Colaborador y se administran desde
 * App\Http\Controllers\Rh\ExpedienteController — ver docs/ROLES_Y_NAVEGACION.md.
 */
class UsuarioController extends Controller
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly RolPermisoService $rolPermisoService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $usuarios = User::query()
            ->tap(fn ($query) => $this->alcance->limitarUsuariosPorAlcance($query, $request->user()))
            ->with(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id,departamento_id', 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.departamento:id,nombre'])
            ->when($request->string('busqueda')->toString(), function (Builder $query, string $busqueda) {
                $query->where(function (Builder $sub) use ($busqueda) {
                    $sub->where('name', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('email', 'like', "%{$busqueda}%")
                        ->orWhereHas('colaborador', function (Builder $colaboradorQuery) use ($busqueda) {
                            $colaboradorQuery->where('numero_empleado', 'like', "%{$busqueda}%");
                        });
                });
            })
            ->when($request->string('estado_acceso')->toString(), function (Builder $query, string $estado) {
                match ($estado) {
                    'bloqueado' => $query->whereNotNull('acceso_bloqueado_en'),
                    'activo' => $query->whereNull('acceso_bloqueado_en'),
                    default => null,
                };
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $usuariosVisibles = fn () => $this->alcance->limitarUsuariosPorAlcance(User::query(), $request->user());

        return Inertia::render('Administracion/Usuarios/Index', [
            'usuarios' => $usuarios,
            'filtros' => $request->only('busqueda', 'estado_acceso'),
            'colaboradoresSinCuenta' => Colaborador::query()
                ->whereDoesntHave('user')
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name', 'apellidos', 'numero_empleado']),
            'rolesDisponibles' => Role::query()->orderBy('name')->pluck('name'),
            'puedeRevocarAcceso' => $request->user()->can('usuarios.desactivar'),
            // Acotadas por el mismo alcance que la tabla: un gerente de sucursal
            // no debe ver totales de toda la organización en estas tarjetas.
            'estadisticas' => [
                'total' => $usuariosVisibles()->count(),
                'bloqueados' => $usuariosVisibles()->whereNotNull('acceso_bloqueado_en')->count(),
                'activos' => $usuariosVisibles()->whereNull('acceso_bloqueado_en')->count(),
                'sin_verificar' => $usuariosVisibles()->whereNull('email_verified_at')->count(),
            ],
        ]);
    }

    public function store(StoreUsuarioRequest $request): RedirectResponse
    {
        $colaborador = Colaborador::query()->whereDoesntHave('user')->findOrFail($request->integer('colaborador_id'));

        $usuario = User::create([
            'colaborador_id' => $colaborador->id,
            'name' => $colaborador->name,
            'apellidos' => $colaborador->apellidos,
            'email' => $request->string('email')->toString(),
            'password' => Hash::make(Str::random(40)),
        ]);

        $this->rolPermisoService->asignarRoles($usuario, $request->input('roles', []));

        Password::broker()->sendResetLink(['email' => $usuario->email]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Usuario creado. Se envió un correo para que establezca su contraseña.',
        ]);
    }

    public function update(UpdateUsuarioRequest $request, User $usuario): RedirectResponse
    {
        $usuario->update($request->safe()->only(['email', 'zona_horaria']));
        $this->rolPermisoService->asignarRoles($usuario, $request->input('roles', []));

        return back()->with('toast', ['type' => 'success', 'message' => 'Usuario actualizado correctamente.']);
    }

    /**
     * Bloquea el login de un colaborador que SIGUE empleado (a diferencia de
     * la baja laboral, que ahora vive en App\Http\Controllers\Rh\ExpedienteController::darDeBaja()):
     * no toca estatus, no hace soft-delete, no registra movimiento laboral y
     * no sincroniza headcount/vacante, porque para efectos de plantilla
     * sigue activo — solo se le revoca el acceso al sistema (web + API
     * móvil).
     */
    public function revocarAcceso(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('revocarAcceso', $usuario);

        $usuario->update(['acceso_bloqueado_en' => now()]);
        $usuario->tokens()->delete();
        $usuario->mobileDevices()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Acceso al sistema revocado. El colaborador sigue activo en la plantilla.']);
    }

    public function restablecerAcceso(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('restablecerAcceso', $usuario);

        $usuario->update(['acceso_bloqueado_en' => null]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Acceso al sistema restablecido.']);
    }

    /**
     * Establece una contraseña nueva para el colaborador desde el panel.
     * Nunca se puede leer la contraseña actual (se guarda con hash) — esto
     * solo la sobreescribe. Devuelve la contraseña en texto plano una sola
     * vez en la respuesta JSON para que el admin la copie y se la dé al
     * colaborador; no se guarda en texto plano en ningún lado ni se registra
     * en el log.
     */
    public function establecerPassword(Request $request, User $usuario): JsonResponse
    {
        $this->authorize('restablecerPassword', $usuario);

        $datos = $request->validate([
            'password' => ['nullable', 'string', PasswordRule::defaults()],
        ]);

        $passwordNueva = $datos['password'] ?? Str::password(14);

        $usuario->update(['password' => Hash::make($passwordNueva)]);

        return response()->json(['password' => $passwordNueva]);
    }
}
