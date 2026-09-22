<?php

namespace App\Services\Solicitudes;

use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Colaboradores\JerarquiaColaboradorService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Vistos buenos jerárquicos sobre solicitudes internas, usando la
 * estructura real de personas (jefe inmediato / gerente, ver
 * JerarquiaColaboradorService). Qué tipos exigen visto bueno del jefe antes
 * de la autorización final de RH/Dirección vive en config/solicitudes.php
 * (`visto_bueno_jefe`). Si el colaborador no tiene jefe capturado, el paso
 * no aplica (no se bloquea el trámite por un dato faltante; RH decide).
 */
class AprobacionJerarquicaService
{
    public function __construct(private readonly JerarquiaColaboradorService $jerarquia) {}

    public function requiereVistoBuenoJefe(SolicitudInterna $solicitud): bool
    {
        /** El arreglo de configuración son claves de TipoSolicitudInterna. */
        $tipos = (array) config('solicitudes.visto_bueno_jefe', []);

        if (! in_array($solicitud->tipo->value, $tipos, true)) {
            return false;
        }

        $colaborador = $solicitud->personaSolicitante();

        return $colaborador !== null && ($colaborador->jefe_id !== null || $colaborador->gerente_id !== null);
    }

    public function decisionJefe(SolicitudInterna $solicitud): ?SolicitudAprobacion
    {
        return SolicitudAprobacion::query()
            ->where('solicitud_interna_id', $solicitud->id)
            ->where('nivel', SolicitudAprobacion::NIVEL_JEFE_INMEDIATO)
            ->first();
    }

    public function tieneVistoBuenoJefe(SolicitudInterna $solicitud): bool
    {
        return $this->decisionJefe($solicitud)?->decision === SolicitudAprobacion::DECISION_APROBADO;
    }

    public function puedeDarVistoBueno(User $usuario, SolicitudInterna $solicitud): bool
    {
        $colaborador = $solicitud->personaSolicitante();

        return $colaborador !== null && $this->jerarquia->usuarioEsJefeDe($usuario, $colaborador);
    }

    /**
     * Registra la decisión del jefe (una sola por solicitud: índice único).
     *
     * @throws ValidationException Si no es el jefe, ya decidió, o la solicitud ya no está en trámite.
     */
    public function registrarDecisionJefe(SolicitudInterna $solicitud, User $jefe, bool $aprobado, ?string $comentario): SolicitudAprobacion
    {
        if (! $this->puedeDarVistoBueno($jefe, $solicitud)) {
            throw ValidationException::withMessages(['solicitud' => 'Solo el jefe inmediato o gerente del colaborador puede dar el visto bueno.']);
        }

        if ($solicitud->estado->esFinal() || $solicitud->estado->value === 'aprobada') {
            throw ValidationException::withMessages(['solicitud' => 'La solicitud ya no está en trámite.']);
        }

        if (! $aprobado && trim((string) $comentario) === '') {
            throw ValidationException::withMessages(['comentario' => 'Indica el motivo por el que no das el visto bueno.']);
        }

        try {
            return SolicitudAprobacion::query()->create([
                'solicitud_interna_id' => $solicitud->id,
                'nivel' => SolicitudAprobacion::NIVEL_JEFE_INMEDIATO,
                'decision' => $aprobado ? SolicitudAprobacion::DECISION_APROBADO : SolicitudAprobacion::DECISION_RECHAZADO,
                'user_id' => $jefe->id,
                'comentario' => $comentario,
            ]);
        } catch (QueryException) {
            throw ValidationException::withMessages(['solicitud' => 'Esta solicitud ya tiene la decisión del jefe inmediato.']);
        }
    }
}
