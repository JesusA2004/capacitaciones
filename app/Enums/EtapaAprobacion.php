<?php

namespace App\Enums;

/**
 * Las dos etapas de toda aprobación del ciclo laboral: primero el superior
 * operativo PREAUTORIZA y después RH AUTORIZA en definitiva. Preautorizar
 * nunca equivale a autorizar: ningún proceso llega a su efecto final sin la
 * etapa autorizacion_rh. Ver App\Services\CicloLaboral\AprobacionService.
 */
enum EtapaAprobacion: string
{
    case Preautorizacion = 'preautorizacion';
    case AutorizacionRh = 'autorizacion_rh';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Preautorizacion => 'Preautorización operativa',
            self::AutorizacionRh => 'Autorización final de RH',
        };
    }

    public function orden(): int
    {
        return match ($this) {
            self::Preautorizacion => 1,
            self::AutorizacionRh => 2,
        };
    }
}
