<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\EstadoUsuario;
use App\Enums\ModoCalculoRecepcion;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\Colaborador;
use App\Models\RecepcionDocumentalCatalogo;
use App\Models\Sucursal;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Entrada AGREGADA POR RH en el catálogo de «lo que recibo»
 * (docs/RECEPCION_DOCUMENTAL_RH.md → «Agregar lo que debe llegar»). No hay
 * evento propio: lo esperado sale de lo configurado en la entrada.
 *
 *  - Cantidad fija al mes: UN renglón general (sin sucursal) con esa
 *    cantidad; origen = la propia entrada del catálogo.
 *  - Por sucursal: un renglón por sucursal activa (o solo las elegidas) con
 *    esa cantidad; origen = la sucursal.
 *  - Por colaborador activo: un renglón por sucursal con
 *    cantidad × colaboradores activos en el mes (misma definición que las
 *    listas de asistencia: estatus activo, ingreso a más tardar el último
 *    día; con baja, solo si fue a partir del día 1), opcionalmente solo de
 *    ciertas áreas.
 *  - Por evento: por cada evento que la regla base del sistema detecta en
 *    el mes (cada alta, cada baja, cada recibo…) se espera esa cantidad;
 *    origen = el mismo evento, así aparece junto a los demás documentos de
 *    esa persona. En listas de asistencia el evento es la semana.
 *
 * El tipo_documento de sus renglones es la clave de la entrada
 * («personalizado.<slug>», con «.AAAA-MM-DD» en lotes semanales), así la
 * materialización sabe a qué entrada pertenece cada renglón.
 */
class ReglaPersonalizada implements ReglaRecepcion
{
    public function __construct(
        public readonly RecepcionDocumentalCatalogo $entrada,
        private readonly ?ReglaRecepcion $base = null,
    ) {}

    public function regla(): ReglaRecepcionDocumental
    {
        return $this->entrada->bloqueEfectivo();
    }

    public function documentosPosibles(): array
    {
        return [$this->clave() => $this->entrada->nombre];
    }

    public function clave(): string
    {
        return (string) ($this->entrada->clave ?? 'personalizado.nuevo');
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        return match ($this->entrada->modo_calculo) {
            ModoCalculoRecepcion::FijoMensual => [$this->fijo($periodo)],
            ModoCalculoRecepcion::PorSucursal => $this->porSucursal($periodo),
            ModoCalculoRecepcion::PorColaboradorActivo => $this->porColaborador($periodo),
            ModoCalculoRecepcion::PorEvento => $this->porEvento($periodo),
            ModoCalculoRecepcion::Regla => [],
        };
    }

    /**
     * Colaboradores activos en el mes en la sucursal (los nombres que trae
     * el renglón «por colaborador activo»).
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null}>
     */
    public function integrantes(int $sucursalId, PeriodoRecepcion $periodo): array
    {
        if ($this->entrada->modo_calculo !== ModoCalculoRecepcion::PorColaboradorActivo) {
            return [];
        }

        return array_values($this->activos($periodo)
            ->where('sucursal_principal_id', $sucursalId)
            ->sortBy(fn (Colaborador $c): string => $c->nombreCompleto())
            ->map(fn (Colaborador $c): array => ['id' => $c->id, 'nombre' => $c->nombreCompleto(), 'numero_empleado' => $c->numero_empleado])
            ->values()
            ->all());
    }

    private function fijo(PeriodoRecepcion $periodo): EsperadoRecepcion
    {
        $cantidad = $this->entrada->cantidadUnitaria();

        return new EsperadoRecepcion(
            $this->regla(), $this->clave(), $this->entrada->nombre, $this->entrada, null,
            null, null, null, null, null, null,
            $periodo->fin()->toDateString(),
            sprintf('%d papel%s al mes', $cantidad, $cantidad === 1 ? '' : 'es'),
            $cantidad,
        );
    }

    /**
     * @return list<EsperadoRecepcion>
     */
    private function porSucursal(PeriodoRecepcion $periodo): array
    {
        $cantidad = $this->entrada->cantidadUnitaria();
        $esperados = [];

        foreach ($this->sucursales()->get(['id', 'nombre']) as $sucursal) {
            $esperados[] = $this->deSucursal($sucursal, $periodo, $cantidad, sprintf('%d papel%s de la sucursal al mes', $cantidad, $cantidad === 1 ? '' : 'es'));
        }

        return $esperados;
    }

