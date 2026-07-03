<?php

namespace App\Enums;

/**
 * La asociación automática con un colaborador (User) solo ocurre con
 * coincidencia confiable de correo electrónico. Nunca por nombre. Un
 * participante anónimo, externo sin correo disponible, o con una
 * coincidencia ambigua siempre queda en `PendienteRevision` — nunca se
 * adivina. Ver App\Services\Reuniones\AsociadorParticipanteService y
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 4.
 */
enum EstadoIdentificacionParticipante: string
{
    case Identificado = 'identificado';
    case PendienteRevision = 'pendiente_revision';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Identificado => 'Identificado por correo',
            self::PendienteRevision => 'Pendiente de revisión',
        };
    }
}
