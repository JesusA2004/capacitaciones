<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoIntervencionCandidato;
use App\Enums\GrupoPuestoIndicador;
use App\Enums\PrioridadTarea;
use App\Enums\RutaIntervencionCandidato;
use App\Enums\TipoSeguimientoCandidato;
use App\Enums\TipoTarea;
use App\Models\Candidato;
use App\Models\IntervencionCandidato;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El flujo MÁS IMPORTANTE de reclutamiento (CLAUDE.md §10-12). Si RH
 * aprueba o rechaza normalmente, el proceso sigue o termina ahí — SIN
 * avisar a Regional ni a Dirección Comercial. Solo si el GERENTE que
 * entrevistó pulsa «Solicitar intervención» (motivo obligatorio) se abre
 * esta excepción, y solo entonces se notifica a quien debe decidir:
 *
 *   - Gestor/Volante (App\Enums\GrupoPuestoIndicador::Gestores) → Regional
 *     de la sucursal del candidato.
 *   - Cualquier otro puesto → Dirección Comercial.
 *
 * Resuelto por el grupo del puesto objetivo, nunca por el nombre del
 * puesto. Si aprueban, el candidato pasa a Contratación (nunca se le crea
 * una vacante artificial); si confirman el rechazo, queda rechazado. Todo
 * queda inmutable una vez decidido.
 */
class IntervencionCandidatoService
{
    public const PERMISO_DECIDIR = 'candidatos.intervencion_decidir';

