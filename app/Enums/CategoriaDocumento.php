<?php

namespace App\Enums;

/**
 * Categoría documental del expediente. Define la subcarpeta física en el
 * NAS (ver App\Services\Expedientes\DocumentoStorageService::rutaDocumento())
 * y la agrupación del expediente en web/API.
 */
enum CategoriaDocumento: string
{
    case Personales = 'personales';
    case Contratos = 'contratos';
    case Vacaciones = 'vacaciones';
    case Permisos = 'permisos';
    case Prestamos = 'prestamos';
    case Actas = 'actas';
    case NominaInterna = 'nomina_interna';
    case BajaFiniquito = 'baja_finiquito';
    case Otros = 'otros';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Personales => 'Personales',
            self::Contratos => 'Contratos',
            self::Vacaciones => 'Vacaciones',
            self::Permisos => 'Permisos',
            self::Prestamos => 'Préstamos',
            self::Actas => 'Actas',
            self::NominaInterna => 'Nómina interna',
            self::BajaFiniquito => 'Baja y finiquito',
            self::Otros => 'Otros',
        };
    }

    /**
     * Nombre de la subcarpeta física (sin acentos ni espacios).
     */
    public function carpeta(): string
    {
        return match ($this) {
            self::Personales => 'Personales',
            self::Contratos => 'Contratos',
            self::Vacaciones => 'Vacaciones',
            self::Permisos => 'Permisos',
            self::Prestamos => 'Prestamos',
            self::Actas => 'Actas',
            self::NominaInterna => 'NominaInterna',
            self::BajaFiniquito => 'BajaFiniquito',
            self::Otros => 'Otros',
        };
    }
}
