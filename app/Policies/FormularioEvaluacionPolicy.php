<?php

namespace App\Policies;

use App\Models\FormularioEvaluacion;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\Evaluaciones\FormularioEvaluacionService;

/**
 * Configurar los formularios de evaluación (inducción y periodo de prueba)
 * es de Recursos Humanos con `formularios_evaluacion.administrar`. Una
 * versión publicada nunca se edita: solo el borrador.
 */
class FormularioEvaluacionPolicy
{
    use ConSuperAdminOperativo;

    public function viewAny(User $usuario): bool
    {
        return $usuario->can(FormularioEvaluacionService::PERMISO_ADMINISTRAR);
    }

    public function view(User $usuario, FormularioEvaluacion $formulario): bool
    {
        return $usuario->can(FormularioEvaluacionService::PERMISO_ADMINISTRAR);
    }

    public function create(User $usuario): bool
    {
        return $usuario->can(FormularioEvaluacionService::PERMISO_ADMINISTRAR);
    }

    public function update(User $usuario, FormularioEvaluacion $formulario): bool
    {
        return $usuario->can(FormularioEvaluacionService::PERMISO_ADMINISTRAR) && $formulario->esBorrador();
    }

    public function publicar(User $usuario, FormularioEvaluacion $formulario): bool
    {
        return $this->update($usuario, $formulario);
    }
}
