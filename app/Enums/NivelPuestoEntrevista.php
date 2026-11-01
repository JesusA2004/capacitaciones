<?php

namespace App\Enums;

/**
 * Nivel del puesto al que aspira un candidato: decide quién aprueba su entrevista
 * (App\Services\Reclutamiento\AprobadorEntrevistaService).
 */
enum NivelPuestoEntrevista: string
{
    case Gestor = 'gestor';
    /** Subgerente o Gerente de Sucursal. */
    case Gerencial = 'gerencial';
    /** Subgerente o Gerente Regional. */
    case Regional = 'regional';
    /** Fuera de la línea comercial (Coordinadora, puestos corporativos…). */
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Gestor => 'Gerente de sucursal',
            self::Gerencial => 'Gerente o Subgerente Regional, o Dirección Comercial',
            self::Regional => 'Dirección Comercial',
            self::Otro => 'Responsable de la entrevista',
        };
    }

    /** Lo revisa Dirección Comercial: Subgerentes y Gerentes, de sucursal y regionales. */
    public function loRevisaDireccionComercial(): bool
    {
        return $this === self::Gerencial || $this === self::Regional;
    }
}