    /**
     * @return list<EsperadoRecepcion>
     */
    private function porColaborador(PeriodoRecepcion $periodo): array
    {
        $porSucursal = $this->activos($periodo)->groupBy('sucursal_principal_id');

        if ($porSucursal->isEmpty()) {
            return [];
        }

        $unitaria = $this->entrada->cantidadUnitaria();
        $esperados = [];

        foreach ($this->sucursales()->whereIn('id', $porSucursal->keys()->map(fn (mixed $id): int => (int) $id)->all())->get(['id', 'nombre']) as $sucursal) {
            $activos = $porSucursal->get($sucursal->id)?->count() ?? 0;

            if ($activos === 0) {
                continue;
            }

            $descripcion = sprintf('%d colaborador%s activo%s × %d', $activos, $activos === 1 ? '' : 'es', $activos === 1 ? '' : 's', $unitaria);
            $esperados[] = $this->deSucursal($sucursal, $periodo, $activos * $unitaria, $descripcion);
        }

        return $esperados;
    }

    /**
     * @return list<EsperadoRecepcion>
     */
    private function porEvento(PeriodoRecepcion $periodo): array
    {
        if ($this->base === null) {
            return [];
        }

        $porSemana = $this->base->regla() === ReglaRecepcionDocumental::ListasAsistencia;
        $departamentos = $this->entrada->departamentos();
        $eventos = [];

        foreach ($this->base->esperados($periodo) as $esperado) {
            $llave = sprintf('%s|%d%s', $esperado->origen->getMorphClass(), (int) $esperado->origen->getKey(), $porSemana ? '|'.$esperado->fechaEvento : '');
            $eventos[$llave] ??= $esperado;
        }

        if ($departamentos !== []) {
            $ids = array_values(array_unique(array_filter(array_map(fn (EsperadoRecepcion $e): ?int => $e->colaboradorId, $eventos))));
            $enAreas = Colaborador::withTrashed()->whereIn('id', $ids)->whereIn('departamento_id', $departamentos)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $eventos = array_filter($eventos, fn (EsperadoRecepcion $e): bool => $e->colaboradorId !== null && in_array($e->colaboradorId, $enAreas, true));
        }

        $cantidad = $this->entrada->cantidadUnitaria();
        $resultado = [];

        foreach ($eventos as $evento) {
            $tipo = $porSemana && $evento->fechaEvento !== null ? sprintf('%s.%s', $this->clave(), $evento->fechaEvento) : $this->clave();

            $resultado[] = new EsperadoRecepcion(
                $this->regla(), $tipo, $this->entrada->nombre, $evento->origen, null,
                $evento->colaboradorId, $evento->sucursalId, $evento->colaboradorNombre, $evento->numeroEmpleado,
                $evento->puestoNombre, $evento->sucursalNombre, $evento->fechaEvento, $evento->descripcion,
                $cantidad, $this->clave(),
            );
        }

        return $resultado;
    }

    private function deSucursal(Sucursal $sucursal, PeriodoRecepcion $periodo, int $cantidad, string $descripcion): EsperadoRecepcion
    {
        return new EsperadoRecepcion(
            $this->regla(), $this->clave(), $this->entrada->nombre, $sucursal, null,
            null, $sucursal->id, null, null, null, $sucursal->nombre,
            $periodo->fin()->toDateString(), $descripcion, $cantidad,
        );
    }

    /**
     * Sucursales activas (el alcance por sucursal/empresa lo aplica después
     * ReglaConfigurada::filtrar, igual que en las reglas del sistema).
     *
     * @return Builder<Sucursal>
     */
    private function sucursales(): Builder
    {
        return Sucursal::query()->where('activo', true)->orderBy('nombre');
    }

    /**
     * Activos en algún día del mes, con sucursal, opcionalmente de ciertas áreas.
     *
     * @return Collection<int, Colaborador>
     */
    private function activos(PeriodoRecepcion $periodo): Collection
    {
        $inicio = $periodo->inicio()->toDateString();
        $fin = $periodo->fin()->toDateString();
        $departamentos = $this->entrada->departamentos();

        return Colaborador::query()
            ->whereNotNull('sucursal_principal_id')
            ->when($departamentos !== [], fn (Builder $q) => $q->whereIn('departamento_id', $departamentos))
            ->where(fn (Builder $q) => $q->whereNull('fecha_ingreso')->orWhereDate('fecha_ingreso', '<=', $fin))
            ->where(fn (Builder $q) => $q->where('estatus', EstadoUsuario::Activo->value)
                ->orWhere(fn (Builder $b) => $b->whereNotNull('fecha_baja')->whereDate('fecha_baja', '>=', $inicio)))
            ->get(['id', 'name', 'apellidos', 'numero_empleado', 'sucursal_principal_id', 'estatus', 'fecha_ingreso', 'fecha_baja'])
            ->filter(fn (Colaborador $c): bool => ReglaListasAsistencia::activoEnSemana($c, $inicio, $fin))
            ->values();
    }
}
