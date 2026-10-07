<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reclutamiento\DecidirIntervencionCandidatoRequest;
use App\Http\Requests\Reclutamiento\SolicitarIntervencionCandidatoRequest;
use App\Models\Candidato;
use App\Models\IntervencionCandidato;
use App\Services\Reclutamiento\IntervencionCandidatoService;
use Illuminate\Http\RedirectResponse;

/**
 * Intervención por jerarquía sobre un rechazo de RH (CLAUDE.md §10-12):
 * delgado, delega TODO a IntervencionCandidatoService — misma autoridad de
 * negocio que usaría la API móvil.
 */
class IntervencionCandidatoController extends Controller
{
    public function __construct(private readonly IntervencionCandidatoService $intervenciones) {}

    public function solicitar(SolicitarIntervencionCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->intervenciones->solicitar($candidato, $request->user(), (string) $request->validated('motivo'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Intervención solicitada.']);
    }

    public function decidir(DecidirIntervencionCandidatoRequest $request, IntervencionCandidato $intervencion): RedirectResponse
    {
        $aprueba = (bool) $request->validated('aprueba');

        $this->intervenciones->decidir($intervencion, $request->user(), $aprueba, $request->validated('comentario'));

        return back()->with('toast', ['type' => 'success', 'message' => $aprueba ? 'Intervención aprobada: el candidato pasa a contratación.' : 'Rechazo confirmado.']);
    }
}
