<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\EstadoUsuario;
use App\Enums\EstatusImss;
use App\Enums\MotivoVacante;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreUsuarioRequest;
use App\Http\Requests\Administracion\UpdateUsuarioRequest;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Asignaciones\AsignacionService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\RolPermisoService;
use App\Services\Vacantes\VacanteAutoGenerationService;
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

class UsuarioController extends Controller
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly RolPermisoService $rolPermisoService,
        private readonly AsignacionService $asignacionService,
        private readonly MovimientoLaboralService $movimientos,
        private readonly VacanteAutoGenerationService $vacantesAutomaticas,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $usuarios = User::withTrashed()
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

        $usuariosVisibles = fn () => $this->alcance->limitarUsuariosPorAlcance(User::withTrashed(), $request->user());

        return Inertia::render('Administracion/Usuarios/Index', [
            'usuarios' => $usuarios,
            'filtros' => $request->only('busqueda', 'sucursal_id', 'estatus'),
            'sucursalesDisponibles' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestosDisponibles' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
            'rolesDisponibles' => Role::query()->orderBy('name')->pluck('name'),
            'estados' => array_map(fn (EstadoUsuario $estado) => ['value' => $estado->value, 'etiqueta' => $estado->etiqueta()], EstadoUsuario::cases()),
            'estadosImss' => array_map(fn (EstatusImss $estado) => ['value' => $estado->value, 'etiqueta' => $estado->etiqueta()], EstatusImss::cases()),
            // Acotadas por el mismo alcance que la tabla: un gerente de sucursal
            // no debe ver totales de toda la organización en estas tarjetas.
            'puedeReactivar' => $request->user()->can('usuarios.reactivar'),
            'puedeRevocarAcceso' => $request->user()->can('usuarios.desactivar'),
            'estadisticas' => [
                'total' => $usuariosVisibles()->count(),
                'activos' => $usuariosVisibles()->where('estatus', EstadoUsuario::Activo->value)->count(),
                'inactivos' => $usuariosVisibles()->where('estatus', '!=', EstadoUsuario::Activo->value)->whereNull('deleted_at')->count(),
                'bajas' => $usuariosVisibles()->whereNotNull('deleted_at')->count(),
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
        $this->movimientos->registrarAlta($usuario, $request->user());

        Password::broker()->sendResetLink(['email' => $usuario->email]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Colaborador creado. Se envió un correo para que establezca su contraseña.',
        ]);
    }

    public function update(UpdateUsuarioRequest $request, User $usuario): RedirectResponse
    {
        $datos = $request->safe()->except(['sucursales_adicionales', 'roles', 'motivo_movimiento', 'crear_vacante_reemplazo']);

        $antes = $this->movimientos->snapshot($usuario);
        $puestoAnteriorId = $antes['puesto_id'];
        $cambiaDePuesto = array_key_exists('puesto_id', $datos)
            && $puestoAnteriorId !== null
            && (int) $datos['puesto_id'] !== $puestoAnteriorId;

        $vacanteId = null;
        if ($cambiaDePuesto && $request->boolean('crear_vacante_reemplazo')) {
            $vacante = Vacante::create([
                'empresa_id' => $antes['empresa_id'],
                'sucursal_id' => $antes['sucursal_id'],
                'departamento_id' => $antes['departamento_id'],
                'puesto_id' => $puestoAnteriorId,
                'motivo' => MotivoVacante::Promocion->value,
                'estado' => 'abierta',
                'fecha_apertura' => now(),
                'observaciones' => $request->string('motivo_movimiento')->toString() ?: null,
                'creado_por' => $request->user()?->id,
            ]);
            $vacanteId = $vacante->id;
        }

        $usuario->update($datos);
        $usuario->sucursalesAdicionales()->sync($request->input('sucursales_adicionales', []));
        $this->rolPermisoService->asignarRoles($usuario, $request->input('roles', []));

        $this->movimientos->registrarCambioPuesto(
            $usuario->fresh(),
            $antes,
            $request->user(),
            $request->string('motivo_movimiento')->toString() ?: null,
            $vacanteId,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador actualizado correctamente.']);
    }

    /**
     * Baja laboral administrativa directa (sin pasar por la aprobación de
     * una Solicitud interna — ver App\Services\Solicitudes\BajaColaboradorService
     * para el flujo aprobado). Termina la relación laboral: estatus
     * Inactivo, soft-delete, historial de movimiento, revoca acceso
     * (tokens/dispositivos) y sincroniza headcount/vacante. NO es lo mismo
     * que revocarAcceso() de abajo — esa solo bloquea el login de alguien
     * que sigue empleado.
     */
    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('delete', $usuario);

        $datos = $request->validate([
            'motivo' => ['nullable', 'string', 'max:500'],
            'crear_vacante' => ['boolean'],
        ]);

        $sucursalId = $usuario->sucursal_principal_id;
        $puestoId = $usuario->puesto_id;

        $this->movimientos->registrarBaja(
            $usuario,
            $request->user(),
            $datos['motivo'] ?? null,
            (bool) ($datos['crear_vacante'] ?? false),
        );

        $usuario->update(['estatus' => EstadoUsuario::Inactivo]);
        $usuario->tokens()->delete();
        $usuario->mobileDevices()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $usuario->delete();

        // La plantilla actual acaba de bajar: sincroniza la vacante
        // automática de (sucursal, puesto) DESPUÉS de que el estatus ya
        // quedó Inactivo (si no, vacantesDerivadas() todavía contaría a
        // este colaborador como activo y el faltante saldría desfasado).
        if ($sucursalId !== null && $puestoId !== null) {
            $this->vacantesAutomaticas->sincronizar($sucursalId, $puestoId);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador dado de baja correctamente.']);
    }

    /**
     * Bloquea el login de un colaborador que SIGUE empleado (a diferencia de
     * destroy()): no toca estatus, no hace soft-delete, no registra
     * movimiento laboral y no sincroniza headcount/vacante, porque para
     * efectos de plantilla sigue activo — solo se le revoca el acceso al
     * sistema (web + API móvil).
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

    /**
     * Revierte una baja lógica: solo super_admin (ver UserPolicy::reactivar()
     * y RolesYPermisosSeeder). El colaborador vuelve a poder iniciar sesión
     * y a contar en la plantilla activa de su (sucursal, puesto), por lo que
     * también resincroniza la vacante automática correspondiente.
     */
    public function reactivar(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('reactivar', $usuario);

        $usuario->restore();
        $usuario->update(['estatus' => EstadoUsuario::Activo]);

        if ($usuario->sucursal_principal_id !== null && $usuario->puesto_id !== null) {
            $this->vacantesAutomaticas->sincronizar($usuario->sucursal_principal_id, $usuario->puesto_id);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador reactivado correctamente.']);
    }
}
