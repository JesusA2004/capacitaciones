<?php

namespace App\Http\Controllers;

use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Directorio\DirectorioColaboradoresService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Directorio de la empresa (correo/teléfono corporativo por colaborador):
 * visible a cualquier colaborador autenticado, acotado a su alcance
 * organizacional — no es una pantalla de administración de RH.
 */
class DirectorioController extends Controller
{
    public function __construct(private readonly DirectorioColaboradoresService $directorio) {}

    public function index(Request $request): Response
    {
        $filtros = $request->only(['busqueda', 'puesto_id', 'sucursal_id', 'departamento_id', 'solo_con_correo']);

        return Inertia::render('Directorio/Index', [
            'colaboradores' => $this->directorio->buscar($filtros)
                ->through(fn ($colaborador) => $this->directorio->aArray($colaborador)),
            'filtros' => $filtros,
            'opciones' => [
                'puestos' => Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            ],
        ]);
    }
}
