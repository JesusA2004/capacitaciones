<?php

namespace App\Services\RecepcionDocumental;

use App\Models\RecepcionDocumentalCorte;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Mes calendario de un corte de Recepción documental. Nunca se asume la
 * duración: inicio = startOfMonth, fin = endOfMonth (28, 29, 30 o 31 días).
 *
 * Dos tipos de columna, dos rangos:
 *  - DATE (fecha_ingreso, fecha_baja, fecha_pago…): fecha calendario tal
 *    cual, sin zona → rangoFechas().
 *  - TIMESTAMP guardados en UTC (created_at, autorizado_en, revisado_en…):
 *    el mes se decide en hora de México (America/Mexico_City). Un evento a
 *    las 23:30 del último día en CDMX ya es el día 1 en UTC, pero cuenta en
 *    ESTE mes → rangoUtc().
 */
final class PeriodoRecepcion
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private function __construct(public readonly int $anio, public readonly int $mes) {}

    public static function de(int $anio, int $mes): self
    {
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            throw new InvalidArgumentException(sprintf('Periodo inválido: %d-%d.', $anio, $mes));
        }

        return new self($anio, $mes);
    }

    /** «2026-11» → noviembre de 2026. */
    public static function desdeClave(string $clave): self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $clave, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Periodo inválido: %s (usa AAAA-MM).', $clave));
        }

        return self::de((int) $m[1], (int) $m[2]);
    }

    /** El mes en curso en México (no en UTC). */
    public static function actual(): self
    {
        $hoy = CarbonImmutable::now(FechaMexico::ZONA);

        return self::de($hoy->year, $hoy->month);
    }

    public static function delCorte(RecepcionDocumentalCorte $corte): self
    {
        return self::de($corte->anio, $corte->mes);
    }

    public static function esClaveValida(string $clave): bool
    {
        try {
            self::desdeClave($clave);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function clave(): string
    {
        return sprintf('%04d-%02d', $this->anio, $this->mes);
    }

    /** Primer día del mes (fecha calendario). */
    public function inicio(): CarbonImmutable
    {
        return CarbonImmutable::create($this->anio, $this->mes, 1, 0, 0, 0, 'UTC')->startOfMonth();
    }

    /** Último día del mes (fecha calendario): 28, 29, 30 o 31. */
    public function fin(): CarbonImmutable
    {
        return $this->inicio()->endOfMonth()->startOfDay();
    }

    public function dias(): int
    {
        return $this->fin()->day;
    }

    /**
     * Para columnas DATE: [«AAAA-MM-01 00:00:00», «AAAA-MM-último 23:59:59»]
     * (funciona igual en MariaDB y en SQLite, donde el cast `date` guarda la hora).
     *
     * @return array{0: string, 1: string}
     */
    public function rangoFechas(): array
    {
        return [
            sprintf('%s 00:00:00', $this->inicio()->toDateString()),
            sprintf('%s 23:59:59', $this->fin()->toDateString()),
        ];
    }

    /**
     * Para TIMESTAMPS en UTC: del 1 a las 00:00 de México al último día a
     * las 23:59:59 de México, expresados en UTC.
     *
     * @return array{0: string, 1: string}
     */
    public function rangoUtc(): array
    {
        $inicio = CarbonImmutable::create($this->anio, $this->mes, 1, 0, 0, 0, FechaMexico::ZONA);

        return [
            $inicio->utc()->format('Y-m-d H:i:s'),
            $inicio->endOfMonth()->endOfDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** Fecha calendario en México de un timestamp (UTC). */
    public static function fechaMexico(?CarbonInterface $instante): ?string
    {
        return $instante === null ? null : CarbonImmutable::instance($instante)->setTimezone(FechaMexico::ZONA)->toDateString();
    }

    /** «noviembre 2026». */
    public function etiqueta(): string
    {
        return sprintf('%s %d', self::MESES[$this->mes - 1], $this->anio);
    }

    /** El mes ya terminó en México (hoy es posterior a su último día). */
    public function haTerminado(): bool
    {
        return FechaMexico::hoy() > $this->fin()->toDateString();
    }

    /** El mes todavía no empieza en México. */
    public function esFuturo(): bool
    {
        return FechaMexico::hoy() < $this->inicio()->toDateString();
    }
}
