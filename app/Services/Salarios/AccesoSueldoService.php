<?php

namespace App\Services\Salarios;

use App\Models\User;

/**
 * Regla única para mostrar el sueldo de una persona fuera de Administración de
 * salarios (expediente, ciclo laboral, contratos, finiquito del cierre). Ser
 * gerente o tener permisos generales de expedientes no da acceso: solo el
 * permiso expreso o la propia persona sobre su propio sueldo.
 */
class AccesoSueldoService
{
    public const PERMISO = 'salarios.ver_en_expediente';

    public function puedeVer(?User $usuario, ?int $colaboradorId = null): bool
    {
        if ($usuario === null) {
            return false;
        }

        if ($colaboradorId !== null && $usuario->colaborador_id === $colaboradorId) {
            return true;
        }

        return $usuario->can(self::PERMISO) || $usuario->can('salarios.ver');
    }

    /** Importes de un finiquito: el flujo de finiquitos tiene su propio permiso. */
    public function puedeVerFiniquito(?User $usuario, ?int $colaboradorId = null): bool
    {
        return $this->puedeVer($usuario, $colaboradorId) || $usuario?->can('finiquitos.ver') === true;
    }
}
