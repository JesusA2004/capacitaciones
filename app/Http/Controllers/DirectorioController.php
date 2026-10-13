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
        // Lista completa: la búsqueda y las vistas Por puesto / Por área /
        // Por sucursal se resuelven al instante en pantalla.
        return Inertia::render('Directorio/Index', [
            'colaboradores' => $this->directorio->todos(),
            'filtros' => $request->only(['busqueda', 'vista']),
        ]);
    }
}
