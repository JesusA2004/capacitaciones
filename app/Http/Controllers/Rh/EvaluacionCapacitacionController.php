<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoEvaluacionPrueba;
use App\Http\Controllers\Controller;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Evaluación de capacitación inicial»: tablero de las evaluaciones que se
 * abren 15 días antes de que venza el contrato de capacitación
 * (VencimientoContratosService). La captura y la autorización viven en el
 * ciclo de cada persona; aquí se ve qué toca, a quién y cuándo. Misma
 * fuente (EvaluacionPeriodoPruebaService) que la API.
 */
class EvaluacionCapacitacionController extends Controller
{
    public function __construct(private readonly EvaluacionPeriodoPruebaService $evaluaciones) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->can('evaluaciones.capturar') || $usuario->can('evaluaciones.autorizar'), 403);

        $estado = EstadoEvaluacionPrueba::tryFrom($request->string('estado')->toString());
        $pagina = $this->evaluaciones->listar($usuario, ['estado' => $estado?->value, 'per_page' => 30])->withQueryString();
        $hoy = Carbon::today('America/Mexico_City');

        $pagina->getCollection()->transform(function (EvaluacionPeriodoPrueba $e) use ($hoy) {
            $e->loadMissing(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre']);
            $datos = $this->evaluaciones->aArray($e);

            return [
                ...$datos,
                'puesto' => $e->colaborador->puesto?->nombre,
                'sucursal' => $e->colaborador->sucursalPrincipal?->nombre,
                'dias_restantes' => $e->fecha_limite !== null ? (int) $hoy->diffInDays($e->fecha_limite, false) : null,
                'url' => route('rh.colaboradores.ciclo', $e->colaborador_id),
            ];
        });

        return Inertia::render('Rh/Evaluaciones/Index', [
            'evaluaciones' => $pagina,
            'estados' => array_map(fn (EstadoEvaluacionPrueba $e) => ['value' => $e->value, 'label' => $e->etiqueta()], EstadoEvaluacionPrueba::cases()),
            'filtros' => ['estado' => $estado?->value],
            'puedeAutorizar' => $usuario->can('evaluaciones.autorizar'),
        ]);
    }
}
