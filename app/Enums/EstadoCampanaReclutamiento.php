<?php

namespace App\Enums;

use App\Models\CampanaReclutamiento;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Estado de una campaña de reclutamiento. No es una columna: se deriva de
 * su vigencia (fecha_inicio/fecha_fin) contra el día de hoy. Una campaña
 * sin fecha de inicio (histórico capturado solo por mes/año) se evalúa por
 * ese mes completo.
 */
enum EstadoCampanaReclutamiento: string
{
    case Programada = 'programada';
    case Vigente = 'vigente';
    case Concluida = 'concluida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Programada => 'Programada (aún no inicia)',
            self::Vigente => 'Vigente',
            self::Concluida => 'Concluida',
        };
    }

    public static function de(CampanaReclutamiento $campana, ?CarbonInterface $hoy = null): self
    {
        $hoy = ($hoy ?? Carbon::today())->copy()->startOfDay();

        if ($campana->fecha_inicio === null) {
            $clave = $campana->anio * 100 + $campana->mes;
            $claveHoy = (int) $hoy->format('Y') * 100 + (int) $hoy->format('n');

            return match (true) {
                $clave > $claveHoy => self::Programada,
                $clave < $claveHoy => self::Concluida,
                default => self::Vigente,
            };
        }

        if ($campana->fecha_inicio->copy()->startOfDay()->gt($hoy)) {
            return self::Programada;
        }

        return $campana->fecha_fin !== null && $campana->fecha_fin->copy()->startOfDay()->lt($hoy)
            ? self::Concluida
            : self::Vigente;
    }

    /**
     * @return list<array{value: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], self::cases());
    }
}
