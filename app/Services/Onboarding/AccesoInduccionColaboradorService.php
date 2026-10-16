<?php

namespace App\Services\Onboarding;

use App\Enums\AccesoInduccionColaborador;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoSesionInduccion;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingProceso;
use App\Models\SesionInduccion;
use App\Models\SesionInduccionColaborador;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cuándo el colaborador puede VER la Inducción Mr. Lana. La presenta
 * Recursos Humanos (SesionInduccionService): el colaborador nunca presenta,
 * solo ve el contenido mientras una sesión «En curso» lo incluye como
 * asistente; después contesta su evaluación (EvaluacionInduccionService).
 * Antes de la sesión y una vez terminada, el contenido no se le muestra.
 *
 * Misma regla para la web («Mi espacio») y la API móvil; el PDF oficial
 * además lo puede abrir quien gestiona onboarding (RH presentando).
 */
class AccesoInduccionColaboradorService
{
    /**
     * La sesión «En curso» de RH que incluye a este colaborador, o null.
     */
    public function sesionEnCursoPara(int $colaboradorId): ?SesionInduccion
    {
        $asistencia = SesionInduccionColaborador::query()
            ->where('colaborador_id', $colaboradorId)
            ->whereHas('sesion', fn (Builder $q) => $q->where('estado', EstadoSesionInduccion::EnCurso->value))
            ->with('sesion')
            ->latest('id')
            ->first();

        return $asistencia?->sesion;
    }

    public function estado(Colaborador $colaborador): AccesoInduccionColaborador
    {
        if ($this->sesionEnCursoPara($colaborador->id) !== null) {
            return AccesoInduccionColaborador::EnSesion;
        }

        $proceso = OnboardingProceso::query()->where('colaborador_id', $colaborador->id)->latest('id')->first();
        $evaluacion = $proceso !== null
            ? EvaluacionInduccion::query()->where('onboarding_proceso_id', $proceso->id)->latest('id')->first()
            : null;

        return match (true) {
            $evaluacion === null => AccesoInduccionColaborador::PendientePresentacion,
            $evaluacion->estado === EstadoEvaluacionInduccion::Aprobada => AccesoInduccionColaborador::Completada,
            $evaluacion->estado->permitePresentar() => AccesoInduccionColaborador::EvaluacionPendiente,
            default => AccesoInduccionColaborador::EnRevision,
        };
    }

    /**
     * @return array{estado: string, etiqueta: string, mensaje: string, puede_ver_contenido: bool, puede_contestar_evaluacion: bool}
     */
    public function resumen(Colaborador $colaborador): array
    {
        $estado = $this->estado($colaborador);

        return [
            'estado' => $estado->value,
            'etiqueta' => $estado->etiqueta(),
            'mensaje' => $estado->mensaje(),
            'puede_ver_contenido' => $estado->puedeVerContenido(),
            'puede_contestar_evaluacion' => $estado === AccesoInduccionColaborador::EvaluacionPendiente,
        ];
    }

    /**
     * Regla del COLABORADOR (sin importar si su cuenta también tiene
     * permisos de RH: «Mi espacio» es solo de colaborador).
     */
    public function puedeVerContenido(User $usuario): bool
    {
        return $usuario->colaborador_id !== null && $this->sesionEnCursoPara($usuario->colaborador_id) !== null;
    }

    /**
     * @throws AuthorizationException fuera de una sesión de RH que lo incluya
     */
    public function exigirContenidoVisible(User $usuario): void
    {
        if ($this->puedeVerContenido($usuario)) {
            return;
        }

        $colaborador = $usuario->colaborador;

        throw new AuthorizationException($colaborador instanceof Colaborador
            ? $this->estado($colaborador)->mensaje()
            : AccesoInduccionColaborador::PendientePresentacion->mensaje());
    }

    /**
     * El PDF oficial: RH que presenta (onboarding.gestionar) o el
     * colaborador mientras está en la sesión.
     *
     * @throws AuthorizationException
     */
    public function exigirPdfVisible(User $usuario): void
    {
        if ($usuario->can(OnboardingService::PERMISO_GESTIONAR)) {
            return;
        }

        $this->exigirContenidoVisible($usuario);
    }
}
