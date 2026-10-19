<?php

namespace App\Services\Onboarding;

use App\Enums\AccesoInduccionColaborador;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\TipoModuloOnboarding;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingAvance;
use App\Models\OnboardingProceso;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cuándo el colaborador puede VER la Inducción Mr. Lana en «Mi espacio».
 *
 * NUEVO FLUJO (2026): la inducción se le ASIGNA al iniciar su onboarding y la
 * ve SOLO —hojas del PDF oficial + material de audio/video— antes de su
 * evaluación. El acceso al contenido depende únicamente de tener un avance
 * institucional (con evaluación formal) ASIGNADO y aún no aprobado; ya no
 * depende de ninguna «sesión en vivo» de RH (eso quedó tras el flag
 * features.induccion_sesiones_legacy).
 *
 * Misma regla para la web («Mi espacio») y la API móvil; el PDF oficial
 * además lo puede abrir quien gestiona onboarding (onboarding.gestionar).
 */
class AccesoInduccionColaboradorService
{
    /**
     * El avance institucional con evaluación formal del proceso abierto del
     * colaborador (la «inducción asignada»), o null si no tiene.
     */
    public function avanceInduccion(Colaborador $colaborador): ?OnboardingAvance
    {
        $proceso = OnboardingProceso::query()->where('colaborador_id', $colaborador->id)->latest('id')->first();

        if ($proceso === null) {
            return null;
        }

        return $proceso->avances()
            ->whereHas('modulo', fn (Builder $q) => $q
                ->where('tipo', TipoModuloOnboarding::Institucional->value)
                ->whereNotNull('evaluacion_formal_clave'))
            ->with('modulo')
            ->first();
    }

    public function estado(Colaborador $colaborador): AccesoInduccionColaborador
    {
        $avance = $this->avanceInduccion($colaborador);

        if ($avance === null) {
            return AccesoInduccionColaborador::SinAsignar;
        }

        $evaluacion = EvaluacionInduccion::query()
            ->where('onboarding_proceso_id', $avance->onboarding_proceso_id)
            ->latest('id')
            ->first();

        return match (true) {
            $evaluacion === null => AccesoInduccionColaborador::Disponible,
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
     * Regla del COLABORADOR (sin importar si su cuenta también tiene permisos
     * de RH: «Mi espacio» es solo de colaborador).
     */
    public function puedeVerContenido(User $usuario): bool
    {
        $colaborador = $usuario->colaborador;

        return $colaborador instanceof Colaborador && $this->estado($colaborador)->puedeVerContenido();
    }

    /**
     * @throws AuthorizationException si la inducción no está asignada / ya se completó
     */
    public function exigirContenidoVisible(User $usuario): void
    {
        if ($this->puedeVerContenido($usuario)) {
            return;
        }

        $colaborador = $usuario->colaborador;

        throw new AuthorizationException($colaborador instanceof Colaborador
            ? $this->estado($colaborador)->mensaje()
            : AccesoInduccionColaborador::SinAsignar->mensaje());
    }

    /**
     * El PDF oficial: RH que gestiona onboarding (repaso/carga de material) o
     * el colaborador mientras su inducción está asignada y por ver.
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
