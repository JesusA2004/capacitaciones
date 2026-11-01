<?php

namespace App\Services\Reaperturas;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoIntervencionCandidato;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoSolicitudVacaciones;
use App\Enums\ResultadoEtapaCandidato;
use App\Enums\TipoReapertura;
use App\Enums\TipoSolicitudInterna;
use App\Models\Candidato;
use App\Models\CandidatoEntrevista;
use App\Models\IntervencionCandidato;
use App\Models\Reapertura;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Organigrama\AutoridadJerarquicaService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\IntervencionCandidatoService;
use App\Services\Solicitudes\AprobacionJerarquicaService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Solicitudes\TareasSolicitudService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Reabrir una decisión comercial: regla de la dueña del negocio, igual en web y API.
 * Solo alguien a uno o más niveles por encima de quien decidió
 * (AutoridadJerarquicaService::esRangoSuperior), dentro de lo que ya puede ver, con
 * motivo obligatorio. Cada reapertura queda en `reaperturas`, en el historial del
 * trámite y en la bitácora; la decisión anterior se conserva completa en ese registro.
 *
 * Primero se revisa la autoridad y después el estado: a quien no le corresponde se le
 * responde 403 con un mensaje que no dice nada del trámite.
 */
class ReaperturaService
{
    public const MENSAJE_SIN_AUTORIDAD = 'Solo un puesto superior a quien lo decidió puede reabrirlo.';

