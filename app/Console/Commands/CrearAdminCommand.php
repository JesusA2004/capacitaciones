<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Autenticacion\NombreUsuarioService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Crea (o reactiva) la cuenta super_admin de ARRANQUE con la que se entra a
 * un sistema recién migrado. Es una cuenta puramente OPERATIVA: nace SIN
 * Colaborador enlazado (`colaborador_id = null`), así que no aparece en
 * Expedientes, ni en la plantilla/headcount, ni en rotación, ni en el
 * directorio — ver docs/ROLES_Y_NAVEGACION.md. El login es por USERNAME
 * (p. ej. «Jesus Arizmendi»), no por correo (ver docs/AUTENTICACION.md).
 *
 * Que una cuenta operativa sin colaborador pueda iniciar sesión lo permite
 * App\Services\Autenticacion\AutenticacionService::puedeIniciarSesion()
 * (cuenta no bloqueada + con dashboard.*.ver). Un colaborador dado de baja
 * sigue bloqueado.
 */
class CrearAdminCommand extends Command
{
    protected $signature = 'people:crear-admin
        {--nombre= : Nombre de pila (p. ej. «Jesus»)}
        {--apellidos= : Apellidos (p. ej. «Arizmendi»)}
        {--correo= : Correo de contacto/recuperación (opcional)}
        {--password= : Contraseña (mínimo 8 caracteres)}
        {--rol=super_admin : Rol operativo a asignar}
        {--force : No pedir confirmación aunque la cuenta ya exista}';

    protected $description = 'Crea la cuenta super_admin de arranque (operativa, sin colaborador ni expediente).';

    public function handle(): int
    {
        $rol = (string) $this->option('rol');

        if (! Role::query()->where('name', $rol)->where('guard_name', 'web')->exists()) {
            $this->components->error("El rol «{$rol}» no existe todavía. Corre primero: php artisan people:bootstrap-produccion (o php artisan people:sincronizar-permisos)");

            return self::FAILURE;
        }

        $nombre = trim((string) ($this->option('nombre') ?: $this->ask('Nombre de pila')));
        $apellidos = trim((string) ($this->option('apellidos') ?: $this->ask('Apellidos')));
        $correo = trim((string) ($this->option('correo') ?: ''));
        $password = (string) ($this->option('password') ?: $this->secret('Contraseña (mínimo 8 caracteres)'));

        $validador = Validator::make(
            ['nombre' => $nombre, 'apellidos' => $apellidos, 'correo' => $correo ?: null, 'password' => $password],
            [
                'nombre' => ['required', 'string', 'max:255'],
                'apellidos' => ['required', 'string', 'max:255'],
                'correo' => ['nullable', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ],
            [
                'nombre.required' => 'El nombre es obligatorio.',
                'apellidos.required' => 'Los apellidos son obligatorios.',
                'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
                'correo.email' => 'El correo no tiene un formato válido.',
            ],
        );

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        // ¿Ya existe la cuenta? (El username se deriva del nombre y puede
        // repetirse con sufijo; el correo es el mejor ancla.) Sin correo, o
        // si ninguna cuenta lo tiene, se busca una cuenta OPERATIVA (sin
        // colaborador) con el mismo nombre y apellidos — sin distinguir
        // mayúsculas ni acentos — para no duplicar al correr el comando dos
        // veces.
        $existente = $correo !== ''
            ? User::query()->where('email', $correo)->first()
            : null;
        $ancla = 'ese correo';

        if ($existente === null) {
            $existente = $this->cuentaOperativaPorNombre($nombre, $apellidos);
            $ancla = 'ese nombre y apellidos';
        }

        if ($existente !== null && ! $this->option('force')
            && ! $this->confirm("Ya existe la cuenta «{$existente->username}» con {$ancla}. ¿Actualizar su contraseña y asegurar el rol «{$rol}»?")) {
            $this->components->warn('Operación cancelada.');

            return self::FAILURE;
        }

        try {
            $usuario = DB::transaction(function () use ($existente, $nombre, $apellidos, $correo, $password, $rol): User {
                $cuenta = $existente ?? new User;

                $cuenta->colaborador_id = null; // Cuenta operativa pura: nunca un colaborador.
                $cuenta->name = $nombre;
                $cuenta->apellidos = $apellidos;
                $cuenta->email = $correo !== '' ? $correo : $cuenta->email;
                $cuenta->email_verified_at = $cuenta->email_verified_at ?? Carbon::now();
                $cuenta->password = Hash::make($password);
                $cuenta->acceso_bloqueado_en = null;
                $cuenta->acceso_bloqueado_motivo = null;
                $cuenta->acceso_bloqueado_por = null;

                if (trim((string) $cuenta->username) === '' && $existente === null) {
                    $cuenta->username = app(NombreUsuarioService::class)->paraCuenta(
                        tap($cuenta, fn (User $u) => $u->colaborador_id = null),
                    );
                }

                $cuenta->save();
                $cuenta->syncRoles([$rol]);

                return $cuenta;
            });
        } catch (Throwable $e) {
            $this->components->error('No se pudo crear la cuenta: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info($existente !== null ? 'Cuenta de administrador actualizada.' : 'Cuenta de administrador creada.');
        $this->components->twoColumnDetail('<fg=gray>Usuario (login)</>', "<options=bold>{$usuario->username}</>");
        $this->components->twoColumnDetail('<fg=gray>Nombre</>', "{$usuario->name} {$usuario->apellidos}");
        $this->components->twoColumnDetail('<fg=gray>Correo</>', $usuario->email ?: '—');
        $this->components->twoColumnDetail('<fg=gray>Rol</>', $rol);
        $this->components->twoColumnDetail('<fg=gray>Colaborador</>', 'ninguno (no aparece en expedientes, plantilla ni rotación)');
        $this->newLine();
        $this->components->warn('Entra con el USUARIO mostrado arriba (no el correo) y tu contraseña. Cambia la contraseña tras el primer acceso.');

        return self::SUCCESS;
    }

    /**
     * Cuenta operativa (colaborador_id null) con el mismo nombre completo,
     * comparado sin mayúsculas, acentos ni espacios repetidos. Si hubiera
     * varias, se reutiliza la más antigua y se avisa de las demás (nunca se
     * borra ninguna).
     */
    private function cuentaOperativaPorNombre(string $nombre, string $apellidos): ?User
    {
        $buscado = $this->normalizar($nombre.' '.$apellidos);

        $coincidencias = User::query()
            ->whereNull('colaborador_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u): bool => $this->normalizar(sprintf('%s %s', $u->name, $u->apellidos)) === $buscado)
            ->values();

        if ($coincidencias->count() > 1) {
            $this->components->warn(sprintf(
                'Hay %d cuentas operativas con ese nombre (%s): se actualiza la más antigua; revisa las demás a mano.',
                $coincidencias->count(),
                $coincidencias->map(fn (User $u): string => (string) $u->username)->implode(', '),
            ));
        }

        return $coincidencias->first();
    }

    private function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->squish()->toString();
    }
}
