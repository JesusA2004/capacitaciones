<?php

namespace App\Http\Controllers\Cuestionarios;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cuestionarios\StorePreguntaRequest;
use App\Http\Requests\Cuestionarios\UpdatePreguntaRequest;
use App\Models\BancoPregunta;
use App\Models\Pregunta;
use App\Services\Evaluacion\BancoPreguntaService;
use Illuminate\Http\RedirectResponse;

class PreguntaController extends Controller
{
    public function __construct(private readonly BancoPreguntaService $service) {}

    public function store(StorePreguntaRequest $request, BancoPregunta $banco): RedirectResponse
    {
        $this->service->crearPregunta($banco, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Pregunta agregada correctamente.']);
    }

    public function update(UpdatePreguntaRequest $request, BancoPregunta $banco, Pregunta $pregunta): RedirectResponse
    {
        $this->service->actualizarPregunta($pregunta, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Pregunta actualizada correctamente.']);
    }

    public function destroy(BancoPregunta $banco, Pregunta $pregunta): RedirectResponse
    {
        $this->authorize('update', $banco);

        $this->service->eliminarPregunta($pregunta);

        return back()->with('toast', ['type' => 'success', 'message' => 'Pregunta eliminada correctamente.']);
    }
}
