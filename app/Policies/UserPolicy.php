<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Organigrama\AutoridadJerarquicaService;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AutoridadJerarquicaService $autoridad,
        private readonly OrganizacionJerarquiaService $jerarquia,
    ) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('usuarios.ver');
    }

    public function view(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.ver') && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('usuarios.crear');
    }

    public function update(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.editar') && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    /**
     * Revocar/restablecer acceso al sistema (bloquear login sin dar de baja
     * laboral) — la baja/reactivación de la relación laboral vive en
     * App\Http\Controllers\Rh\ExpedienteController (actúa sobre Colaborador,
     * funciona con o sin cuenta de acceso), no aquí.
     */
    public function revocarAcceso(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.desactivar')
            && ! $usuario->is($objetivo)
            && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    /**
     * RH y regionales rehabilitan el acceso (p. ej. una baja en trámite que
     * se suspendió al solicitarla) aunque no puedan revocar cuentas.
     */
    public function restablecerAcceso(User $usuario, User $objetivo): bool
    {
        return ($usuario->can('usuarios.desactivar') || $usuario->can('usuarios.restablecer_acceso'))
            && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    /**
     * Establecer una contraseña nueva desde el panel (pestaña "Usuario" del
     * expediente): nunca se puede leer la contraseña actual (se guarda con
     * hash), solo sobreescribirla. Permiso propio
     * (usuarios.restablecer_password); por defecto lo tiene quien tenía
     * usuarios.editar (config/permisos_modulos.php → herencia).
     */
    public function restablecerPassword(User $usuario, User $objetivo): Response
    {
        if (! $usuario->can('usuarios.ver') || ! $usuario->can('usuarios.restablecer_password') || $usuario->is($objetivo)) {
            return Response::deny('No tienes permiso para restablecer contraseñas. Pide a RH que lo haga.');
        }

        if (! $this->alcance->puedeVerUsuario($usuario, $objetivo)) {
            return Response::deny('Esta persona está fuera de tu alcance.');
        }

        // Administración de cuentas (RH, Sistemas, super admin: quien puede
        // revocar accesos): toda cuenta de su alcance.
        if ($usuario->can('usuarios.desactivar')) {
            return Response::allow();
        }

        // Gerencias (2026-11-04, antisuplantación): solo a SU gente —quien
        // está bajo su mando en la línea comercial o en su cadena de mando—,
        // nunca a pares, superiores, RH ni a otra cuenta que administre a
        // otras personas. Siempre auditado y con aviso al titular.
        $colaborador = $objetivo->colaborador;
        $actor = $usuario->colaborador;

        $bajoSuMando = $colaborador !== null && (
            $this->autoridad->tieneAutoridadSobre($usuario, $colaborador)
            || ($actor !== null && $this->jerarquia->estaEnCadenaDeMando($actor, $colaborador))
        );

        if (! $bajoSuMando) {
            return Response::deny('Solo puedes restablecer la contraseña de colaboradores bajo tu mando. Para cualquier otra cuenta, pide a RH.');
        }

        if ($objetivo->can('usuarios.restablecer_password') || $objetivo->can('usuarios.desactivar')) {
            return Response::deny('Esa cuenta administra a otras personas: solo RH puede restablecer su contraseña.');
        }

        return Response::allow();
    }

    /**
     * Cambiar los roles de una cuenta (alta o edición): permiso propio,
     * separado de editar usuario/correo.
     */
    public function asignarRoles(User $usuario): bool
    {
        return $usuario->can('usuarios.ver') && $usuario->can('usuarios.asignar_roles');
    }

    /**
     * PURGA INTEGRAL de la persona (cuenta + expediente + todo lo suyo),
     * exclusiva de super_admin y solo para registros erróneos/de prueba —
     * ver App\Services\Administracion\PurgaPersonaService. No es «Revocar
     * acceso» (que conserva todo). Las salvaguardas finas (no a sí mismo, no
     * al último super_admin, bloqueos por datos de terceros) se revalidan en
     * el servicio.
     */
    public function purgarDefinitivamente(User $usuario, User $objetivo): bool
    {
        return $usuario->hasRole('super_admin') && ! $usuario->is($objetivo);
    }
}
