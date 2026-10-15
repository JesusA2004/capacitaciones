<?php

namespace App\Enums;

/**
 * Qué fecha filtra el rango del reporte de campañas de reclutamiento
 * (App\Services\Reclutamiento\ReporteCampanasService).
 */
enum CriterioFechaReporteCampanas: string
{
    /** Campañas cuya vigencia (inicio–fin) se cruza con el rango; todos sus candidatos. */
    case Vigencia = 'vigencia';

    /** Solo los candidatos registrados dentro del rango (y las campañas de las que llegaron). */
    case Registro = 'registro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vigencia => 'Campañas vigentes en el rango',
            self::Registro => 'Candidatos registrados en el rango',
        };
    }

    public function explicacion(): string
    {
        return match ($this) {
            self::Vigencia => 'Entran las campañas cuya vigencia (inicio–fin) se cruza con el rango, con todos sus candidatos. Una campaña sin fecha de fin sigue vigente; una sin fecha de inicio cuenta por su mes.',
            self::Registro => 'Entran solo los candidatos que RH registró dentro del rango y las campañas de las que llegaron. El gasto de cada campaña se muestra completo.',
        };
    }

    /**
     * @return list<array{value: string, etiqueta: string, explicacion: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'etiqueta' => $c->etiqueta(), 'explicacion' => $c->explicacion()], self::cases());
    }
}
