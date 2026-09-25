<?php

namespace App\Enums;

/**
 * Clasificación de un gasto de reclutamiento según ANSI/SHRM 06001.2012
 * (costo por contratación = costos internos + externos / contrataciones).
 */
enum TipoCostoReclutamiento: string
{
    case Publicidad = 'publicidad';
    case BolsaTrabajo = 'bolsa_trabajo';
    case Agencia = 'agencia';
    case Evaluaciones = 'evaluaciones';
    case BonoReferido = 'bono_referido';
    case EntrevistasViaticos = 'entrevistas_viaticos';
    case PersonalReclutamiento = 'personal_reclutamiento';
    case Software = 'software';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Publicidad => 'Publicidad / anuncios',
            self::BolsaTrabajo => 'Bolsa de trabajo',
            self::Agencia => 'Agencia / headhunter',
            self::Evaluaciones => 'Evaluaciones (exámenes, estudio socioeconómico, antecedentes)',
            self::BonoReferido => 'Bono por referido',
            self::EntrevistasViaticos => 'Entrevistas y viáticos',
            self::PersonalReclutamiento => 'Tiempo del personal de reclutamiento (prorrateado)',
            self::Software => 'Software de reclutamiento',
            self::Otro => 'Otro',
        };
    }

    /**
     * Interno = lo que la empresa paga dentro (personal, bonos, entrevistas,
     * software); externo = pagos a terceros (anuncios, bolsas, agencias,
     * evaluaciones).
     */
    public function esInterno(): bool
    {
        return in_array($this, [self::BonoReferido, self::EntrevistasViaticos, self::PersonalReclutamiento, self::Software], true);
    }

    /**
     * @return list<array{value: string, etiqueta: string, interno: bool}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta(), 'interno' => $t->esInterno()], self::cases());
    }
}
