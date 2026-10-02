<?php

namespace App\Services\Mobile;

use App\Enums\EstadoUsuario;
use App\Models\User;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Configuracion\ConfiguracionSistemaService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\RhMobile\RhPendientesService;
use App\Services\Tareas\TareaService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Arma el contexto inicial que la app movil carga justo despues de
 * autenticarse (GET /api/v1/mobile/bootstrap): quien es, que puede hacer
 * (capabilities/features, calculadas SIEMPRE en el backend a partir de
 * roles/permisos de Spatie, nunca hardcodeadas en la app) y contadores para
 * badges. Nunca expone datos sensibles ni rutas del disco NAS. Ver seccion
 * 1 del encargo movil y docs/BACKEND_MOBILE_V5.md.
 */
class MobileBootstrapService
{
    /**
     * Roles con alcance de aprobacion sobre subordinados/sucursal (mismo
     * catalogo funcional que App\Services\AlcanceOrganizacionalService,
     * mas jefe_directo/supervisor que ahi cuentan aparte).
     *
     * @var array<int, string>
     */
    private const ROLES_MANAGER = [
        'jefe_directo', 'supervisor', 'gerente_sucursal', 'gerente', 'subgerente',
        'gerente_regional', 'coordinadora_regional', 'coordinadora',
    ];

    /**
     * Permisos (RolesYPermisosSeeder) que habilitan al menos un modulo de
     * operacion de "Gestion RH" en la app. Direccion y Juridico NO tienen
     * rh.pendientes.ver pero si capacidades reales (autorizar prestamos,
     * indicadores, documentos laborales, cierres...), asi que
     * capabilities.rh se calcula con cualquiera de ellos. La app sigue
     * mostrando cada modulo solo con su permiso exacto (user.permissions):
     * esto decide unicamente si la experiencia existe para la cuenta.
     *
     * @var list<string>
     */
    public const PERMISOS_EXPERIENCIA_RH = [
        'rh.pendientes.ver',
        'rh.mobile.dashboard.ver',
        'indicadores.ver',
        'headcount.ver',
        'organigrama.ver',
        'plantillas_documentales.ver',
        'documentos_laborales.ver',
        'contratos.ver',
        'evaluaciones.autorizar',
        'cierres.ver',
        'nomina.recibos.ver',
        'prestamos.ver',
        'prestamos.autorizar',
        'actas.ver',
    ];

