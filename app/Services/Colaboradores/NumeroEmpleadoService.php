<?php

namespace App\Services\Colaboradores;

use App\Models\Colaborador;

/**
 * Número de empleado operativo (EMP-0001…): único, consecutivo sobre todo
 * el histórico (incluye bajas). Única regla para el alta y la migración
 * inicial; la «Clave» histórica del Excel nunca se convierte en este número
 * (va a clave_legacy).
 */
class NumeroEmpleadoService
{
    public function siguiente(): string
    {
        $maximo = Colaborador::withTrashed()
            ->where('numero_empleado', 'like', 'EMP-%')
            ->pluck('numero_empleado')
            ->map(fn (?string $n) => (int) preg_replace('/\D/', '', (string) $n))
            ->max() ?? 0;

        return sprintf('EMP-%04d', ((int) $maximo) + 1);
    }
}
