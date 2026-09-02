<?php

namespace App\Enums;

enum EstadoAltaDigital: string
{
    case Creada = 'creada';
    case Enviada = 'enviada';
    case EnCaptura = 'en_captura';
    case EnviadaPorCandidato = 'enviada_por_candidato';
    case EnRevisionRh = 'en_revision_rh';
    case RequiereCorreccion = 'requiere_correccion';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
    case ConvertidaAColaborador = 'convertida_a_colaborador';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Creada => 'Creada',
            self::Enviada => 'Enviada',
            self::EnCaptura => 'En captura',
            self::EnviadaPorCandidato => 'Enviada por el candidato',
            self::EnRevisionRh => 'En revisión de RH',
            self::RequiereCorreccion => 'Requiere corrección',
            self::Aprobada => 'Aprobada',
            self::Rechazada => 'Rechazada',
            self::ConvertidaAColaborador => 'Convertida a colaborador',
            self::Cancelada => 'Cancelada',
        };
    }

    /**
     * Estados en los que la liga publica sigue aceptando captura del candidato.
     */
    public function permiteCaptura(): bool
    {
        return in_array($this, [self::Enviada, self::EnCaptura, self::RequiereCorreccion], true);
    }
}
