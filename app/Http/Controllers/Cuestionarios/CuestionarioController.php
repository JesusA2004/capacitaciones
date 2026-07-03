<?php

namespace App\Http\Controllers\Cuestionarios;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cuestionarios\ActualizarPreguntasCuestionarioRequest;
use App\Http\Requests\Cuestionarios\StoreCuestionarioRequest;
use App\Http\Requests\Cuestionarios\UpdateCuestionarioRequest;
use App\Models\BancoPregunta;
use App\Models\Cuestionario;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Services\Evaluacion\CuestionarioService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CuestionarioController extends Controller
{
    public function __construct(private readonly CuestionarioService $service) {}

    public function edit(Curso $curso, CursoModulo $modulo, Leccion $leccion): Response
    {
        $this->authorize('create', Cuestionario::class);
        abort_unless($modulo->curso_id === $curso->id && $leccion->curso_modulo_id === $modulo->id, 404);

        $leccion->load('cuestionario.preguntas.opciones');

        return Inertia::render('Cuestionarios/Constructor', [
            'curso' => $curso,
            'leccion' => $leccion,
            'cuestionario' => $leccion->cuestionario,
            'bancos' => BancoPregunta::query()->with('preguntas.opciones')->orderBy('nombre')->get(),
        ]);
    }

    public function store(StoreCuestionarioRequest $request, Curso $curso, CursoModulo $modulo, Leccion $leccion): RedirectResponse
    {
        abort_unless($modulo->curso_id === $curso->id && $leccion->curso_modulo_id === $modulo->id, 404);

        $this->service->crear($leccion, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Cuestionario configurado correctamente.']);
    }

    public function update(UpdateCuestionarioRequest $request, Cuestionario $cuestionario): RedirectResponse
    {
        $this->service->actualizar($cuestionario, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Cuestionario actualizado correctamente.']);
    }

    public function actualizarPreguntas(ActualizarPreguntasCuestionarioRequest $request, Cuestionario $cuestionario): RedirectResponse
    {
        $this->service->actualizarPreguntas($cuestionario, $request->validated('preguntas', []));

        return back()->with('toast', ['type' => 'success', 'message' => 'Preguntas del cuestionario actualizadas correctamente.']);
    }

    public function destroy(Cuestionario $cuestionario): RedirectResponse
    {
        $this->authorize('delete', $cuestionario);

        $this->service->eliminar($cuestionario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Cuestionario eliminado correctamente.']);
    }
}
