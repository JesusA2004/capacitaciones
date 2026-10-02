<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\EstadoCierreLaboral;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreUsuarioRequest;
use App\Http\Requests\Administracion\UpdateUsuarioRequest;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\User;
use App\Notifications\CredencialesActualizadasNotification;
use App\Services\Administracion\AccesoCuentaService;
use App\Services\Administracion\GeneradorPasswordService;
use App\Services\AlcanceOrganizacionalService;
use App\Services\RolPermisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
 *
 * Expone un listado global (index()) además de la gestión contextual desde la
 * pestaña «Cuenta» de cada expediente: el listado aparte es el catálogo de
 * cuentas de acceso (correo, roles, estado, 2FA, último acceso); Expedientes
 * sigue siendo el lugar para dar de alta/editar la cuenta en el contexto de
 * un colaborador específico. Ambos llaman a los mismos endpoints.
 */
class UsuarioController extends Controller
{
    public function __construct(
        private readonly RolPermisoService $rolPermisoService,
        private readonly GeneradorPasswordService $generadorPassword,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AccesoCuentaService $acceso,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $usuario = $request->user();

        // Por defecto solo cuentas ACTIVAS; "inactivos" muestra las dadas de
        // baja (acceso revocado o colaborador dado de baja), "todos" ambas.
        $estado = in_array($request->string('estado')->toString(), ['activos', 'inactivos', 'todos'], true)
            ? $request->string('estado')->toString()
            : 'activos';

        $usuarios = $this->alcance
            ->limitarUsuariosPorAlcance(User::query(), $usuario)
            ->with([
                'colaborador' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'apellidos', 'estatus', 'numero_empleado', 'deleted_at', 'fecha_baja']),
                'roles:id,name',
            ])
            ->when($estado === 'activos', fn ($q) => $this->acceso->soloActivas($q))
            ->when($estado === 'inactivos', fn ($q) => $q->whereNotIn('id', $this->acceso->soloActivas(User::query())->select('id')))
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                // Nombre/apellidos/numero_empleado se buscan en Colaborador
                // (fuente real de la persona); name/apellidos en users son
                // solo una copia de despliegue. email sí vive en users (es
                // la cuenta de acceso).
                $query->where(function ($sub) use ($busqueda) {
                    $sub->where('email', 'like', "%{$busqueda}%")
                        ->orWhereHas('colaborador', function ($c) use ($busqueda) {
                            $c->where('name', 'like', "%{$busqueda}%")
                                ->orWhere('apellidos', 'like', "%{$busqueda}%")
                                ->orWhere('numero_empleado', 'like', "%{$busqueda}%");
                        });
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $enBaja = CierreLaboral::query()
            ->whereIn('colaborador_id', $usuarios->getCollection()->pluck('colaborador_id')->filter())
            ->whereIn('estado', array_map(fn (EstadoCierreLaboral $e) => $e->value, EstadoCierreLaboral::abiertos()))
            ->pluck('colaborador_id')
            ->all();

        $usuarios->getCollection()->transform(function (User $u) use ($enBaja, $usuario) {
            $u->setAttribute('roles_nombres', $u->roles->pluck('name'));
            $u->setAttribute('estado_cuenta', $this->acceso->cuentaActiva($u) ? 'activa' : 'inactiva');
            $u->setAttribute('motivo_inactiva', $this->acceso->motivoInactiva($u));
            $u->setAttribute('estado_colaborador', $this->acceso->estadoColaborador($u, in_array($u->colaborador_id, $enBaja, true)));
            $u->setAttribute('puede_revocar', $u->acceso_bloqueado_en === null && $usuario->can('revocarAcceso', $u));
            $u->setAttribute('puede_restablecer', $u->acceso_bloqueado_en !== null && $usuario->can('restablecerAcceso', $u));

            return $u;
        });

        $total = $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuario)->count();
        $activas = $this->acceso->soloActivas($this->alcance->limitarUsuariosPorAlcance(User::query(), $usuario))->count();

        return Inertia::render('Administracion/Usuarios/Index', [
            'usuarios' => $usuarios,
            'filtros' => [...$request->only('busqueda'), 'estado' => $estado],
            // Solo se usa en el diálogo "Nuevo usuario": se pide con una
            // recarga parcial al abrirlo (Inertia::optional), no viaja en
            // cada visita al listado — con la plantilla completa eran miles
            // de filas para un diálogo cerrado.
            'colaboradoresSinCuenta' => Inertia::optional(fn () => Colaborador::query()
                ->whereDoesntHave('user')
                ->orderBy('name')
                ->get(['id', 'name', 'apellidos'])),
            'rolesDisponibles' => Role::query()->orderBy('name')->pluck('name'),
            'estadisticas' => [
                'total' => $total,
                'activas' => $activas,
                'inactivas' => $total - $activas,
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
     * Baja de CUENTA: bloquea el acceso (web + app) sin tocar la relación
     * laboral — el colaborador sigue en plantilla. Nada se borra. La regla
     * vive en AccesoCuentaService.
     */
    public function revocarAcceso(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('revocarAcceso', $usuario);

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);
        $this->acceso->revocar($usuario, $request->user(), $datos['motivo'] ?? 'Acceso revocado por administración');

        return back()->with('toast', ['type' => 'success', 'message' => 'Cuenta desactivada: ya no puede entrar a la web ni a la app. Su información se conserva.']);
    }

    public function restablecerAcceso(Request $request, User $usuario): RedirectResponse
    {
        $this->authorize('restablecerAcceso', $usuario);

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);
        $this->acceso->restablecer($usuario, $request->user(), $datos['motivo'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Acceso restablecido.']);
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

        $passwordNueva = $datos['password'] ?? $this->generadorPassword->generar();

        $usuario->update(['password' => Hash::make($passwordNueva)]);

        return response()->json(['password' => $passwordNueva]);
    }

    /**
     * Envía la contraseña (ya generada/mostrada por establecerPassword()) al
     * correo del colaborador. Un fallo de envío se loguea pero nunca revierte
     * el cambio de contraseña, que ya quedó aplicado.
     */
    public function enviarPasswordCorreo(Request $request, User $usuario): JsonResponse
    {
        $this->authorize('restablecerPassword', $usuario);

        $datos = $request->validate([
            'password' => ['required', 'string'],
        ]);

        try {
            $usuario->notify(new CredencialesActualizadasNotification($datos['password']));

            return response()->json(['enviado' => true]);
        } catch (\Throwable $excepcion) {
            Log::warning('No se pudo enviar la contraseña por correo.', [
                'usuario_id' => $usuario->id,
                'error' => $excepcion->getMessage(),
            ]);

            return response()->json(['enviado' => false], 502);
        }
    }
}