    public function __construct(
        private readonly AutoridadJerarquicaService $autoridad,
        private readonly AprobacionJerarquicaService $aprobaciones,
        private readonly SolicitudesService $solicitudes,
        private readonly TareasSolicitudService $tareasSolicitud,
        private readonly IntervencionCandidatoService $intervenciones,
        private readonly CandidatoWorkflowService $workflow,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Lo que esta cuenta puede reabrir ahora sobre el trámite: el botón «Reabrir»
     * solo se muestra con esto.
     *
     * @return list<array{tipo: string, id: int, etiqueta: string}>
     */
    public function disponibles(User $actor, Candidato|SolicitudInterna|SolicitudVacaciones $sujeto): array
    {
        $opciones = match (true) {
            $sujeto instanceof SolicitudInterna => [[$this->tipoDeSolicitud($sujeto), $sujeto]],
            $sujeto instanceof SolicitudVacaciones => [[TipoReapertura::VacacionesRechazadas, $sujeto]],
            default => [
                [TipoReapertura::EntrevistaCandidato, $sujeto],
                [TipoReapertura::IntervencionCandidato, $sujeto->intervenciones()->latest('id')->first()],
            ],
        };

        $disponibles = [];

        foreach ($opciones as [$tipo, $registro]) {
            if ($registro !== null && $this->tieneAutoridad($actor, $tipo, $registro) && $this->impedimento($tipo, $registro) === null) {
                $disponibles[] = ['tipo' => $tipo->value, 'id' => (int) $registro->getKey(), 'etiqueta' => $tipo->etiqueta()];
            }
        }

        return $disponibles;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reabrir(TipoReapertura $tipo, int $id, User $actor, string $motivo): Reapertura
    {
        $motivo = trim($motivo);
        $registro = $this->registro($tipo, $id);

        if (! $this->tieneAutoridad($actor, $tipo, $registro)) {
            throw new AuthorizationException(self::MENSAJE_SIN_AUTORIDAD);
        }

        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo para reabrir.']);
        }

        $reapertura = DB::transaction(function () use ($tipo, $registro, $actor, $motivo): Reapertura {
            $actual = $this->bloquear($registro);
            $impedimento = $this->impedimento($tipo, $actual);

            if ($impedimento !== null) {
                throw ValidationException::withMessages(['motivo' => $impedimento]);
            }

            $autorId = $this->autorId($tipo, $actual);
            $anterior = match (true) {
                $actual instanceof SolicitudInterna => $this->reabrirSolicitud($actual, $actor, $motivo),
                $actual instanceof SolicitudVacaciones => $this->reabrirVacaciones($actual),
                $actual instanceof IntervencionCandidato => $this->intervenciones->reabrir($actual, $actor, $motivo),
                $actual instanceof Candidato => $this->workflow->regresarAEntrevista($actual, $actor, $motivo),
            };

            return Reapertura::query()->create([
                'tipo' => $tipo,
                'reabrible_type' => $actual->getMorphClass(),
                'reabrible_id' => $actual->getKey(),
                'decidido_por' => $autorId,
                'reabierto_por' => $actor->id,
                'motivo' => $motivo,
                'decision_anterior' => $anterior,
            ]);
        });

        $this->despuesDeReabrir($registro, $actor);
        $this->auditoria->registrar('decision_reabierta', $reapertura, $actor, ['tipo' => $tipo->value, 'motivo' => $motivo]);

        return $reapertura;
    }

    private function bloquear(Candidato|SolicitudInterna|SolicitudVacaciones|IntervencionCandidato $registro): Candidato|SolicitudInterna|SolicitudVacaciones|IntervencionCandidato
    {
        return match (true) {
            $registro instanceof Candidato => Candidato::query()->lockForUpdate()->findOrFail($registro->id),
            $registro instanceof SolicitudInterna => SolicitudInterna::query()->lockForUpdate()->findOrFail($registro->id),
            $registro instanceof SolicitudVacaciones => SolicitudVacaciones::query()->lockForUpdate()->findOrFail($registro->id),
            $registro instanceof IntervencionCandidato => IntervencionCandidato::query()->lockForUpdate()->findOrFail($registro->id),
        };
    }

    private function registro(TipoReapertura $tipo, int $id): Candidato|SolicitudInterna|SolicitudVacaciones|IntervencionCandidato
    {
        return match ($tipo) {
            TipoReapertura::VistoBuenoSolicitud, TipoReapertura::SolicitudRechazada => SolicitudInterna::query()->findOrFail($id),
            TipoReapertura::VacacionesRechazadas => SolicitudVacaciones::query()->findOrFail($id),
            TipoReapertura::IntervencionCandidato => IntervencionCandidato::query()->findOrFail($id),
            TipoReapertura::EntrevistaCandidato => Candidato::query()->findOrFail($id),
        };
    }

    private function tieneAutoridad(User $actor, TipoReapertura $tipo, Model $registro): bool
    {
        $sujeto = $registro instanceof IntervencionCandidato ? $registro->candidato()->first() : $registro;

        return $sujeto !== null
            && Gate::forUser($actor)->allows('view', $sujeto)
            && $this->autoridad->esRangoSuperior($actor, $this->autoridad->autorPorId($this->autorId($tipo, $registro)));
    }

    private function autorId(TipoReapertura $tipo, Model $registro): ?int
    {
        return match (true) {
            $tipo === TipoReapertura::VistoBuenoSolicitud && $registro instanceof SolicitudInterna => $this->ultimaDecision($registro)?->user_id,
            $registro instanceof SolicitudInterna, $registro instanceof SolicitudVacaciones => $registro->revisado_por,
            $registro instanceof IntervencionCandidato => $registro->aprobador_id,
            $registro instanceof Candidato => $this->ultimaEntrevista($registro)?->registrado_por,
            default => null,
        };
    }

    /**
     * Por qué ya no se puede reabrir; null si se puede.
     */
    private function impedimento(TipoReapertura $tipo, Model $registro): ?string
    {
        return match (true) {
            $registro instanceof SolicitudInterna => $this->impedimentoDeSolicitud($tipo, $registro),
            $registro instanceof SolicitudVacaciones => $registro->estado === EstadoSolicitudVacaciones::Rechazada ? null : 'Solo se reabren vacaciones rechazadas.',
            $registro instanceof IntervencionCandidato => $this->impedimentoDeIntervencion($registro),
            $registro instanceof Candidato => $this->impedimentoDeEntrevista($registro),
            default => 'Esta decisión no se puede reabrir.',
        };
    }

    private function tipoDeSolicitud(SolicitudInterna $solicitud): TipoReapertura
    {
        $ultima = $this->ultimaDecision($solicitud);

        return $solicitud->estado === EstadoSolicitudInterna::Rechazada && $ultima?->decision !== SolicitudAprobacion::DECISION_RECHAZADO
            ? TipoReapertura::SolicitudRechazada
            : TipoReapertura::VistoBuenoSolicitud;
    }

    private function impedimentoDeSolicitud(TipoReapertura $tipo, SolicitudInterna $solicitud): ?string
    {
        if ($solicitud->tipo === TipoSolicitudInterna::BajaColaborador) {
            return 'Las bajas las cancela o reabre solo Recursos Humanos.';
        }

        if ($tipo !== $this->tipoDeSolicitud($solicitud)) {
            return 'Esta solicitud ya cambió: recarga la página.';
        }

        if ($tipo === TipoReapertura::SolicitudRechazada) {
            return null;
        }

        return in_array($solicitud->estado, [EstadoSolicitudInterna::Enviada, EstadoSolicitudInterna::EnRevision, EstadoSolicitudInterna::Rechazada], true)
            ? null
            : 'Esta solicitud ya no está en trámite: su visto bueno no se puede reabrir.';
    }

    private function impedimentoDeIntervencion(IntervencionCandidato $intervencion): ?string
    {
        if ($intervencion->estado === EstadoIntervencionCandidato::Pendiente) {
            return 'Esta intervención sigue pendiente de decisión.';
        }

        if ($intervencion->estado === EstadoIntervencionCandidato::Aprobada && $intervencion->candidato()->first()?->estado !== EstadoCandidato::AutorizadoRh) {
            return 'El candidato ya entró a contratación: esta intervención ya no se puede reabrir.';
        }

        return null;
    }

    private function impedimentoDeEntrevista(Candidato $candidato): ?string
    {
        if ($this->ultimaEntrevista($candidato) === null) {
            return 'Este candidato no tiene una entrevista decidida.';
        }

        $orden = $candidato->estado->orden();
        $sigueAbierto = $orden >= EstadoCandidato::PsicometricasPendientes->orden() && $orden <= EstadoCandidato::ReferenciasPendientes->orden();

        if ($sigueAbierto || $this->workflow->salioEnEntrevista($candidato)) {
            return null;
        }

        return 'El proceso de este candidato ya no está en una etapa donde se pueda reabrir su entrevista.';
    }

    /**
     * @return array<string, mixed>
     */
    private function reabrirSolicitud(SolicitudInterna $solicitud, User $actor, string $motivo): array
    {
        $ultima = $this->tipoDeSolicitud($solicitud) === TipoReapertura::VistoBuenoSolicitud ? $this->ultimaDecision($solicitud) : null;
        $anterior = [
            'estado' => $solicitud->estado->value,
            'motivo_rechazo' => $solicitud->motivo_rechazo,
            'revisado_por' => $solicitud->revisado_por,
            'revisado_en' => $solicitud->revisado_en?->toIso8601String(),
            'visto_bueno' => $ultima === null ? null : [
                'nivel' => $ultima->nivel,
                'decision' => $ultima->decision,
                'user_id' => $ultima->user_id,
                'comentario' => $ultima->comentario,
                'fecha' => $ultima->created_at?->toIso8601String(),
            ],
        ];

        // Una sola decisión por nivel (índice único): el registro de la reapertura la conserva.
        $ultima?->delete();

        $solicitud->update([
            'estado' => $this->aprobaciones->tieneVistoBuenoJefe($solicitud) ? EstadoSolicitudInterna::EnRevision : EstadoSolicitudInterna::Enviada,
            'motivo_rechazo' => null,
            'revisado_por' => null,
            'revisado_en' => null,
        ]);
        $this->solicitudes->registrarHistorial($solicitud, $actor, 'decision_reabierta', $motivo);

        return $anterior;
    }

    /**
     * @return array<string, mixed>
     */
    private function reabrirVacaciones(SolicitudVacaciones $vacaciones): array
    {
        $anterior = [
            'estado' => $vacaciones->estado->value,
            'motivo_rechazo' => $vacaciones->motivo_rechazo,
            'revisado_por' => $vacaciones->revisado_por,
            'revisado_en' => $vacaciones->revisado_en?->toIso8601String(),
        ];

        $vacaciones->update(['estado' => EstadoSolicitudVacaciones::Pendiente, 'motivo_rechazo' => null, 'revisado_por' => null, 'revisado_en' => null]);

        return $anterior;
    }

    /**
     * Pendientes y avisos, ya con la reapertura guardada: un fallo aquí no la deshace.
     */
    private function despuesDeReabrir(Model $registro, User $actor): void
    {
        match (true) {
            $registro instanceof SolicitudInterna => $this->tareasSolicitud->alReabrir($registro->refresh(), $actor),
            $registro instanceof IntervencionCandidato => $this->intervenciones->avisarReapertura($registro->refresh()),
            $registro instanceof Candidato => $this->workflow->actualizarPendientes($registro->refresh(), $actor),
            default => null,
        };
    }

    private function ultimaDecision(SolicitudInterna $solicitud): ?SolicitudAprobacion
    {
        return SolicitudAprobacion::query()->where('solicitud_interna_id', $solicitud->id)->latest('id')->first();
    }

    private function ultimaEntrevista(Candidato $candidato): ?CandidatoEntrevista
    {
        return CandidatoEntrevista::query()->where('candidato_id', $candidato->id)
            ->whereIn('resultado', [ResultadoEtapaCandidato::Viable->value, ResultadoEtapaCandidato::NoViable->value])
            ->latest('id')
            ->first();
    }
}
