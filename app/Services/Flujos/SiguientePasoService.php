<?php

namespace App\Services\Flujos;

use App\Enums\EstatusBaja;
use App\Enums\FlujoOperativo;
use App\Models\Baja;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Flujos\Dto\SiguientePaso;
use App\Services\Flujos\Resolvers\FlujoBajaResolver;
use App\Services\Flujos\Resolvers\FlujoContratacionResolver;
use App\Services\Flujos\Resolvers\FlujoPermanenciaResolver;
use App\Services\Flujos\Resolvers\FlujoSueldoResolver;

/**
 * Fachada de «Siguiente paso»: lo mismo para web y API. Solo elige el resolver;
 * las reglas siguen en los servicios de cada módulo (docs/SIGUIENTE_PASO.md).
 */
class SiguientePasoService
{
    public function __construct(
        private readonly CicloLaboralService $ciclo,
        private readonly FlujoContratacionResolver $contratacion,
        private readonly FlujoPermanenciaResolver $permanencia,
        private readonly FlujoBajaResolver $bajas,
        private readonly FlujoSueldoResolver $sueldo,
    ) {}

    /**
     * @param  array<string, mixed>|null  $estado  el de obtenerEstado(), si ya se calculó
     */
    public function paraCandidato(Candidato $candidato, User $viewer, ?array $estado = null): SiguientePaso
    {
        return $this->contratacion->paraCandidato($candidato, $viewer, $estado);
    }

    /**
     * Pantalla del ciclo laboral. Con una baja abierta manda la baja.
     *
     * @param  array<string, mixed>|null  $estado
     */
    public function paraColaborador(Colaborador $colaborador, User $viewer, ?array $estado = null): SiguientePaso
    {
        $estado ??= $this->ciclo->obtenerEstado($colaborador, $viewer, false);

        if ($estado['etapa']['clave'] === 'cierre') {
            $baja = $this->bajaAbierta($colaborador);

            if ($baja !== null) {
                return $this->bajas->resolver($baja, $viewer, FlujoBajaResolver::EN_CICLO);
            }
        }

        return $this->contratacion->paraColaborador($colaborador, $viewer, $estado);
    }

    /**
     * Pantalla del expediente: mientras se contrata, el proceso; después, el hub.
     */
    public function paraExpediente(Colaborador $colaborador, User $viewer): SiguientePaso
    {
        if ($colaborador->estado_alta?->enContratacion() === true) {
            $estado = $this->ciclo->obtenerEstado($colaborador, $viewer, false);

            return $this->contratacion->paraColaborador($colaborador, $viewer, $estado, FlujoOperativo::Contratacion);
        }

        return $this->permanencia->resolver($colaborador, $viewer, FlujoPermanenciaResolver::EN_EXPEDIENTE);
    }

    public function paraSolicitud(SolicitudInterna $solicitud, Colaborador $colaborador, User $viewer): SiguientePaso
    {
        return $this->permanencia->resolver($colaborador, $viewer, FlujoPermanenciaResolver::EN_SOLICITUD, solicitud: $solicitud);
    }

    public function paraIncidencias(Colaborador $colaborador, User $viewer): SiguientePaso
    {
        return $this->permanencia->resolver($colaborador, $viewer, FlujoPermanenciaResolver::EN_INCIDENCIAS);
    }

    public function paraNomina(Colaborador $colaborador, User $viewer, ?ReciboNomina $recibo): SiguientePaso
    {
        return $this->permanencia->resolver($colaborador, $viewer, FlujoPermanenciaResolver::EN_NOMINA, recibo: $recibo);
    }

    public function paraBaja(Baja $baja, User $viewer): SiguientePaso
    {
        return $this->bajas->resolver($baja, $viewer);
    }

    /**
     * null: sin permiso de ver el sueldo no hay panel ni pista de que cambió.
     */
    public function paraSueldo(Colaborador $colaborador, User $viewer): ?SiguientePaso
    {
        return $this->sueldo->resolver($colaborador, $viewer);
    }

    private function bajaAbierta(Colaborador $colaborador): ?Baja
    {
        return Baja::query()->where('colaborador_id', $colaborador->id)
            ->whereIn('estatus', [EstatusBaja::EnRevisionRh->value, EstatusBaja::EnRevisionJuridico->value])
            ->latest('id')
            ->first();
    }
}
