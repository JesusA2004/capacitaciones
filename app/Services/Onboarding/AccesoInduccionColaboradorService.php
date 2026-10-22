<?php

namespace App\Services\Onboarding;

use App\Enums\AccesoInduccionColaborador;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoOnboarding;
use App\Enums\TipoModuloOnboarding;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\InduccionAsignacion;
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
 * RH también puede asignarla (o reasignarla, o retirarla) a mano desde
 * «Inducción institucional» (AsignacionInduccionService): a quien está en
 * onboarding le ajusta qué ver (presentación y/o material) y a quien ya
 * labora le abre una REINDUCCIÓN. Una asignación retirada deja la inducción
 * «Sin asignar» hasta que RH la vuelva a asignar.
 *
 * Misma regla para la web («Mi espacio») y la API móvil; el PDF oficial
 * además lo puede abrir quien gestiona onboarding (onboarding.gestionar).
 */
class AccesoInduccionColaboradorService
{
    /**
     * El proceso que lleva la Inducción Mr. Lana del colaborador: el más
     * reciente (ingreso o reinducción asignada por RH) que no esté cancelado
     * y tenga el módulo institucional con evaluación formal. Es la fuente de
     * «Mi inducción» en Mi espacio y en la app — distinto de
     * OnboardingService::procesoActual(), que solo ve el de ingreso.
     */
    public function procesoInduccion(Colaborador $colaborador): ?OnboardingProceso
    {
        return OnboardingProceso::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', '!=', EstadoOnboarding::Cancelado->value)
            ->whereHas('avances.modulo', fn (Builder $q) => $q
                ->where('tipo', TipoModuloOnboarding::Institucional->value)
                ->whereNotNull('evaluacion_formal_clave'))
            ->latest('id')
            ->first();
    }

    /**
     * El avance institucional con evaluación formal del proceso de inducción
     * del colaborador (la «inducción asignada»), o null si no tiene.
     */
    public function avanceInduccion(Colaborador $colaborador): ?OnboardingAvance
    {
        $proceso = $this->procesoInduccion($colaborador);

        if ($proceso === null) {
            return null;
        }

        return $this->avanceDeProceso($proceso);
    }

    public function avanceDeProceso(OnboardingProceso $proceso): ?OnboardingAvance
    {
        return $proceso->avances()
            ->whereHas('modulo', fn (Builder $q) => $q
                ->where('tipo', TipoModuloOnboarding::Institucional->value)
                ->whereNotNull('evaluacion_formal_clave'))
            ->with('modulo')
            ->first();
    }

    /**
     * La asignación vigente que hizo RH sobre ese avance (qué incluye, fecha
     * límite, mensaje), o null si nunca la tocó (asignación automática del
     * onboarding de ingreso) o si la retiró.
     */
    public function asignacionVigente(OnboardingAvance $avance): ?InduccionAsignacion
    {
        return InduccionAsignacion::query()
            ->where('onboarding_avance_id', $avance->id)
            ->where('vigente', true)
            ->with('asignadaPor:id,name,apellidos')
            ->first();
    }

    /** RH retiró la asignación y no la ha vuelto a asignar. */
    public function asignacionRetirada(OnboardingAvance $avance): bool
    {
        $filas = InduccionAsignacion::query()->where('onboarding_avance_id', $avance->id);

        return (clone $filas)->exists() && ! (clone $filas)->where('vigente', true)->exists();
    }

    /**
     * Qué tiene que ver el colaborador: sin asignación explícita, todo (la
     * presentación y el material vigente). RH puede asignar solo una parte.
     *
     * @return array{presentacion: bool, material: bool}
     */
    public function contenidoAsignado(OnboardingAvance $avance): array
    {
        $asignacion = $this->asignacionVigente($avance);

        return [
            'presentacion' => $asignacion === null || $asignacion->incluye_presentacion,
            'material' => $asignacion === null || $asignacion->incluye_material,
        ];
    }

    public function estado(Colaborador $colaborador): AccesoInduccionColaborador
    {
        $avance = $this->avanceInduccion($colaborador);

        if ($avance === null || $this->asignacionRetirada($avance)) {
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
