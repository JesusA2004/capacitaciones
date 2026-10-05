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

    /**
     * Las únicas modalidades que se ofrecen al contratar: la etapa inicial
     * (capacitación inicial = periodo de prueba, es lo mismo) y, al
     * aprobarla, tiempo indeterminado. Los demás casos solo existen para
     * leer historial.
     *
     * @return list<self>
     */
    public static function seleccionables(): array
    {
        return [self::CapacitacionInicial, self::Indeterminado];
    }

    /**
     * Periodo de prueba y capacitación inicial son la misma etapa: todo
     * contrato nuevo de "periodo de prueba" nace como capacitación inicial.
     */
    public function normalizada(): self
    {
        return $this === self::PeriodoPrueba ? self::CapacitacionInicial : $this;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::PeriodoPrueba => 'Capacitación inicial (periodo de prueba)',
            self::CapacitacionInicial => 'Capacitación inicial (periodo de prueba)',
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
