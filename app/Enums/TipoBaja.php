<?php

namespace App\Enums;

/**
 * Clasificación de una baja de colaborador (solicitud tipo
 * `baja_colaborador`, ver docs/SOLICITUDES_UNIFICADAS.md), capturada al
 * crear la solicitud para no tener que adivinar el motivo a partir de
 * texto libre en reportes futuros.
 */
enum TipoBaja: string
{
    case Renuncia = 'renuncia';
    case Despido = 'despido';
    case MutuoAcuerdo = 'mutuo_acuerdo';
    case FinContrato = 'fin_contrato';
    case Abandono = 'abandono';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Renuncia => 'Renuncia voluntaria',
            self::Despido => 'Despido',
            self::MutuoAcuerdo => 'Mutuo acuerdo',
            self::FinContrato => 'Fin de contrato',
            self::Abandono => 'Abandono de empleo',
            self::Otro => 'Otro',
        };
    }
}
