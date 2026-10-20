<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Services\Contratos\PeriodosPruebaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Periodos por vencer» (Etapa 4): quién sigue dentro de su periodo de
 * prueba, calculado por fecha de ingreso (PeriodosPruebaService).
 */
class PeriodosPruebaController extends Controller
{
    public function __construct(private readonly PeriodosPruebaService $periodos) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($this->periodos->puedeVer($usuario), 403);

        $filtros = $request->only(['busqueda', 'sucursal_id', 'solo_alerta']);
        $listado = $this->periodos->listar($usuario, $filtros);

        return Inertia::render('Rh/PeriodosPrueba/Index', [
            'filas' => $listado['filas'],
            'resumen' => $listado['resumen'],
            'sinDuracion' => $listado['sin_duracion'],
            'diasAlerta' => $listado['dias_alerta'],
            'filtros' => $filtros,
        ]);
    }
}
