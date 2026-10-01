<?php

namespace App\Enums;

/**
 * Agrupación de puestos para indicadores ("tiempo de contratación por
 * nivel"). Se asigna por puesto en el catálogo (puestos.grupo_indicador),
 * nunca se infiere del texto del nombre del puesto.
 */
enum GrupoPuestoIndicador: string
{
    case Gestores = 'gestores';
    case Coordinadoras = 'coordinadoras';
    case GerenciaSucursal = 'gerencia_sucursal';
    case Regionales = 'regionales';
    case DireccionComercial = 'direccion_comercial';
    case Otros = 'otros';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Gestores => 'Gestores',
            self::Coordinadoras => 'Coordinadoras',
            self::GerenciaSucursal => 'Ger./Subger.',
            self::Regionales => 'Regionales',
            self::DireccionComercial => 'Dir. comercial',
            self::Otros => 'Otros',
        };
    }
}
