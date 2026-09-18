<?php

namespace App\Enums;

/**
 * Canal de una campaña de gasto de reclutamiento (App\Models\CampanaReclutamiento).
 * Concepto relacionado pero distinto de `Candidato::$fuente` (texto libre
 * capturado por RH al registrar un candidato): aquí el listado es cerrado
 * porque representa dónde se pagó la campaña, no de dónde dijo venir el
 * candidato.
 */
enum CanalReclutamiento: string
{
    case Meta = 'meta';
    case Indeed = 'indeed';
    case Computrabajo = 'computrabajo';
    case LinkedIn = 'linkedin';
    case Referidos = 'referidos';
    case Otros = 'otros';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Meta => 'Meta Ads',
            self::Indeed => 'Indeed',
            self::Computrabajo => 'Computrabajo',
            self::LinkedIn => 'LinkedIn',
            self::Referidos => 'Referidos',
            self::Otros => 'Otros',
        };
    }
}
