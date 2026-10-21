<?php

namespace App\Policies;

use App\Enums\AlcanceDatos;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Permisos\AlcanceModuloService;
use App\Services\Solicitudes\AprobacionJerarquicaService;

/**
 * No usa los verbos CRUD por defecto de Laravel: cada método representa una
 * acción real del flujo de revisión (igual criterio que
 * EmployeeDocumentPolicy/SolicitudVacacionesPolicy). super_admin aprueba o
 * rechaza incluso su propia solicitud, ver ConSuperAdminOperativo.
 *
 * Única policy de la bandeja para web (Rh\SolicitudController) Y API móvil
 * (Api\V1\Rh\SolicitudController). Cada acción acepta las dos familias de
 * permisos que ya existen en Roles y permisos — `solicitudes.*` (web) y
 * `rh.solicitudes.*` (app) — para no quitarle a ningún rol el acceso que hoy
 * tiene en uno u otro canal. Las reglas de negocio que no dependen de quién
 * mira (baja en Cierre laboral, no aprobar lo propio, vistos buenos) viven
 * en SolicitudesService::cambiarEstado().
 */
class SolicitudInternaPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AlcanceModuloService $alcanceModulo,
    ) {}

    /**
     * Gate del listado de revisión de RH/gerencia (Rh\SolicitudController,
     * GET /api/v1/rh/solicitudes), no del listado propio del colaborador.
     */
    public function viewAny(User $usuario): bool
    {
        // Quien revisa/aprueba, o quien solo CONSULTA solicitudes de otros
        // (p. ej. Sistemas con lectura global): ver ≠ aprobar.
        return $usuario->can('solicitudes.revisar')
            || $usuario->can('solicitudes.aprobar')
            || $usuario->can('rh.solicitudes.ver')
            || ($usuario->can('solicitudes.ver') && $this->alcanceModulo->nivel($usuario, 'solicitudes') !== AlcanceDatos::Propios);
    }

    public function view(User $usuario, SolicitudInterna $solicitud): bool
    {
        if ($usuario->is($solicitud->usuario)) {
            return true;
        }

        if ($usuario->can('solicitudes.ver') && $this->alcanceModulo->alcanzaColaborador($usuario, $solicitud->personaSolicitante(), 'solicitudes')) {
            return true;
        }

        // Familia de la app (rh.solicitudes.detalle): alcance organizacional,
        // mismo criterio que tenía la API (p. ej. director_comercial).
        return $usuario->can('rh.solicitudes.detalle') && $this->alcanzaAutor($usuario, $solicitud);
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('solicitudes.crear');
    }

    public function cancelar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return $usuario->is($solicitud->usuario) && $solicitud->estado->puedeCancelarse();
    }

    /**
     * Responder lo que RH pidió (requiere información): solo el dueño
     * (CorreccionesService::responder() vuelve a validar el estado).
     */
    public function responderCorreccion(User $usuario, SolicitudInterna $solicitud): bool
    {
        return $solicitud->esDe($usuario);
    }

    /**
     * Marcar en revisión / pedir corrección: acción de RH/gerencia, nunca
     * sobre la propia solicitud.
     */
    public function revisar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return ($usuario->can('solicitudes.revisar') || $usuario->can('rh.solicitudes.correccion'))
            && $this->puedeActuarSobre($usuario, $solicitud);
    }

    public function aprobar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return ($usuario->can('solicitudes.aprobar') || $usuario->can('rh.solicitudes.aprobar'))
            && $this->puedeActuarSobre($usuario, $solicitud)
            // Gerente/regional dan visto bueno; la autorización final es de RH.
            && ! app(AprobacionJerarquicaService::class)->esAprobadorDe($usuario, $solicitud);
    }

    public function rechazar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return ($usuario->can('solicitudes.rechazar') || $usuario->can('rh.solicitudes.rechazar'))
            && $this->puedeActuarSobre($usuario, $solicitud);
    }

    /**
     * Crear una solicitud de baja de colaborador: permiso dedicado, no
     * `solicitudes.crear` genérico — un gerente puede solicitar la baja de
     * su equipo sin necesariamente poder crear otros tipos de solicitud
     * sobre sí mismo. (Aprobarla ya no es una acción de la bandeja: la baja
     * se ejecuta en el Cierre laboral, ver CierreLaboralPolicy.)
     */
    public function crearBaja(User $usuario, Colaborador $colaboradorObjetivo): bool
    {
        return $usuario->can('solicitudes.bajas.crear') && $this->alcance->puedeVerExpediente($usuario, $colaboradorObjetivo);
    }

    /**
     * Nunca sobre la propia solicitud y siempre dentro del alcance.
     */
    private function puedeActuarSobre(User $usuario, SolicitudInterna $solicitud): bool
    {
        return ! $solicitud->esDe($usuario) && $this->alcanzaAutor($usuario, $solicitud);
    }

    private function alcanzaAutor(User $usuario, SolicitudInterna $solicitud): bool
    {
        $autor = $solicitud->usuario;

        return $autor !== null && $this->alcance->puedeVerUsuario($usuario, $autor);
    }
}
