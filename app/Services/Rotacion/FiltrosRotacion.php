<?php

namespace App\Services\Rotacion;

use App\Enums\ClasificacionSalida;
use App\Enums\RangoAntiguedad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Filtros ya validados y acotados al alcance de quien consulta. Los usa todo
 * el módulo de Rotación (tablero, histórico, exportaciones): una sola forma de
 * leer «qué se pidió», así pantalla y archivo dan la misma cifra.
 *
 * Los filtros de DETALLE (puesto, área, tipo de salida, causal, antigüedad)
 * solo pueden aplicarse a bajas individuales: un mes que solo tiene el total
 * agregado del Excel no se puede desglosar y se reporta como «no disponible»,
 * nunca como cero.
 */
final class FiltrosRotacion
{
    /**
     * @param  Collection<int, int>  $sucursales  Ids dentro del alcance (y de la empresa/sucursal elegida).
     * @param  'acumulado'|'anio'|'mes'|'rango'  $periodo
     * @param  'todas'|'sistema'|'historico'  $fuente
     */
    public function __construct(
        public readonly string $periodo,
        public readonly int $anio,
        public readonly ?int $mes,
        public readonly CarbonImmutable $desde,
        public readonly CarbonImmutable $hasta,
        public readonly Collection $sucursales,
        public readonly ?int $empresaId,
        public readonly ?int $sucursalId,
        public readonly ?int $departamentoId,
        public readonly ?int $puestoId,
        public readonly ?ClasificacionSalida $clasificacion,
        public readonly ?int $causalAnaliticaId,
        public readonly ?RangoAntiguedad $rango,
        public readonly string $fuente,
        public readonly bool $corteParcial,
        // Incluir las bajas históricas de unidades externas (sin sucursal en
        // People): solo con alcance global y sin empresa ni sucursal elegida.
        public readonly bool $externas = false,
    ) {}

    /** Hay algún filtro que solo las bajas individuales pueden responder. */
    public function conDetalle(): bool
    {
        return $this->departamentoId !== null
            || $this->puestoId !== null
            || $this->clasificacion !== null
            || $this->causalAnaliticaId !== null
            || $this->rango !== null;
    }

    /** Los agregados del Excel entran solo si la fuente lo permite. */
    public function incluyeAgregados(): bool
    {
        return $this->fuente === 'todas' || $this->fuente === 'historico';
    }

    /**
     * Mismo periodo y alcance, sin los filtros de detalle (para comparar o
     * para totales de referencia).
     */
    public function sinDetalle(): self
    {
        return new self($this->periodo, $this->anio, $this->mes, $this->desde, $this->hasta, $this->sucursales, $this->empresaId, $this->sucursalId, null, null, null, null, null, $this->fuente, $this->corteParcial, $this->externas);
    }

    /**
     * El mismo filtro con otro rango de fechas (periodo anterior, un mes).
     */
    public function conRango(CarbonImmutable $desde, CarbonImmutable $hasta): self
    {
        return new self($this->periodo, $this->anio, $this->mes, $desde, $hasta, $this->sucursales, $this->empresaId, $this->sucursalId, $this->departamentoId, $this->puestoId, $this->clasificacion, $this->causalAnaliticaId, $this->rango, $this->fuente, $this->corteParcial, $this->externas);
    }

    /**
     * Otras sucursales (p. ej. una sola): sin unidades externas.
     *
     * @param  Collection<int, int>  $sucursales
     */
    public function conSucursales(Collection $sucursales): self
    {
        return new self($this->periodo, $this->anio, $this->mes, $this->desde, $this->hasta, $sucursales, $this->empresaId, $this->sucursalId, $this->departamentoId, $this->puestoId, $this->clasificacion, $this->causalAnaliticaId, $this->rango, $this->fuente, $this->corteParcial);
    }

    /**
     * Lo que la pantalla necesita para volver a pintar los filtros y para los
     * enlaces (mismos nombres que la query string).
     *
     * @return array<string, int|string|bool|null>
     */
    public function aArray(): array
    {
        return [
            'periodo' => $this->periodo,
            'anio' => $this->anio,
            'mes' => $this->mes,
            'desde' => $this->desde->toDateString(),
            'hasta' => $this->hasta->toDateString(),
            'empresa_id' => $this->empresaId,
            'sucursal_id' => $this->sucursalId,
            'departamento_id' => $this->departamentoId,
            'puesto_id' => $this->puestoId,
            'clasificacion' => $this->clasificacion?->value,
            'causal_analitica_id' => $this->causalAnaliticaId,
            'antiguedad' => $this->rango?->value,
            'fuente' => $this->fuente,
            'corte_parcial' => $this->corteParcial,
        ];
    }
}
