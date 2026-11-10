<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FichaAltaRapidaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tarjeta «Datos para dar de alta» (hover en Expedientes y en el tablero de
 * Candidatos). JSON bajo demanda; la regla de acceso es la misma que abrir
 * el expediente o el candidato.
 */
class FichaAltaRapidaController extends Controller
{
    public function __construct(
        private readonly FichaAltaRapidaService $fichas,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function colaborador(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();

        // Igual que ExpedienteController::renderExpediente(): fuera de
        // alcance no se ve, ni siquiera la ficha corta.
        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 403);

        return response()->json($this->fichas->deColaborador($colaborador));
    }

    public function candidato(Request $request, Candidato $candidato): JsonResponse
    {
        $this->authorize('view', $candidato);

        return response()->json($this->fichas->deCandidato($candidato, $request->user()));
    }
}
