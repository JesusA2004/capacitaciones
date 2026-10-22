<?php

namespace App\Policies;

use App\Models\EvaluacionInduccion;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Onboarding\OnboardingService;

/**
 * El colaborador titular presenta su propia evaluación de inducción; RH con
 * permiso onboarding.gestionar (y alcance sobre el colaborador) la califica
 * y retroalimenta. Nadie califica ni retroalimenta la suya propia — salvo
 * super_admin, ver ConSuperAdminOperativo.
 */
class EvaluacionInduccionPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, EvaluacionInduccion $evaluacion): bool
    {
        return $usuario->colaborador_id === $evaluacion->colaborador_id
            || ($usuario->can(OnboardingService::PERMISO_GESTIONAR) && $this->alcance->alcanzaColaborador($usuario, $evaluacion->colaborador));
    }

    public function presentar(User $usuario, EvaluacionInduccion $evaluacion): bool
    {
        return $usuario->colaborador_id !== null && $usuario->colaborador_id === $evaluacion->colaborador_id;
    }

    /** onboarding.gestionar + la acción onboarding.calificar (Roles y permisos). */
    public function calificar(User $usuario, EvaluacionInduccion $evaluacion): bool
    {
        return $usuario->can(OnboardingService::PERMISO_GESTIONAR)
            && $usuario->can('onboarding.calificar')
            && $usuario->colaborador_id !== $evaluacion->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $evaluacion->colaborador);
    }

    /**
     * PDF FO-RH-EI (lleva la clave de respuestas): onboarding.gestionar + la
     * acción onboarding.descargar_pdf, con alcance y nunca el propio.
     */
    public function descargarPdf(User $usuario, EvaluacionInduccion $evaluacion): bool
    {
        return $usuario->can(OnboardingService::PERMISO_GESTIONAR)
            && $usuario->can('onboarding.descargar_pdf')
            && $usuario->colaborador_id !== $evaluacion->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $evaluacion->colaborador);
    }
}
