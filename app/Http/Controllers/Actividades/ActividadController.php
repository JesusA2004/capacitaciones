<?php

namespace App\Http\Controllers\Actividades;

use App\Http\Controllers\Controller;
use App\Http\Requests\Actividades\StoreActividadRequest;
use App\Http\Requests\Actividades\UpdateActividadRequest;
use App\Models\Actividad;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Services\Evaluacion\ActividadService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ActividadController extends Controller
{
    public function __construct(private readonly ActividadService $service) {}

    public function edit(Curso $curso, CursoModulo $modulo, Leccion $leccion): Response
    {
        $this->authorize('create', Actividad::class);
        abort_unless($modulo->curso_id === $curso->id && $leccion->curso_modulo_id === $modulo->id, 404);

        $leccion->load('actividad');

        return Inertia::render('Actividades/Constructor', [
            'curso' => $curso,
            'leccion' => $leccion,
            'actividad' => $leccion->actividad,
        ]);
    }

    public function store(StoreActividadRequest $request, Curso $curso, CursoModulo $modulo, Leccion $leccion): RedirectResponse
    {
        abort_unless($modulo->curso_id === $curso->id && $leccion->curso_modulo_id === $modulo->id, 404);

        $this->service->crear($leccion, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Actividad configurada correctamente.']);
    }

    public function update(UpdateActividadRequest $request, Actividad $actividad): RedirectResponse
    {
        $this->service->actualizar($actividad, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Actividad actualizada correctamente.']);
    }

    public function destroy(Actividad $actividad): RedirectResponse
    {
        $this->authorize('delete', $actividad);

        $this->service->eliminar($actividad);

        return back()->with('toast', ['type' => 'success', 'message' => 'Actividad eliminada correctamente.']);
    }
}
