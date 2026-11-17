<?php

namespace App\Services\Administracion;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\RolPermisoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alta de CUENTAS DE ACCESO desde Administración → Usuarios (la persona ya
 * existe como Colaborador; aquí solo nace su login). Reglas:
 *  - solo colaboradores vigentes (activos o en incorporación) y sin baja:
 *    a alguien dado de baja nunca se le abre acceso por aquí;
 *  - solo dentro del alcance organizacional de quien crea la cuenta;
 *  - cuenta + vínculo + roles en una sola transacción (si un rol no se puede
 *    dar, no queda una cuenta a medias).
 * El correo para establecer contraseña sale DESPUÉS de confirmar la
 * transacción y su fallo nunca deshace el alta.
 */
class CuentaUsuarioService
{
    public function __construct(
        private readonly RolPermisoService $roles,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Colaboradores a los que $actor puede abrirles cuenta (selector del
     * diálogo «Nuevo usuario»): vigentes, sin cuenta y en su alcance.
     *
     * @return Builder<Colaborador>
     */
    public function colaboradoresSinCuenta(User $actor): Builder
    {
        return $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $actor)
            ->whereDoesntHave('user')
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereNull('fecha_baja');
    }

    /**
     * @param  list<string>  $roles
     */
    public function crear(int $colaboradorId, ?string $email, array $roles, User $actor): User
    {
        $usuario = DB::transaction(function () use ($colaboradorId, $email, $roles, $actor): User {
            $colaborador = Colaborador::query()->where('id', $colaboradorId)->whereDoesntHave('user')->lockForUpdate()->first();

            if ($colaborador === null) {
                throw ValidationException::withMessages(['colaborador_id' => 'Ese colaborador ya tiene cuenta o no existe.']);
            }

            if (! $this->esVigente($colaborador)) {
                throw ValidationException::withMessages(['colaborador_id' => 'Ese colaborador está dado de baja: no se le puede abrir una cuenta de acceso.']);
            }

            if (! $this->alcance->alcanzaColaborador($actor, $colaborador)) {
                throw ValidationException::withMessages(['colaborador_id' => 'Ese colaborador está fuera de tu alcance.']);
            }

            $usuario = User::create([
                'colaborador_id' => $colaborador->id,
                'name' => $colaborador->name,
                'apellidos' => $colaborador->apellidos,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);

            $this->roles->asignarRoles($usuario, $roles, $actor);

            return $usuario;
        });

        if ($usuario->email !== null) {
            try {
                Password::broker()->sendResetLink(['email' => $usuario->email]);
            } catch (Throwable $e) {
                Log::warning('Cuenta creada, pero no se pudo enviar el correo para establecer contraseña.', ['usuario_id' => $usuario->id, 'error' => $e->getMessage()]);
            }
        }

        return $usuario;
    }

    private function esVigente(Colaborador $colaborador): bool
    {
        return in_array($colaborador->estatus->value, EstadoUsuario::valoresVigentes(), true)
            && $colaborador->fecha_baja === null;
    }
}
