<?php

namespace App\Enums;

/**
 * Accesorios y documentos entregados con el vehículo (sección 4 de la
 * «RESPONSIVA DE VEHÍCULO»). Lista de selección múltiple; solo deja
 * constancia (no es inventario).
 */
enum AccesorioVehiculo: string
{
    case Llaves = 'llaves';
    case LlantaRefaccion = 'llanta_refaccion';
    case Gato = 'gato';
    case Herramienta = 'herramienta';
    case Extintor = 'extintor';
    case TarjetaCirculacion = 'tarjeta_circulacion';
    case Poliza = 'poliza';
    case Otro = 'otro';
    case Ninguno = 'ninguno';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Llaves => 'Llaves',
            self::LlantaRefaccion => 'Llanta de refacción',
            self::Gato => 'Gato',
            self::Herramienta => 'Herramienta',
            self::Extintor => 'Extintor',
            self::TarjetaCirculacion => 'Tarjeta de circulación',
            self::Poliza => 'Póliza / seguro',
            self::Otro => 'Otro',
            self::Ninguno => 'Ninguno',
        };
    }
}
