<?php

namespace App\Services\Reclutamiento;

use App\Models\CandidatoEvidencia;
use App\Models\CandidatoPsicometrica;
use App\Models\CandidatoReferencia;
use App\Models\CandidatoSocioeconomico;
use App\Models\User;
use App\Services\Permisos\PermisoAccionService;

/**
 * Permisos por PASO y por ACCIÓN del recorrido del candidato (2026-11-18):
 * en cada paso se separa Ver, Cargar (capturar / subir evidencia) y Revisar o
 * autorizar (decidir viable / no viable). Los nombres viven en
 * config/permisos_modulos.php → modulos.candidatos; las transiciones las
 * valida CandidatoWorkflowService, la visibilidad de cada paso este service.
 *
 * Ver un paso = su permiso «consultar_*» O cualquier acción de ese paso: quien
 * carga o revisa siempre ve lo que carga o revisa.
 */
class PermisosPasoCandidatoService
{
    public const PASO_ENTREVISTA = 'entrevista';

    public const PASO_PSICOMETRICAS = 'psicometricas';

    public const PASO_SOCIOECONOMICO = 'socioeconomico';

    public const PASO_REFERENCIAS = 'referencias';

    public const PERMISO_CONSULTAR_ENTREVISTA = 'candidatos.consultar_entrevista';

    public const PERMISO_CONSULTAR_PSICOMETRICAS = 'candidatos.consultar_psicometricas';

    public const PERMISO_CONSULTAR_SOCIOECONOMICO = 'candidatos.consultar_socioeconomico';

    public const PERMISO_CONSULTAR_REFERENCIAS = 'candidatos.consultar_referencias';

    /**
     * Paso => [permiso para ver, ...acciones del paso que también lo dejan ver].
     *
     * @var array<string, list<string>>
     */
    private const PERMISOS_DEL_PASO = [
        self::PASO_ENTREVISTA => [
            self::PERMISO_CONSULTAR_ENTREVISTA,
            CandidatoWorkflowService::PERMISO_ENTREVISTA,
        ],
        self::PASO_PSICOMETRICAS => [
            self::PERMISO_CONSULTAR_PSICOMETRICAS,
            CandidatoWorkflowService::PERMISO_ENVIAR_PSICOMETRICAS,
            CandidatoWorkflowService::PERMISO_PSICOMETRICAS,
            CandidatoWorkflowService::PERMISO_REVISAR_PSICOMETRICAS,
        ],
        self::PASO_SOCIOECONOMICO => [
            self::PERMISO_CONSULTAR_SOCIOECONOMICO,
            CandidatoWorkflowService::PERMISO_SOCIOECONOMICO,
            CandidatoWorkflowService::PERMISO_REVISAR_SOCIOECONOMICO,
        ],
        self::PASO_REFERENCIAS => [
            self::PERMISO_CONSULTAR_REFERENCIAS,
            CandidatoWorkflowService::PERMISO_CARGAR_REFERENCIAS,
            CandidatoWorkflowService::PERMISO_REFERENCIAS,
        ],
    ];

    public function __construct(private readonly PermisoAccionService $permisos) {}

    public function puedeConsultar(User $usuario, string $paso): bool
    {
        return $this->permisos->puedeAlguno($usuario, self::PERMISOS_DEL_PASO[$paso] ?? []);
    }

    /**
     * Qué pasos puede ver quien consulta la ficha (web y API).
     *
     * @return array{entrevista: bool, psicometricas: bool, socioeconomico: bool, referencias: bool}
     */
    public function pasosVisibles(User $usuario): array
    {
        return [
            self::PASO_ENTREVISTA => $this->puedeConsultar($usuario, self::PASO_ENTREVISTA),
            self::PASO_PSICOMETRICAS => $this->puedeConsultar($usuario, self::PASO_PSICOMETRICAS),
            self::PASO_SOCIOECONOMICO => $this->puedeConsultar($usuario, self::PASO_SOCIOECONOMICO),
            self::PASO_REFERENCIAS => $this->puedeConsultar($usuario, self::PASO_REFERENCIAS),
        ];
    }

    /**
     * Paso al que pertenece una evidencia (null = archivo suelto del mini
     * expediente, que se ve con solo ver al candidato).
     */
    public function pasoDeEvidencia(CandidatoEvidencia $evidencia): ?string
    {
        return match ((string) $evidencia->evidenciable_type) {
            (new CandidatoPsicometrica)->getMorphClass() => self::PASO_PSICOMETRICAS,
            (new CandidatoSocioeconomico)->getMorphClass() => self::PASO_SOCIOECONOMICO,
            (new CandidatoReferencia)->getMorphClass() => self::PASO_REFERENCIAS,
            default => null,
        };
    }

    /** Además de CandidatoPolicy::view: la evidencia es de un paso que puede ver. */
    public function puedeVerEvidencia(User $usuario, CandidatoEvidencia $evidencia): bool
    {
        $paso = $this->pasoDeEvidencia($evidencia);

        return $paso === null || $this->puedeConsultar($usuario, $paso);
    }
}
