<?php

namespace App\Http\Controllers\Reportes;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Models\Departamento;
use App\Models\Sucursal;
use App\Services\Reportes\ReporteCumplimientoService;
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

        return Inertia::render('Reportes/Cumplimiento', [
            'colaboradores' => $this->service->porColaborador($usuario, $filtros),
            'filtros' => $filtros,
            'puedeExportar' => $usuario->can('reportes.exportar'),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'cursos' => Curso::query()->orderBy('titulo')->get(['id', 'titulo']),
        ]);
    }
}
