<?php

namespace App\Policies;

use App\Enums\AlcanceDatos;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Navigation\NavigationService;
use App\Services\Organigrama\AutoridadJerarquicaService;
use App\Services\Permisos\AlcanceModuloService;
use App\Services\Solicitudes\AprobacionJerarquicaService;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

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

    public const MENSAJE_SIN_COLABORADOR = 'Tu cuenta no está vinculada a un expediente de colaborador, por eso no puedes crear solicitudes propias. Pide a Recursos Humanos que vincule tu cuenta.';

    public const MENSAJE_SIN_MODO_COLABORADOR = 'Tu cuenta no tiene acceso a «Mi espacio» (colaborador activo), por eso no puedes crear solicitudes propias. Consulta con Recursos Humanos.';

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

        // Quien la cadena de mando nombra como aprobador debe poder abrirla, aunque
        // su sucursal sea otra: si no, el trámite se queda esperándolo sin salida.
        if (app(AprobacionJerarquicaService::class)->esAprobadorDe($usuario, $solicitud)) {
            return true;
        }

        // Baja de colaborador: el alcance es el de quien SE VA, no el de
        // quien la registró (un jefe de otra sucursal puede registrarla).
        $persona = $solicitud->colaboradorDeBaja() ?? $solicitud->personaSolicitante();

        if ($usuario->can('solicitudes.ver') && $this->alcanceModulo->alcanzaColaborador($usuario, $persona, 'solicitudes')) {
            return true;
        }

        // Familia de la app (rh.solicitudes.detalle): alcance organizacional,
        // mismo criterio que tenía la API (p. ej. director_comercial).
        return $usuario->can('rh.solicitudes.detalle') && $this->alcanzaAutor($usuario, $solicitud);
    }

    /**
     * Solicitudes PROPIAS de autoservicio (vacaciones, permisos, préstamo,
     * completar mis datos): toda persona con colaborador vinculado que entra
     * a «Mi espacio» (Gate `modo-colaborador`) puede pedirlas, sin importar su
     * rol operativo — un gerente o un regional también es trabajador con
     * derecho a vacaciones. `solicitudes.crear` se sigue aceptando (roles que
     * ya lo tenían). La baja de colaborador NO pasa por aquí: tiene su propio
     * permiso (`solicitudes.bajas.crear`, crearBaja()).
     */
    public function create(User $usuario): bool|Response
    {
        if ($usuario->colaborador_id === null) {
            return Response::deny(self::MENSAJE_SIN_COLABORADOR);
        }

        if ($usuario->can('solicitudes.crear') || Gate::forUser($usuario)->allows(NavigationService::GATE_MODO_COLABORADOR)) {
            return true;
        }

        return Response::deny(self::MENSAJE_SIN_MODO_COLABORADOR);
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
    public function revisar(User $usuario, SolicitudInterna $solicitud): bool|Response
    {
        if ((! $usuario->can('solicitudes.revisar') && ! $usuario->can('rh.solicitudes.correccion')) || ! $this->puedeActuarSobre($usuario, $solicitud)) {
            return false;
        }

        return $this->respetaLoDecidido($usuario, $solicitud);
    }

    /**
     * Pedir corrección al colaborador: acción propia
     * (solicitudes.requerir_correccion), separada de «marcar en revisión».
     */
    public function requerirCorreccion(User $usuario, SolicitudInterna $solicitud): bool|Response
    {
        if (! $usuario->can('solicitudes.requerir_correccion') || ! $this->puedeActuarSobre($usuario, $solicitud)) {
            return false;
        }

        return $this->respetaLoDecidido($usuario, $solicitud);
    }

    /**
     * Descargar el formato oficial de permiso: el dueño siempre; los demás,
     * con solicitudes.descargar_formato y viendo la solicitud.
     */
    public function descargarFormato(User $usuario, SolicitudInterna $solicitud): bool
    {
        if ($usuario->is($solicitud->usuario)) {
            return true;
        }

        return $usuario->can('solicitudes.descargar_formato') && $this->view($usuario, $solicitud);
    }

    /** Exportar la bandeja (Excel / PDF): quien entra a la bandeja y tiene el permiso. */
    public function exportar(User $usuario): bool
    {
        return $usuario->can('solicitudes.exportar') && $this->viewAny($usuario);
    }

    public function aprobar(User $usuario, SolicitudInterna $solicitud): bool|Response
    {
        if ((! $usuario->can('solicitudes.aprobar') && ! $usuario->can('rh.solicitudes.aprobar'))
            || ! $this->puedeActuarSobre($usuario, $solicitud)
            // Gerente/regional dan visto bueno; la autorización final es de RH.
            || app(AprobacionJerarquicaService::class)->esAprobadorDe($usuario, $solicitud)) {
            return false;
        }

        return $this->respetaLoDecidido($usuario, $solicitud);
    }

    public function rechazar(User $usuario, SolicitudInterna $solicitud): bool|Response
    {
        if ((! $usuario->can('solicitudes.rechazar') && ! $usuario->can('rh.solicitudes.rechazar')) || ! $this->puedeActuarSobre($usuario, $solicitud)) {
            return false;
        }

        return $this->respetaLoDecidido($usuario, $solicitud);
    }

    /**
     * Eliminar una solicitud registrada por error o como prueba (cualquier
     * tipo y estado, sin avisar al colaborador): acción propia
     * (`solicitudes.eliminar`), dentro del alcance del módulo. Sí aplica a
     * la solicitud propia: quien limpia pruebas suele haberlas creado. Lo
     * que no se puede deshacer lo bloquea EliminacionSolicitudService.
     */
    public function eliminar(User $usuario, SolicitudInterna $solicitud): bool
    {
        if (! $usuario->can('solicitudes.eliminar')) {
            return false;
        }

        if ($solicitud->esDe($usuario)) {
            return true;
        }

        return $this->alcanceModulo->alcanzaColaborador($usuario, $solicitud->colaboradorDeBaja() ?? $solicitud->personaSolicitante(), 'solicitudes');
    }

    /**
     * Crear una solicitud de baja de colaborador: permiso dedicado, no
     * `solicitudes.crear` genérico ni el autoservicio de create(): pedir la
     * baja de alguien de su equipo es una acción operativa. (Aprobarla ya no es una acción de la bandeja: la baja
     * se ejecuta en el Cierre laboral, ver CierreLaboralPolicy.)
     */
    public function crearBaja(User $usuario, Colaborador $colaboradorObjetivo): bool
    {
        return $usuario->can('solicitudes.bajas.crear') && $this->alcance->puedeVerExpediente($usuario, $colaboradorObjetivo);
    }

    /**
     * Nunca se deshace lo decidido por un puesto superior de la línea comercial.
     */
    private function respetaLoDecidido(User $usuario, SolicitudInterna $solicitud): bool|Response
    {
        $autoridad = app(AutoridadJerarquicaService::class);

        foreach (app(AprobacionJerarquicaService::class)->autoresDeDecisiones($solicitud) as $autor) {
            if (! $autoridad->puedeModificarLoHechoPor($usuario, $autor)) {
                return Response::deny(AutoridadJerarquicaService::MENSAJE_PUESTO_SUPERIOR);
            }
        }

        return true;
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
        $deBaja = $solicitud->colaboradorDeBaja();

        if ($deBaja !== null) {
            return $this->alcance->alcanzaColaborador($usuario, $deBaja);
        }

        $autor = $solicitud->usuario;

        return $autor !== null && $this->alcance->puedeVerUsuario($usuario, $autor);
    }
}
