<?php

namespace App\Services\Integraciones\Sistemas;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Periodos de la rotación para otros sistemas, compatibles con Reportes:
 *
 *  - `rango`: un solo periodo [desde, hasta] (p. ej. el start_date/end_date
 *    de un periodo de Reportes, semanal o mensual de 4 semanas).
 *  - `semanal`: semanas operativas lunes-domingo; `desde` debe ser lunes.
 *  - `bloques_4_semanas`: bloques de 28 días desde `desde` (lunes), como los
 *    meses operativos de Reportes.
 *  - `mes_calendario`: meses naturales; `desde` debe ser día 1. Usa las cifras
 *    OFICIALES del Tablero de Rotación de People.
 *
 * El último periodo puede quedar incompleto si `hasta` lo corta
 * (`completo=false`); ninguno empieza después de `hasta`.
 */
final class PeriodosIntegracion
{
    public const RANGO = 'rango';

    public const SEMANAL = 'semanal';

    public const BLOQUES_4_SEMANAS = 'bloques_4_semanas';

    public const MES_CALENDARIO = 'mes_calendario';

    public const AGRUPACIONES = [self::RANGO, self::SEMANAL, self::BLOQUES_4_SEMANAS, self::MES_CALENDARIO];

    private const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /**
     * @return list<array{clave: string, etiqueta: string, inicio: CarbonImmutable, fin: CarbonImmutable, completo: bool}>
     *
     * @throws InvalidArgumentException con el mensaje para el campo que no cuadra
     */
    public static function generar(string $agrupacion, CarbonImmutable $desde, CarbonImmutable $hasta, int $maximo): array
    {
        $desde = $desde->startOfDay();
        $hasta = $hasta->startOfDay();

        if ($agrupacion === self::RANGO) {
            return [[
                'clave' => sprintf('R-%s_%s', $desde->toDateString(), $hasta->toDateString()),
                'etiqueta' => sprintf('Del %s al %s', $desde->format('d/m/Y'), $hasta->format('d/m/Y')),
                'inicio' => $desde,
                'fin' => $hasta,
                'completo' => true,
            ]];
        }

        if (in_array($agrupacion, [self::SEMANAL, self::BLOQUES_4_SEMANAS], true) && ! $desde->isMonday()) {
            throw new InvalidArgumentException('Con agrupación semanal o de bloques de 4 semanas, desde debe ser lunes (semana operativa lunes-domingo).');
        }

        if ($agrupacion === self::MES_CALENDARIO && $desde->day !== 1) {
            throw new InvalidArgumentException('Con agrupación mes_calendario, desde debe ser el día 1 del mes.');
        }

        $periodos = [];
        $inicio = $desde;

        while ($inicio->lte($hasta)) {
            $finCompleto = match ($agrupacion) {
                self::SEMANAL => $inicio->addDays(6),
                self::BLOQUES_4_SEMANAS => $inicio->addDays(27),
                default => $inicio->endOfMonth()->startOfDay(),
            };
            $fin = $finCompleto->min($hasta);

            $periodos[] = [
                'clave' => match ($agrupacion) {
                    self::SEMANAL => sprintf('S-%s', $inicio->toDateString()),
                    self::BLOQUES_4_SEMANAS => sprintf('B4-%s', $inicio->toDateString()),
                    default => $inicio->format('Y-m'),
                },
                'etiqueta' => match ($agrupacion) {
                    self::SEMANAL => sprintf('Semana del %s al %s', $inicio->format('d/m/Y'), $finCompleto->format('d/m/Y')),
                    self::BLOQUES_4_SEMANAS => sprintf('4 semanas del %s al %s', $inicio->format('d/m/Y'), $finCompleto->format('d/m/Y')),
                    default => sprintf('%s %d', self::MESES[$inicio->month], $inicio->year),
                },
                'inicio' => $inicio,
                'fin' => $fin,
                'completo' => $fin->equalTo($finCompleto),
            ];

            if (count($periodos) > $maximo) {
                throw new InvalidArgumentException(sprintf('El rango pide más de %d periodos; acórtalo o usa una agrupación más amplia.', $maximo));
            }

            $inicio = $finCompleto->addDay();
        }

        return $periodos;
    }
}
