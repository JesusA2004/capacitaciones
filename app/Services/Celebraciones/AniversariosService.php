<?php

namespace App\Services\Celebraciones;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Detección de ANIVERSARIOS LABORALES (docs/CELEBRACIONES.md): única fuente
 * para web, API y scheduler.
 *
 * Reglas:
 *  - solo colaboradores ACTIVOS con `fecha_ingreso`;
 *  - años = diferencia exacta de calendario contra `fecha_ingreso` vigente
 *    (el modelo no registra continuidad de antigüedad en reingresos: se
 *    usa la fecha de ingreso actual del colaborador);
 *  - no se celebra el año 0 (quien ingresó hoy no tiene aniversario);
 *  - ingreso 29/feb → se celebra el 28/feb en años no bisiestos;
 *  - "hoy" en America/Mexico_City.
 */
class AniversariosService
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * @param  array{empresa_id?: int|null, sucursal_id?: int|null, departamento_id?: int|null, busqueda?: string|null}  $filtros
     * @return Collection<int, array{colaborador: Colaborador, fecha: Carbon, anios: int}>
     */
    public function deHoy(?User $usuario = null, array $filtros = []): Collection
    {
        $hoy = FechasCelebracion::hoy();

        return $this->enRango($hoy, $hoy, $usuario, $filtros);
    }

    /**
     * @param  array{empresa_id?: int|null, sucursal_id?: int|null, departamento_id?: int|null, busqueda?: string|null}  $filtros
     * @return Collection<int, array{colaborador: Colaborador, fecha: Carbon, anios: int}>
     */
    public function proximos(int $dias, ?User $usuario = null, array $filtros = []): Collection
    {
        $hoy = FechasCelebracion::hoy();

        return $this->enRango($hoy, $hoy->copy()->addDays(max(0, $dias)), $usuario, $filtros);
    }

    /**
     * Aniversarios entre $desde y $hasta (inclusive), ordenados por fecha.
     *
     * @param  array{empresa_id?: int|null, sucursal_id?: int|null, departamento_id?: int|null, busqueda?: string|null}  $filtros
     * @return Collection<int, array{colaborador: Colaborador, fecha: Carbon, anios: int}>
     */
    public function enRango(CarbonInterface $desde, CarbonInterface $hasta, ?User $usuario = null, array $filtros = []): Collection
    {
        $desde = Carbon::parse($desde->toDateString());
        $hasta = Carbon::parse($hasta->toDateString());

        $filas = collect();

        foreach ($this->consulta($usuario, $filtros)->get() as $colaborador) {
            $ingreso = $colaborador->fecha_ingreso;

            if ($ingreso === null) {
                continue;
            }

            $fecha = FechasCelebracion::proxima($ingreso, $desde);
            $anios = FechasCelebracion::aniosCumplidos($ingreso, $fecha);

            if ($anios >= 1 && $fecha->betweenIncluded($desde, $hasta)) {
                $filas->push(['colaborador' => $colaborador, 'fecha' => $fecha, 'anios' => $anios]);
            }
        }

        return $filas
            ->sortBy(fn (array $a) => sprintf('%s-%s', $a['fecha']->toDateString(), $a['colaborador']->nombreCompleto()))
            ->values();
    }

    /**
     * Aniversario de un colaborador en una fecha concreta, o null si ese día
     * no le toca (o no cumpliría al menos un año).
     */
    public function aniosEn(Colaborador $colaborador, CarbonInterface $fecha): ?int
    {
        if ($colaborador->fecha_ingreso === null || ! FechasCelebracion::esHoy($colaborador->fecha_ingreso, $fecha)) {
            return null;
        }

        $anios = FechasCelebracion::aniosCumplidos($colaborador->fecha_ingreso, $fecha);

        return $anios >= 1 ? $anios : null;
    }

    /**
     * @param  array{empresa_id?: int|null, sucursal_id?: int|null, departamento_id?: int|null, busqueda?: string|null}  $filtros
     * @return Builder<Colaborador>
     */
    private function consulta(?User $usuario, array $filtros): Builder
    {
        $consulta = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereNotNull('fecha_ingreso')
            ->with(['sucursalPrincipal:id,nombre,empresa_id', 'puesto:id,nombre', 'departamento:id,nombre'])
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, int $id) => $q->whereHas('sucursalPrincipal', fn (Builder $s) => $s->where('empresa_id', $id)))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, int $id) => $q->where('sucursal_principal_id', $id))
            ->when($filtros['departamento_id'] ?? null, fn (Builder $q, int $id) => $q->where('departamento_id', $id))
            ->when($filtros['busqueda'] ?? null, fn (Builder $q, string $texto) => $q->where(fn (Builder $s) => $s
                ->where('name', 'like', "%{$texto}%")->orWhere('apellidos', 'like', "%{$texto}%")));

        return $usuario !== null ? $this->alcance->limitarColaboradoresPorAlcance($consulta, $usuario) : $consulta;
    }
}
