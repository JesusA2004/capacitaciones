<?php

namespace App\Enums;

/**
 * «Asignar a» en Onboarding (inducción y descriptivo de puesto) — ver
 * App\Services\Onboarding\DestinatariosOnboardingService.
 */
enum ModoDestinatariosOnboarding: string
{
    // Todos los colaboradores activos dentro del alcance de quien asigna.
    case Todos = 'todos';
    // Una persona o una lista de personas elegidas en el buscador.
    case Personas = 'personas';
    // Todos los colaboradores activos de una o varias sucursales.
    case Sucursales = 'sucursales';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Todos => 'Todos',
            self::Personas => 'Personas',
            self::Sucursales => 'Por sucursal',
        };
    }
}