    public function __construct(
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly CandidatoWorkflowService $workflow,
        private readonly NotificadorRhService $notificador,
        private readonly TareaService $tareas,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function solicitar(Candidato $candidato, User $gerente, string $motivo): IntervencionCandidato
    {
        if (! $gerente->can(CandidatoWorkflowService::PERMISO_GERENTE) || ! $this->jerarquia->alcanzaCandidato($gerente, $candidato)) {
            throw new AuthorizationException('No tienes permiso para solicitar una intervención sobre este candidato.');
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Explica por qué solicitas revisar este rechazo.']);
        }

        if ($candidato->estado !== EstadoCandidato::RechazadoRh) {
            throw ValidationException::withMessages(['estado' => 'Solo puede solicitarse una intervención sobre un rechazo de RH.']);
        }

        if ($candidato->intervenciones()->where('estado', EstadoIntervencionCandidato::Pendiente->value)->exists()) {
            throw ValidationException::withMessages(['estado' => 'Ya hay una intervención pendiente de decisión para este candidato.']);
        }

        ['ruta' => $ruta, 'aprobadores' => $aprobadores] = $this->resolverRuta($candidato);

        $intervencion = DB::transaction(fn (): IntervencionCandidato => IntervencionCandidato::query()->create([
            'candidato_id' => $candidato->id,
            'ruta' => $ruta,
            'rechazo_rh_por' => $candidato->salida_por,
            'rechazo_rh_motivo' => $candidato->motivo_salida,
            'rechazo_rh_en' => $candidato->salida_en,
            'gerente_solicitante_id' => $gerente->id,
            'motivo_solicitud' => $motivo,
            'solicitada_en' => now(),
            'estado' => EstadoIntervencionCandidato::Pendiente,
        ]));

        $this->auditoria->registrar('intervencion_solicitada', $intervencion, $gerente, [
            'candidato_id' => $candidato->id,
            'ruta' => $ruta->value,
        ]);

        // Solo AHORA, porque se solicitó explícitamente, se avisa a quien
        // decide — nunca en el rechazo normal de RH (CLAUDE.md §12).
        foreach ($aprobadores as $aprobador) {
            $this->tareas->abrir(TipoTarea::CandidatoIntervencionPendiente, $intervencion, [
                'titulo' => sprintf('Intervención pendiente: %s', $candidato->nombreCompleto()),
                'candidato' => $candidato,
                'sucursal_id' => $candidato->sucursal_id,
                'usuario' => $aprobador,
                'accion' => 'decidir_intervencion',
                'prioridad' => PrioridadTarea::Alta,
            ]);
        }

        if ($aprobadores->isNotEmpty()) {
            $this->notificador->notificar(
                $aprobadores,
                'intervencion_solicitada',
                'Intervención de candidato pendiente',
                sprintf('%s solicitó revisar el rechazo de RH a %s (%s).', $gerente->nombreCompleto(), $candidato->nombreCompleto(), $ruta->etiqueta()),
                $intervencion,
                'decidir_intervencion',
                'alta',
            );
        }

        return $intervencion;
    }

    public function decidir(IntervencionCandidato $intervencion, User $actor, bool $aprueba, ?string $comentario): IntervencionCandidato
    {
        $comentario = $comentario !== null && trim($comentario) !== '' ? trim($comentario) : null;

        $resultado = DB::transaction(function () use ($intervencion, $actor, $aprueba, $comentario): IntervencionCandidato {
            $actual = IntervencionCandidato::query()->lockForUpdate()->findOrFail($intervencion->id);

            if ($actual->estado !== EstadoIntervencionCandidato::Pendiente) {
                throw ValidationException::withMessages(['estado' => 'Esta intervención ya fue decidida.']);
            }

            if (! $this->puedeDecidir($actor, $actual)) {
                throw new AuthorizationException('No tienes autoridad para decidir esta intervención.');
            }

            $actual->update([
                'estado' => $aprueba ? EstadoIntervencionCandidato::Aprobada : EstadoIntervencionCandidato::RechazoConfirmado,
                'aprobador_id' => $actor->id,
                'comentario_decision' => $comentario,
                'decidida_en' => now(),
            ]);

            $candidato = $actual->candidato()->firstOrFail();

            if ($aprueba) {
                $nota = sprintf('Contratación autorizada por intervención de %s (%s)%s.', $actual->ruta->etiqueta(), $actor->nombreCompleto(), $comentario !== null ? ": {$comentario}" : '');
                $this->workflow->autorizarPorIntervencion($candidato, $actor, $nota);
            } else {
                $candidato->seguimientos()->create([
                    'tipo' => TipoSeguimientoCandidato::Nota,
                    'nota' => sprintf('%s confirmó el rechazo de RH tras la intervención%s.', $actual->ruta->etiqueta(), $comentario !== null ? ": {$comentario}" : '.'),
                    'fecha' => now(),
                    'registrado_por' => $actor->id,
                ]);
            }

            return $actual;
        });

        $this->tareas->resolver(TipoTarea::CandidatoIntervencionPendiente, $resultado, $actor);

        $this->auditoria->registrar($aprueba ? 'intervencion_aprobada' : 'intervencion_rechazo_confirmado', $resultado, $actor, [
            'candidato_id' => $resultado->candidato_id,
        ]);

        $candidato = $resultado->candidato()->first();
        $destinatarios = collect([$resultado->gerenteSolicitante, $resultado->rechazoRhPor])->filter()->unique('id');

        if ($candidato !== null && $destinatarios->isNotEmpty()) {
            $this->notificador->notificar(
                $destinatarios,
                $aprueba ? 'intervencion_aprobada' : 'intervencion_rechazo_confirmado',
                $aprueba ? 'Intervención aprobada' : 'Rechazo confirmado',
                $aprueba
                    ? sprintf('%s aprobó la intervención de %s: pasa a contratación.', $actor->nombreCompleto(), $candidato->nombreCompleto())
                    : sprintf('%s confirmó el rechazo de %s.', $actor->nombreCompleto(), $candidato->nombreCompleto()),
                $candidato,
                'ver_candidato',
                'alta',
            );
        }

        return $resultado;
    }

    /**
     * @return array{ruta: RutaIntervencionCandidato, aprobadores: Collection<int, User>}
     */
    private function resolverRuta(Candidato $candidato): array
    {
        $candidato->loadMissing('puestoObjetivo');
        $esGestorOVolante = $candidato->puestoObjetivo?->grupo_indicador === GrupoPuestoIndicador::Gestores;

        if ($esGestorOVolante) {
            return ['ruta' => RutaIntervencionCandidato::Regional, 'aprobadores' => $this->jerarquia->regionalesDe($candidato->sucursal_id)];
        }

        return ['ruta' => RutaIntervencionCandidato::DireccionComercial, 'aprobadores' => $this->jerarquia->direccionComercialDe()];
    }

    /**
     * La intervención pendiente de este candidato, solo si $actor tiene
     * autoridad para decidirla (Regional/Dirección Comercial correctos para
     * la ruta resuelta) — null en cualquier otro caso, para que la ficha
     * nunca muestre botones de decisión a quien no corresponde.
     */
    public function pendienteDecidiblePor(Candidato $candidato, User $actor): ?IntervencionCandidato
    {
        $pendiente = $candidato->intervenciones()->where('estado', EstadoIntervencionCandidato::Pendiente->value)->latest('id')->first();

        if ($pendiente === null || ! $this->puedeDecidir($actor, $pendiente)) {
            return null;
        }

        return $pendiente;
    }

    private function puedeDecidir(User $actor, IntervencionCandidato $intervencion): bool
    {
        if (! $actor->can(self::PERMISO_DECIDIR)) {
            return false;
        }

        $candidato = $intervencion->candidato()->first();

        if ($candidato === null) {
            return false;
        }

        ['aprobadores' => $aprobadores] = $this->resolverRuta($candidato);

        return $aprobadores->contains('id', $actor->id);
    }
}
