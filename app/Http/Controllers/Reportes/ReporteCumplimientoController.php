<?php

namespace App\Http\Controllers\Reportes;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Models\Departamento;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Reportes\ReporteCumplimientoService;
use App\Support\Export\ChartData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReporteCumplimientoController extends Controller
{
    public function __construct(private readonly ReporteCumplimientoService $service) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        if (! $usuario->can('reportes.sucursal') && ! $usuario->can('reportes.globales')) {
            abort(403);
        }

        $filtros = $request->only(['sucursal_id', 'departamento_id', 'curso_id']);
        $grafica = $this->graficaPorRangoCumplimiento($usuario, $filtros);

        return Inertia::render('Reportes/Cumplimiento', [
            'colaboradores' => $this->service->porColaborador($usuario, $filtros),
            'filtros' => $filtros,
            'grafica' => $grafica === null ? null : [
                'tipo' => $grafica->tipo,
                'categorias' => $grafica->categorias,
                'series' => $grafica->series,
                'recortado' => $grafica->recortado,
            ],
            'puedeExportar' => $usuario->can('reportes.exportar'),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'cursos' => Curso::query()->orderBy('titulo')->get(['id', 'titulo']),
        ]);
    }

    /**
     * Distribución de colaboradores (respetando los mismos filtros de la
     * tabla) por rango de % de cumplimiento, reusando el mismo normalizador
     * de tabla → gráfica que los exports (App\Support\Export\ChartData) en
     * vez de duplicar lógica de graficación.
     *
     * @param  array{sucursal_id?: int|string|null, departamento_id?: int|string|null, curso_id?: int|string|null}  $filtros
     */
    private function graficaPorRangoCumplimiento(User $usuarioActual, array $filtros): ?ChartData
    {
        $rangos = ['0%–25%' => 0, '25%–50%' => 0, '50%–75%' => 0, '75%–100%' => 0];

        foreach ($this->service->todosLosColaboradores($usuarioActual, $filtros) as $colaborador) {
            $total = (int) $colaborador->getAttribute('asignaciones_total');
            $completadas = (int) $colaborador->getAttribute('asignaciones_completadas');
            $porcentaje = $total > 0 ? ($completadas / $total) * 100 : 0.0;

            $rango = match (true) {
                $porcentaje < 25 => '0%–25%',
                $porcentaje < 50 => '25%–50%',
                $porcentaje < 75 => '50%–75%',
                default => '75%–100%',
            };

            $rangos[$rango]++;
        }

        $filas = array_map(fn (string $rango, int $total) => [$rango, $total], array_keys($rangos), array_values($rangos));

        return ChartData::fromTable(['Rango de cumplimiento', 'Colaboradores'], $filas);
    }
}
