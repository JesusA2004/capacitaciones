<?php

namespace App\Enums;

/**
 * Rol de un App\Models\AsignacionNodoComercial sobre un nodo de la matriz
 * comercial. Solo "gestor" cuenta para la cobertura de una ruta
 * (App\Services\MatrizComercial\MatrizComercialService::cobertura()); apoyo
 * y volante son colaboradores adicionales que también trabajan la ruta sin
 * ser su responsable titular.
 */
enum TipoAsignacionNodoComercial: string
{
    case Gestor = 'gestor';
    case Apoyo = 'apoyo';
    case Volante = 'volante';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Gestor => 'Gestor',
            self::Apoyo => 'Apoyo',
            self::Volante => 'Volante',
        };
    }
}
