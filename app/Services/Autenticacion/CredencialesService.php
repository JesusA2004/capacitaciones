<?php

namespace App\Services\Autenticacion;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Administracion\GeneradorPasswordService;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Generar credenciales» (docs/AUTENTICACION.md): el único botón con el que
 * RH/administración da acceso a un colaborador, sea alta manual, migración,
 * alta digital o reingreso. NUNCA pide correo.
 *
 *  - Sin cuenta → la crea: usuario = primer nombre + primer apellido
 *    (NombreUsuarioService, sufijo 2, 3… si se repite), rol colaborador y
 *    contraseña temporal.
 *  - Con cuenta → conserva su usuario y le genera otra contraseña temporal.
 *
 * Siempre: solo el hash en `users.password`, `debe_cambiar_contrasena` y la
 * contraseña en claro se devuelve UNA vez para copiarla (nunca se guarda ni
 * se registra en auditoría/log).
 */
class CredencialesService
{
    public function __construct(
        private readonly NombreUsuarioService $nombres,
        private readonly GeneradorPasswordService $generador,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Quién puede: crear cuentas (sin cuenta) o editar usuarios (con cuenta),
     * siempre dentro de su alcance y nunca sobre su propia cuenta.
     */
    public function puedeGenerar(User $actor, Colaborador $colaborador): bool
    {
        if ($actor->colaborador_id === $colaborador->id || ! $this->alcance->alcanzaColaborador($actor, $colaborador)) {
            return false;
        }

        $cuenta = User::withTrashed()->where('colaborador_id', $colaborador->id)->first();

        return $cuenta === null ? $actor->can('create', User::class) : $actor->can('restablecerPassword', $cuenta);
    }

    /**
     * @return array{usuario: string, contrasena: string, cuenta_nueva: bool, correo: string|null}
     */
    public function generar(Colaborador $colaborador, User $actor): array
    {
        if (in_array($colaborador->estatus, [EstadoUsuario::Inactivo, EstadoUsuario::Suspendido], true)) {
            throw ValidationException::withMessages(['credenciales' => 'Colaborador de baja o suspendido: no se le generan credenciales. Si reingresa, se habilitan con el reingreso.']);
        }

        $contrasena = $this->generador->generar();
        $existente = User::withTrashed()->where('colaborador_id', $colaborador->id)->first();

        if ($existente !== null) {
            DB::transaction(function () use ($existente, $colaborador, $contrasena): void {
                if ($existente->trashed()) {
                    $existente->restore();
                }

                if (trim((string) $existente->username) === '') {
                    $existente->username = $this->nombres->disponible($this->nombres->baseDesdeApellidos($colaborador->name, $colaborador->apellidos));
                }

                $existente->forceFill(['password' => Hash::make($contrasena), 'debe_cambiar_contrasena' => true])->save();
            });
            $this->auditoria->registrar('credenciales_generadas', $existente, $actor, ['colaborador_id' => $colaborador->id, 'usuario' => $existente->username, 'cuenta_nueva' => false]);

            return ['usuario' => $existente->username, 'contrasena' => $contrasena, 'cuenta_nueva' => false, 'correo' => $existente->email];
        }

        $correo = $this->correoLibre($colaborador->correo_personal);
        $cuenta = $this->nombres->crearConUsuario(
            $this->nombres->baseDesdeApellidos($colaborador->name, $colaborador->apellidos),
            function (string $username) use ($colaborador, $correo, $contrasena): User {
                $cuenta = User::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'username' => $username,
                    'name' => $colaborador->name,
                    'apellidos' => $colaborador->apellidos,
                    // Dato opcional: solo si existe y nadie más lo usa.
                    'email' => $correo,
                    'password' => Hash::make($contrasena),
                ]);
                $cuenta->forceFill(['debe_cambiar_contrasena' => true])->save();
                $cuenta->assignRole('colaborador');

                return $cuenta;
            },
        );
        $colaborador->setRelation('user', $cuenta);
        $this->auditoria->registrar('credenciales_generadas', $cuenta, $actor, ['colaborador_id' => $colaborador->id, 'usuario' => $cuenta->username, 'cuenta_nueva' => true]);

        return ['usuario' => $cuenta->username, 'contrasena' => $contrasena, 'cuenta_nueva' => true, 'correo' => $cuenta->email];
    }

    private function correoLibre(?string $correo): ?string
    {
        $correo = Str::lower(trim((string) $correo));

        if ($correo === '' || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return User::withTrashed()->whereRaw('LOWER(email) = ?', [$correo])->exists() ? null : $correo;
    }
}
