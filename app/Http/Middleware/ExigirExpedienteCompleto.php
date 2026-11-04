<?php

namespace App\Http\Middleware;

use App\Enums\TipoSolicitudInterna;
use App\Services\Expedientes\ExpedienteIncompletoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Modo completar expediente» (ExpedienteIncompletoService): al colaborador
 * con datos personales o documentos requeridos pendientes se le bloquea el
 * AUTOSERVICIO (solicitudes, vacaciones, préstamos, recibos, contratos,
 * jerarquía…) con 423 «expediente_incompleto» y los motivos exactos.
 *
 * Alcance: solo rutas de autoservicio (AUTOSERVICIO). Lo operativo —
 * aprobar, equipo, evaluaciones, tareas, agenda, rh.* — NUNCA se bloquea: un
 * gerente con su propio expediente incompleto sigue atendiendo a su equipo.
 * Dentro del autoservicio, PERMITIDAS es todo lo que la guía necesita para
 * completarlo (datos, foto, documentos, inducción, firmas, perfil…).
 *
 * Compatibilidad: solo se exige a la app que declara entender este bloqueo
 * (cabecera X-People-Expediente). Las APKs ya instaladas no la mandan.
 * Se apaga con config('features.bloqueo_expediente_incompleto') = false.
 */
class ExigirExpedienteCompleto
{
    public const CABECERA_CLIENTE = 'X-People-Expediente';

    public const CODIGO = 'expediente_incompleto';

    /**
     * Rutas de autoservicio del colaborador a las que aplica el bloqueo.
     * Fuente única: también la usa ExigirDocumentosPendientes para no
     * bloquear la operación (rh.*, equipo, tareas…) de quien tiene Gestión RH.
     */
    public const AUTOSERVICIO = [
        'api.v1.colaborador.*',
        'api.v1.solicitudes.*',
        'api.v1.vacaciones.*',
    ];

    /** Lo que la guía «Tu siguiente paso» necesita para completar el expediente. */
    private const PERMITIDAS = [
        'api.v1.colaborador.perfil',
        'api.v1.colaborador.foto',
        'api.v1.colaborador.foto.*',
        'api.v1.colaborador.dashboard',
        'api.v1.colaborador.notificaciones',
        'api.v1.colaborador.guia',
        'api.v1.colaborador.mi-proceso',
        'api.v1.colaborador.incorporacion.*',
        'api.v1.colaborador.cumpleanos.*',
        'api.v1.colaborador.onboarding.*',
        'api.v1.colaborador.induccion.*',
        'api.v1.colaborador.descriptivo-puesto.*',
        'api.v1.colaborador.alta',
        'api.v1.colaborador.expediente',
        'api.v1.colaborador.documentos-pendientes',
        'api.v1.colaborador.documentos-laborales.*',
        'api.v1.colaborador.datos-faltantes',
        'api.v1.colaborador.registro.*',
        'api.v1.colaborador.carta-compromiso.*',
        // Ver una solicitud propia, responder correcciones de RH y su configuración.
        'api.v1.solicitudes.configuracion',
        'api.v1.solicitudes.show',
        'api.v1.solicitudes.responder',
        'api.v1.solicitudes.adjuntos',
    ];

    /**
     * Solicitudes que SÍ se pueden crear en modo completar expediente: las
     * que completan el propio expediente y la baja (acción operativa).
     */
    private const TIPOS_SOLICITUD_PERMITIDOS = [
        TipoSolicitudInterna::ActualizacionDatos,
        TipoSolicitudInterna::ActualizacionBancaria,
        TipoSolicitudInterna::ReposicionDocumental,
        TipoSolicitudInterna::BajaColaborador,
    ];

    public function __construct(private readonly ExpedienteIncompletoService $expediente) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null
            || ! $request->hasHeader(self::CABECERA_CLIENTE)
            || ! $this->expediente->habilitado()
            || ! $request->routeIs(...self::AUTOSERVICIO)
            || $request->routeIs(...self::PERMITIDAS)
            || $this->solicitudPermitida($request)) {
            return $next($request);
        }

        $estado = $this->expediente->estado($usuario);

        if (! $estado['bloqueado']) {
            return $next($request);
        }

        return response()->json([
            'message' => $estado['mensaje'],
            'code' => self::CODIGO,
            'codigo' => self::CODIGO,
            'expediente' => $estado,
        ], 423);
    }

    private function solicitudPermitida(Request $request): bool
    {
        if (! $request->routeIs('api.v1.solicitudes.store')) {
            return false;
        }

        $tipo = TipoSolicitudInterna::tryFrom((string) $request->input('tipo', ''));

        return $tipo !== null && in_array($tipo, self::TIPOS_SOLICITUD_PERMITIDOS, true);
    }
}
