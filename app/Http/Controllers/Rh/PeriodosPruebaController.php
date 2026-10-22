<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Services\Contratos\PeriodosPruebaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Compatibilidad: «Periodos por vencer» ya no es pantalla propia (era la
 * misma información que las evaluaciones con otro filtro). Vive como la
 * pestaña «Próximas / por asignar» de «Evaluaciones de periodo de prueba»;
 * la ruta rh.periodos-prueba.index se conserva para enlaces y avisos viejos
 * y redirige ahí con sus mismos filtros.
 */
class PeriodosPruebaController extends Controller
{
    public function __construct(private readonly PeriodosPruebaService $periodos) {}

    public function index(Request $request): RedirectResponse
    {
        abort_unless($this->periodos->puedeVer($request->user()), 403);

        return to_route('rh.evaluaciones.index', [
            'tab' => 'proximas',
            ...array_filter($request->only(['busqueda', 'sucursal_id', 'solo_alerta']), fn ($v) => $v !== null && $v !== ''),
        ]);
    }
}
