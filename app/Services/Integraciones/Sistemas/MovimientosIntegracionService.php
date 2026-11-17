<?php

namespace App\Services\Integraciones\Sistemas;

use App\Enums\ClasificacionSalida;
use App\Enums\FormaSalida;
use App\Enums\RangoAntiguedad;
use App\Enums\TipoContratacion;
use App\Models\CausalAnalitica;
use App\Models\Colaborador;
use App\Services\Rotacion\FiltrosRotacion;
use App\Services\Rotacion\RotacionMotorService;
use App\Support\FechaMexico;
use App\Support\NombrePersona;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Altas y bajas por rango de fechas para la API multi-sistema.
 *
 *  - ALTA = colaborador (incluidos los dados de baja) con fecha de ingreso en
 *    el rango, en una sucursal visible para el cliente: la misma población con
 *    la que PlantillaMensualService reconstruye la plantilla por fechas.
 *  - BAJA = evento de rotación de RotacionMotorService (fuente única: bajas
 *    del sistema que cuentan como rotación + bajas históricas válidas, sin
 *    duplicados). Nunca se recalcula aquí qué cuenta como baja. El motivo en
 *    texto libre no sale: sólo forma de salida, clasificación y causal
 *    homologada (catálogos cerrados).
 *
 * Las bajas de unidades externas (sin sucursal en People) no salen: no
 * tienen sucursal con la cual emparejarse.
 */
class MovimientosIntegracionService
{
    public function __construct(
        private readonly RotacionMotorService $motor,
        private readonly CatalogosIntegracionService $catalogos,
    ) {}

    /**
     * @param  Collection<int, int>  $sucursales
     * @return list<array<string, mixed>>
     */
    public function altas(Collection $sucursales, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        if ($sucursales->isEmpty()) {
            return [];
        }

        $referencias = $this->catalogos->referenciasSucursales($sucursales);

        return array_values(Colaborador::query()->withTrashed()
            ->with(['puesto:id,nombre,departamento_id', 'puesto.departamento:id,nombre'])
            ->whereIn('sucursal_principal_id', $sucursales->all())
            ->whereNotNull('fecha_ingreso')
            ->whereDate('fecha_ingreso', '>=', $desde->toDateString())
            ->whereDate('fecha_ingreso', '<=', $hasta->toDateString())
            ->orderBy('fecha_ingreso')
            ->orderBy('id')
            ->get(['id', 'numero_empleado', 'name', 'apellidos', 'sucursal_principal_id', 'puesto_id', 'tipo_contratacion', 'fecha_ingreso', 'fecha_baja', 'estatus', 'deleted_at'])
            ->map(fn (Colaborador $c): array => [
                'tipo' => 'alta',
                'colaborador_id' => $c->id,
                'numero_empleado' => $c->numero_empleado,
                'nombre_completo' => NombrePersona::completo($c->name, $c->apellidos),
                'fecha' => $c->fecha_ingreso?->format('Y-m-d'),
                'fecha_ingreso' => $c->fecha_ingreso?->format('Y-m-d'),
                'fecha_baja' => $c->fecha_baja?->format('Y-m-d'),
                'sucursal' => $referencias[(int) $c->sucursal_principal_id] ?? null,
                'puesto' => $c->puesto !== null ? ['id' => $c->puesto->id, 'nombre' => $c->puesto->nombre] : null,
                'departamento' => $c->puesto?->departamento !== null ? ['id' => $c->puesto->departamento->id, 'nombre' => $c->puesto->departamento->nombre] : null,
                'tipo_contratacion' => $c->tipo_contratacion instanceof TipoContratacion ? ['valor' => $c->tipo_contratacion->value, 'etiqueta' => $c->tipo_contratacion->etiqueta()] : null,
                'estatus' => ['valor' => $c->estatus->value, 'etiqueta' => $c->estatus->etiqueta()],
            ])
            ->all());
    }

    /**
     * @param  Collection<int, int>  $sucursales
     * @return list<array<string, mixed>>
     */
    public function bajas(Collection $sucursales, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $eventos = $this->eventos($sucursales, $desde, $hasta);

        if ($eventos->isEmpty()) {
            return [];
        }

        $referencias = $this->catalogos->referenciasSucursales($eventos->pluck('sucursal_id')->map(fn ($id): int => (int) $id)->unique()->values());
        $analiticas = $this->motor->analiticas();

        return array_values($eventos
            ->sortBy([['fecha_baja_texto', 'asc'], ['clave', 'asc']])
            ->map(function (array $e) use ($referencias, $analiticas): array {
                $forma = is_string($e['forma']) ? FormaSalida::tryFrom($e['forma']) : null;
                $clasificacion = $e['clasificacion'] instanceof ClasificacionSalida ? $e['clasificacion'] : ClasificacionSalida::SinClasificar;
                $rango = $e['rango'] instanceof RangoAntiguedad ? $e['rango'] : null;
                $causal = is_int($e['causal_analitica_id']) ? $analiticas->get($e['causal_analitica_id']) : null;

                return [
                    'tipo' => 'baja',
                    'clave' => $e['clave'],
                    'fuente' => $e['fuente'],
                    'colaborador_id' => $e['colaborador_id'],
                    'numero_empleado' => $e['numero_empleado'],
                    'nombre_completo' => $e['nombre'],
                    'fecha' => $e['fecha_baja_texto'],
                    'fecha_ingreso' => $e['fecha_ingreso'] instanceof CarbonImmutable ? $e['fecha_ingreso']->toDateString() : null,
                    'fecha_baja' => $e['fecha_baja_texto'],
                    'sucursal' => $referencias[(int) $e['sucursal_id']] ?? null,
                    'puesto_id' => $e['puesto_id'],
                    'departamento_id' => $e['departamento_id'],
                    'forma_salida' => $forma !== null ? ['valor' => $forma->value, 'etiqueta' => $forma->etiqueta()] : null,
                    'clasificacion' => ['valor' => $clasificacion->value, 'etiqueta' => $clasificacion->etiqueta()],
                    'causal' => $causal instanceof CausalAnalitica ? ['id' => $causal->id, 'clave' => $causal->clave, 'nombre' => $causal->nombre, 'homologada' => (bool) $e['homologada']] : null,
                    'antiguedad_dias' => $e['antiguedad_dias'],
                    'rango_antiguedad' => $rango !== null ? ['valor' => $rango->value, 'etiqueta' => $rango->etiqueta()] : null,
                ];
            })
            ->all());
    }

    /**
     * Eventos de rotación del motor único en el rango y sucursales (sin
     * unidades externas). Público para RotacionIntegracionService: altas,
     * bajas y rotación salen de la misma lista.
     *
     * @param  Collection<int, int>  $sucursales
     * @return Collection<int, array<string, mixed>>
     */
    public function eventos(Collection $sucursales, CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        $hoy = FechaMexico::inicioDeHoyInmutable();

        if ($sucursales->isEmpty() || $desde->gt($hoy)) {
            return collect();
        }

        $filtros = new FiltrosRotacion('rango', $desde->year, null, $desde, $hasta->min($hoy), $sucursales, null, null, null, null, null, null, null, 'todas', $hasta->gt($hoy));

        return $this->motor->eventos($filtros, false)
            ->filter(fn (array $e): bool => (int) $e['sucursal_id'] > 0)
            ->values();
    }
}
