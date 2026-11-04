<?php

namespace App\Enums;

/**
 * Qué contratación originó el aviso a Sistemas: define la clave única
 * (origen_tipo + origen_id) que hace idempotente el envío.
 */
enum OrigenAvisoAltaSistemas: string
{
    /** Candidato que entró a la fase 6 «Contratación» (origen_id = candidato). */
    case Candidato = 'candidato';

    /** Reingreso autorizado por RH (origen_id = reingreso). */
    case Reingreso = 'reingreso';

    /** Reenvío manual de un colaborador sin aviso previo (origen_id = colaborador). */
    case Colaborador = 'colaborador';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Candidato => 'Candidato nuevo',
            self::Reingreso => 'Reingreso',
            self::Colaborador => 'Reenvío manual',
        };
    }
}