    public function __construct(
        private readonly ExpedienteService $expediente,
        private readonly IncorporacionService $incorporacion,
        private readonly RhPendientesService $rhPendientes,
        private readonly TareaService $tareas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(User $usuario): array
    {
        $capabilities = $this->capabilities($usuario);

        return [
            'user' => $this->usuario($usuario),
            'capabilities' => $capabilities,
            'features' => $this->features($usuario, $capabilities),
            'counts' => $this->counts($usuario, $capabilities),
            // Mismo tema que GET /api/v1/app/theme (Configuración → Apariencia).
            'theme' => app(ConfiguracionSistemaService::class)->tema(),
            'ciclo_laboral' => $this->cicloLaboral($usuario),
            'pendientes' => $this->tareas->conteos($usuario),
            'server' => [
                'time' => now()->toIso8601String(),
                'timezone' => config('app.mobile_timezone', 'America/Mexico_City'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function usuario(User $usuario): array
    {
        $usuario->loadMissing(['colaborador.sucursalPrincipal.empresa', 'colaborador.departamento', 'colaborador.puesto']);
        $colaborador = $usuario->colaborador;

        return [
            'id' => $usuario->id,
            'name' => $usuario->name,
            'apellidos' => $usuario->apellidos,
            'email' => $usuario->email,
            'estatus' => $colaborador?->estatus->value,
            'numero_empleado' => $colaborador?->numero_empleado,
            'foto_url' => $colaborador?->foto_path !== null ? route('api.v1.colaborador.foto') : null,
            'empresa' => $this->entidad($colaborador?->sucursalPrincipal?->empresa),
            'sucursal' => $this->entidad($colaborador?->sucursalPrincipal),
            'departamento' => $this->entidad($colaborador?->departamento),
            'puesto' => $this->entidad($colaborador?->puesto),
            'roles' => $usuario->getRoleNames()->values(),
            'permissions' => $usuario->getAllPermissions()->pluck('name')->values(),
        ];
    }

    /**
     * @return array{id: int, nombre: string}|null
     */
    private function entidad(mixed $modelo): ?array
    {
        if ($modelo === null) {
            return null;
        }

        return ['id' => $modelo->id, 'nombre' => $modelo->nombre];
    }

    /**
     * @return array{employee: bool, rh: bool, manager: bool, director: bool}
     */
    private function capabilities(User $usuario): array
    {
        return [
            // Mi espacio solo existe si la cuenta tiene expediente de colaborador:
            // una cuenta administrativa sin colaborador entra directo a Gestión RH.
            'employee' => $usuario->colaborador_id !== null,
            'rh' => $usuario->canAny(self::PERMISOS_EXPERIENCIA_RH),
            'manager' => $usuario->hasAnyRole(self::ROLES_MANAGER),
            'director' => $usuario->hasRole('director_comercial'),
        ];
    }

    /**
     * Resumen del ciclo laboral propio (si la cuenta es de una persona) y
     * qué acciones del ciclo puede ejecutar la cuenta. La app muestra
     * botones con estas capacidades — nunca por el nombre del rol — y el
     * backend vuelve a autorizar cada acción.
     *
     * @return array<string, mixed>
     */
    private function cicloLaboral(User $usuario): array
    {
        $colaborador = $usuario->colaborador;
        // La persona nunca ve los nombres internos de sus etapas: solo sus
        // pendientes en lenguaje llano (detalle en GET /colaborador/mi-proceso).
        $propio = $colaborador !== null ? app(CicloLaboralService::class)->misPendientes($colaborador, $usuario) : null;

        return [
            'propio' => $propio === null ? null : [
                'por_hacer' => count(array_filter($propio['pendientes'], fn (array $p) => $p['tipo'] === 'accion')),
                'en_espera' => count(array_filter($propio['pendientes'], fn (array $p) => $p['tipo'] === 'espera')),
                'pendientes' => array_map(fn (array $p) => ['clave' => $p['clave'], 'titulo' => $p['titulo'], 'tipo' => $p['tipo']], $propio['pendientes']),
                'todo_listo' => $propio['todo_listo'],
            ],
            'acciones' => [
                'ver_candidatos' => $usuario->can('candidatos.ver'),
                'evaluar_candidatos' => $usuario->can('candidatos.evaluar'),
                'preautorizar' => $usuario->can(OrganizacionJerarquiaService::PERMISO_PREAUTORIZAR),
                'autorizar_rh' => $usuario->can(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH),
                'entregar_activos' => $usuario->can('onboarding.entregar_activos'),
                'gestionar_onboarding' => $usuario->can('onboarding.gestionar'),
                'capturar_evaluacion' => $usuario->can('evaluaciones.capturar'),
                'autorizar_evaluacion' => $usuario->can('evaluaciones.autorizar'),
                'solicitar_baja' => $usuario->can('cierres.solicitar'),
                'gestionar_cierres' => $usuario->can('cierres.gestionar'),
                'programar_pago' => $usuario->can('cierres.programar_pago'),
                'solicitar_reingreso' => $usuario->can('reingresos.solicitar'),
                'decidir_reingreso' => $usuario->can('reingresos.gestionar') && $usuario->can(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH),
                'operar_documentos_fisicos' => $usuario->can('documentos_laborales.operar_fisico'),
                'ver_tablero' => $usuario->can('dashboard.global.ver') || $usuario->can('dashboard.sucursal.ver'),
            ],
        ];
    }

    /**
     * @param  array{employee: bool, rh: bool, manager: bool, director: bool}  $capabilities
     * @return array<string, bool>
     */
    private function features(User $usuario, array $capabilities): array
    {
        return [
            'incorporacion' => $usuario->can('colaborador.incorporacion.ver'),
            'expedientes' => $usuario->can('expedientes.ver'),
            'solicitudes' => $usuario->can('solicitudes.ver'),
            'vacaciones' => $usuario->can('vacaciones.ver'),
            'notificaciones' => true,
            'push' => (bool) config('mobile.features.push'),
            'rh_mobile' => $capabilities['rh'] && (bool) config('mobile.features.rh_mobile'),
            'formatos' => (bool) config('mobile.features.formatos'),
            'maintenance' => (bool) config('mobile.maintenance'),
        ];
    }

    /**
     * @param  array{employee: bool, rh: bool, manager: bool, director: bool}  $capabilities
     * @return array<string, int>
     */
    private function counts(User $usuario, array $capabilities): array
    {
        $colaborador = $usuario->colaborador;

        if ($colaborador !== null && $colaborador->estatus === EstadoUsuario::EnIncorporacion) {
            $progreso = $this->incorporacion->progreso($this->incorporacion->tiposDocumento($colaborador), $this->expediente->documentosVigentes($colaborador));
            $documentosPendientes = $progreso['pendientes'] + $progreso['rechazados'];
        } else {
            $documentosPendientes = $colaborador !== null ? $this->expediente->documentosPendientesCount($colaborador) : 0;
        }

        // La bandeja RH unificada exige rh.pendientes.ver (Rh\PendienteController):
        // Direccion/Juridico entran a Gestion RH sin ella y sus contadores son 0.
        $rh = $usuario->can('rh.pendientes.ver') ? $this->rhPendientes->resumenConteos($usuario) : ['solicitudes' => 0, 'vacaciones' => 0, 'documentos' => 0, 'incorporaciones' => 0, 'total' => 0];

        return [
            'notifications' => $usuario->unreadNotifications()->count(),
            'tasks' => $this->tareasAbiertas($usuario),
            'documents_pending' => $documentosPendientes,
            'rh_pendientes' => $rh['total'],
            'rh_solicitudes' => $rh['solicitudes'],
            'rh_vacaciones' => $rh['vacaciones'],
            'rh_documentos' => $rh['documentos'],
            'rh_incorporaciones' => $rh['incorporaciones'],
        ];
    }

    /**
     * Mismo conteo que GET /tareas (meta.conteos.abiertas). Un fallo aqui no
     * debe tumbar el bootstrap completo: la app vuelve a consultar /tareas.
     */
    private function tareasAbiertas(User $usuario): int
    {
        try {
            return $this->tareas->conteos($usuario)['abiertas'];
        } catch (Throwable $e) {
            Log::warning('MobileBootstrapService: no se pudo contar tareas.', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
