<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReclutamientoController extends Controller
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vacante::class);

        $usuario = $request->user();

        $vacantesPorEstado = $this->alcance
            ->limitarPorSucursal(Vacante::query(), $usuario)
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $candidatosPorEstado = $this->alcance
            ->limitarPorSucursal(Candidato::query(), $usuario)
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return Inertia::render('Rh/Reclutamiento/Index', [
            'resumen' => [
                'vacantes_abiertas' => (int) $vacantesPorEstado->except([EstadoVacante::Cubierta->value, EstadoVacante::Cancelada->value])->sum(),
                'vacantes_cubiertas' => (int) ($vacantesPorEstado[EstadoVacante::Cubierta->value] ?? 0),
                'candidatos_en_proceso' => (int) $candidatosPorEstado->except([
                    EstadoCandidato::Contratado->value,
                    EstadoCandidato::Rechazado->value,
                    EstadoCandidato::Descartado->value,
                ])->sum(),
                'candidatos_contratados' => (int) ($candidatosPorEstado[EstadoCandidato::Contratado->value] ?? 0),
            ],
            'vacantesPorEstado' => $vacantesPorEstado,
            'candidatosPorEstado' => $candidatosPorEstado,
        ]);
    }
}
