<?php

namespace App\Enums;

/**
 * Qué representa cada entrada que dirección listó dentro de una zona de la
 * matriz comercial (ver App\Services\MatrizComercial\ClasificadorNodoComercial
 * y docs/MATRIZ_COMERCIAL.md). Solo las rutas (de cobro, cartera especial u
 * operación grupal) se le asignan a un Gestor.
 */
enum ClaseEntradaMatriz: string
{
    case RutaCobro = 'ruta_cobro';
    case Gerencia = 'gerencia';
    case Subgerencia = 'subgerencia';
    case Volante = 'volante';
    case RutaInactiva = 'ruta_inactiva';
    case CarteraEspecial = 'cartera_especial';
    case OperacionGrupal = 'operacion_grupal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::RutaCobro => 'Ruta de cobro',
            self::Gerencia => 'Posición de gerente',
            self::Subgerencia => 'Posición de subgerente',
            self::Volante => 'Gestor Volante',
            self::RutaInactiva => 'Ruta inactiva',
            self::CarteraEspecial => 'Cartera vencida / castigo',
            self::OperacionGrupal => 'Operación grupal',
        };
    }

    public function tipoNodo(): TipoNodoComercial
    {
        return match ($this) {
            self::Gerencia => TipoNodoComercial::Gerencia,
            self::Subgerencia => TipoNodoComercial::Subgerencia,
            self::Volante => TipoNodoComercial::Volante,
            default => TipoNodoComercial::Ruta,
        };
    }
}
