<?php

namespace App\Enums;

/**
 * Modalidad de contratación vigente de un colaborador (ver
 * App\Services\Contratos\ContratoLaboralService). Determina qué paquete de
 * documentos contractuales se prepara al darlo de alta
 * (config/contratos.php → paquetes_alta).
 */
enum TipoContratacion: string
{
    case PeriodoPrueba = 'periodo_prueba';
    case CapacitacionInicial = 'capacitacion_inicial';
    case TiempoDeterminado = 'tiempo_determinado';
    case Indeterminado = 'indeterminado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PeriodoPrueba => 'Periodo de prueba',
            self::CapacitacionInicial => 'Capacitación inicial',
            self::TiempoDeterminado => 'Tiempo determinado',
            self::Indeterminado => 'Tiempo indeterminado',
        };
    }

    /**
     * true si la modalidad tiene fecha de vencimiento (y por lo tanto entra
     * al control automático de vencimientos/evaluación).
     */
    public function tieneVencimiento(): bool
    {
        return $this !== self::Indeterminado;
    }
}
