<?php

namespace App\Services\Autenticacion;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Notifications\CredencialesActualizadasNotification;
use App\Services\Administracion\GeneradorPasswordService;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

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
    /** Lo que ve RH en la vista previa del correo en lugar de la contraseña. */
    public const CONTRASENA_ENMASCARADA = '••••••••';

    public function __construct(
        private readonly NombreUsuarioService $nombres,
        private readonly GeneradorPasswordService $generador,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
        private readonly NotificacionEventosService $avisos,
    ) {}

    /**
     * Quién puede: crear cuentas (sin cuenta) o editar usuarios (con cuenta),
     * siempre dentro de su alcance y nunca sobre su propia cuenta.
     */
    public function puedeGenerar(User $actor, Colaborador $colaborador): bool
    {
        try {
            $this->autorizarGeneracion($actor, $colaborador);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /**
     * Igual que puedeGenerar(), pero con el motivo en español (403 que la app
     * y la web muestran tal cual).
     *
     * @throws AuthorizationException
     */
    public function autorizarGeneracion(User $actor, Colaborador $colaborador): void
    {
        if ($actor->colaborador_id === $colaborador->id) {
            throw new AuthorizationException('No puedes generar tus propias credenciales: pídeselo a RH.');
        }

        if (! $this->alcance->alcanzaColaborador($actor, $colaborador)) {
            throw new AuthorizationException('Esta persona está fuera de tu alcance.');
        }

        $cuenta = User::withTrashed()->where('colaborador_id', $colaborador->id)->first();
        $gate = Gate::forUser($actor);

        if ($cuenta === null) {
            if ($gate->denies('create', User::class)) {
                throw new AuthorizationException('No tienes permiso para crear cuentas. Pide a RH que genere sus credenciales.');
            }

            return;
        }

        $gate->inspect('restablecerPassword', $cuenta)->authorize();
    }

    /**
     * @return array{usuario: string, contrasena: string, cuenta_nueva: bool, correo: string|null}
     */
    public function generar(Colaborador $colaborador, User $actor, ?string $contrasenaManual = null): array
    {
        if (in_array($colaborador->estatus, [EstadoUsuario::Inactivo, EstadoUsuario::Suspendido], true)) {
            throw ValidationException::withMessages(['credenciales' => 'Colaborador de baja o suspendido: no se le generan credenciales. Si reingresa, se habilitan con el reingreso.']);
        }

        // Manual (RH la escribe) o generada; la manual cumple la misma
        // política de contraseñas que cualquier cambio de contraseña.
        $contrasena = $contrasenaManual !== null && $contrasenaManual !== ''
            ? $contrasenaManual
            : $this->generador->generar();
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
            $this->avisarAlTitular($existente, $actor);

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

    /**
     * «Establecer contraseña» desde Administración → Usuarios: otra persona
     * la define, así que es temporal (se pide cambiarla al entrar), queda en
     * la bitácora (sin la contraseña) y el titular recibe un aviso.
     * Devuelve la contraseña en claro UNA vez para copiarla.
     */
    public function restablecerContrasena(User $cuenta, User $actor, ?string $contrasenaManual = null): string
    {
        $contrasena = $contrasenaManual !== null && $contrasenaManual !== '' ? $contrasenaManual : $this->generador->generar();

        $cuenta->forceFill(['password' => Hash::make($contrasena), 'debe_cambiar_contrasena' => true])->save();
        $this->auditoria->registrar('contrasena_restablecida', $cuenta, $actor, ['colaborador_id' => $cuenta->colaborador_id, 'usuario' => $cuenta->username, 'manual' => $contrasenaManual !== null && $contrasenaManual !== '']);
        $this->avisarAlTitular($cuenta, $actor);

        return $contrasena;
    }

    /**
     * Aviso al titular: alguien más le cambió la contraseña. Si no lo pidió,
     * sabe que debe avisar a RH (evita la suplantación silenciosa). Un fallo
     * al avisar nunca revierte el cambio.
     */
    private function avisarAlTitular(User $cuenta, User $actor): void
    {
        if ($cuenta->is($actor)) {
            return;
        }

        try {
            $this->avisos->avisarResultado($cuenta, new Aviso(
                'cuenta_contrasena_restablecida',
                'cuenta',
                'Tu contraseña fue restablecida',
                sprintf('%s te asignó una contraseña temporal; al entrar se te pedirá cambiarla. Si no lo pediste, avisa a RH de inmediato.', $actor->nombreCompleto()),
                $cuenta,
                'contrasena_restablecida',
                'alta',
                ocurrencia: sprintf('contrasena-%s', now()->format('YmdHisu')),
                directo: true,
            ));
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar al titular del cambio de contraseña.', ['usuario_id' => $cuenta->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Reglas de una contraseña escrita a mano por RH (misma política que
     * Password::defaults() del resto del sistema).
     *
     * @return array<string, mixed>
     */
    public static function reglasManual(): array
    {
        return ['password' => ['nullable', 'string', Password::defaults()]];
    }

    /**
     * «Enviar accesos por correo»: usuario, contraseña temporal, URL de
     * PEOPLE e indicación de cambiarla. Solo la contraseña VIGENTE (la que
     * se acaba de mostrar), nunca un texto arbitrario. Un fallo de envío no
     * revierte la contraseña, que ya quedó actualizada.
     */
    public function enviarPorCorreo(Colaborador $colaborador, User $actor, string $contrasena): bool
    {
        $cuenta = User::query()->where('colaborador_id', $colaborador->id)->first();

        if ($cuenta === null || ! Hash::check($contrasena, (string) $cuenta->password)) {
            throw ValidationException::withMessages(['password' => 'Esa no es la contraseña vigente de la cuenta: genera una nueva.']);
        }

        if (blank($cuenta->email)) {
            throw ValidationException::withMessages(['password' => 'La cuenta no tiene correo registrado: entrega los accesos en privado.']);
        }

        try {
            $cuenta->notify(new CredencialesActualizadasNotification($contrasena));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar el correo de accesos.', ['usuario_id' => $cuenta->id, 'error' => $e->getMessage()]);

            return false;
        }

        $this->auditoria->registrar('credenciales_enviadas_por_correo', $cuenta, $actor, ['colaborador_id' => $colaborador->id]);

        return true;
    }

    /**
     * Vista previa del correo de accesos ANTES de «Enviar por correo»: el
     * mismo Notification renderizado a HTML, sin enviar nada ni auditar. La
     * contraseña NUNCA viaja ni se pinta: va enmascarada.
     *
     * @return array{asunto: string, destinatario: string, html: string}
     *
     * @throws ValidationException Sin cuenta o sin correo registrado.
     */
    public function vistaPreviaCorreo(Colaborador $colaborador): array
    {
        $cuenta = User::query()->where('colaborador_id', $colaborador->id)->first();

        if ($cuenta === null) {
            throw ValidationException::withMessages(['password' => 'El colaborador todavía no tiene cuenta: genera sus credenciales primero.']);
        }

        if (blank($cuenta->email)) {
            throw ValidationException::withMessages(['password' => 'La cuenta no tiene correo registrado: entrega los accesos en privado.']);
        }

        $mensaje = (new CredencialesActualizadasNotification(self::CONTRASENA_ENMASCARADA))->toMail($cuenta);

        return [
            'asunto' => (string) $mensaje->subject,
            'destinatario' => (string) $cuenta->email,
            'html' => (string) $mensaje->render(),
        ];
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
