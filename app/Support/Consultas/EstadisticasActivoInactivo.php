<?php

namespace App\Support\Consultas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tarjetas "Total / Activos / Inactivos" de los catálogos de
 * Administración (empresas, sucursales, departamentos, puestos) en UNA
 * consulta agregada, en vez de tres count() por visita. Respeta los scopes
 * del query recibido (SoftDeletes incluido).
 */
class EstadisticasActivoInactivo
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{total: int, activos: int, inactivos: int}
     */
    public static function de(Builder $query): array
    {
        $fila = $query->toBase()
            ->selectRaw('count(*) as total, sum(case when activo = 1 then 1 else 0 end) as activos, sum(case when activo = 0 then 1 else 0 end) as inactivos')
            ->first();

        return [
            'total' => (int) ($fila->total ?? 0),
            'activos' => (int) ($fila->activos ?? 0),
            'inactivos' => (int) ($fila->inactivos ?? 0),
        ];
    }
}
