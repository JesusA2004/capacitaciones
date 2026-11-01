<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\SesionInduccionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Calificaciones y refuerzo» (grupo «3 · Onboarding»): resultados de la
 * evaluación de inducción FO-RH-EI y de las lecciones por colaborador, con
 * acceso a calificar, retroalimentar (reforzamiento → reevaluación) y ver el
 * PDF. No confundir con las evaluaciones de PERIODO DE PRUEBA (rh.evaluaciones,
 * grupo «4»). La regla y el alcance viven en SesionInduccionService.
 */
class CalificacionInduccionController extends Controller
{
    public function __construct(
        private readonly SesionInduccionService $sesiones,
        private readonly MaterialInduccionService $materiales,
    ) {}

    public function index(Request $request): Response
    {
        // Acción «Calificar y retroalimentar» (Roles y permisos); el Service
        // exige además onboarding.gestionar.
        abort_unless($request->user()->can('onboarding.calificar'), 403);

        return Inertia::render('Rh/Onboarding/CalificacionesInduccion', [
            'filas' => $this->sesiones->calificacionesRh($request->user()),
            // ?colaborador= desde «Calificar inducción»: solo dentro de su alcance.
            'colaboradorBuscado' => ($c = Colaborador::query()->whereKey($request->integer('colaborador'))->first()) !== null && app(AlcanceOrganizacionalService::class)->alcanzaColaborador($request->user(), $c) ? $c->nombreCompleto() : null,
            // «Asignar inducción» desde aquí: qué material hay vigente.
            'materialInduccion' => ($modulo = $this->materiales->moduloInstitucional()) !== null ? $this->materiales->materialActivo($modulo)?->tipo->value : null,
            'puedeAsignar' => $request->user()->can('onboarding.gestionar'),
        ]);
    }
}
