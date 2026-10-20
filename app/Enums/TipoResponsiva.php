<?php

namespace App\Enums;

/**
 * Las tres familias oficiales de responsiva (docs/responsiva/*.docx):
 * bien mueble, equipo electrónico y vehículo. Cada familia tiene sus propios
 * campos legales (ver App\Http\Requests\Rh\GuardarResponsivaRequest y
 * App\Services\Responsivas\ResponsivaService), pero comparten el mismo
 * encabezado, fundamento (artículos 132, 134 y 135 de la Ley Federal del
 * Trabajo), declaración y firmas (razón social del patrón con «Representante
 * legal» bajo la línea / trabajador).
 */
enum TipoResponsiva: string
{
    case BienMueble = 'bien_mueble';
    case EquipoElectronico = 'equipo_electronico';
    case Vehiculo = 'vehiculo';

    /** Nombre de la familia para la interfaz (selector, listados). */
    public function etiqueta(): string
    {
        return match ($this) {
            self::BienMueble => 'Bien mueble',
            self::EquipoElectronico => 'Equipo electrónico',
            self::Vehiculo => 'Vehículo',
        };
    }

    /** Sustantivo que usa el texto legal de la responsiva («el equipo electrónico»). */
    public function denominacion(): string
    {
        return match ($this) {
            self::BienMueble => 'bien mueble',
            self::EquipoElectronico => 'equipo electrónico',
            self::Vehiculo => 'vehículo',
        };
    }

    /** Título del documento, tal cual aparece en el formato oficial (DOCX). */
    public function titulo(): string
    {
        return match ($this) {
            self::BienMueble => 'RESPONSIVA DE BIEN MUEBLE',
            self::EquipoElectronico => 'RESPONSIVA DE EQUIPO ELECTRONICO',
            self::Vehiculo => 'RESPONSIVA DE VEHÍCULO',
        };
    }
}
