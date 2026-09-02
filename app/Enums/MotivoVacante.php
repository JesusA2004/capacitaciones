<?php

namespace App\Enums;

enum MotivoVacante: string
{
    case NuevaPosicion = 'nueva_posicion';
    case BajaColaborador = 'baja_colaborador';
    case Promocion = 'promocion';
    case Reemplazo = 'reemplazo';
    case Crecimiento = 'crecimiento';
    case CoberturaTemporal = 'cobertura_temporal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::NuevaPosicion => 'Nueva posición',
            self::BajaColaborador => 'Baja de colaborador',
            self::Promocion => 'Promoción',
            self::Reemplazo => 'Reemplazo',
            self::Crecimiento => 'Crecimiento',
            self::CoberturaTemporal => 'Cobertura temporal',
        };
    }
}
