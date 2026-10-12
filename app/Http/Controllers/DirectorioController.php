<?php

namespace App\Http\Controllers;

use App\Services\Directorio\DirectorioColaboradoresService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Directorio de la empresa (correo/teléfono corporativo por colaborador):
 * visible a cualquier colaborador autenticado, GLOBAL (no acotado por
 * alcance organizacional) y solo con contacto corporativo — ver la regla en
 * DirectorioColaboradoresService. Mismo service que
 * Api\V1\DirectorioController (app móvil).
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
            'opciones' => $this->directorio->opciones(),
        ]);
    }
}
