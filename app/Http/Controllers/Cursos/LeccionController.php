<?php

namespace App\Http\Controllers\Cursos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cursos\StoreLeccionRequest;
use App\Http\Requests\Cursos\UpdateLeccionRequest;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Services\Capacitacion\CursoBuilderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeccionController extends Controller
{
    public function __construct(private readonly CursoBuilderService $builder) {}

    public function store(StoreLeccionRequest $request, Curso $curso, CursoModulo $modulo): RedirectResponse
    {
        abort_unless($modulo->curso_id === $curso->id, 404);

        $this->builder->crearLeccion($modulo, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Lección agregada correctamente.']);
    }

    public function update(UpdateLeccionRequest $request, Curso $curso, CursoModulo $modulo, Leccion $leccion): RedirectResponse
    {
        abort_unless($modulo->curso_id === $curso->id && $leccion->curso_modulo_id === $modulo->id, 404);

        $this->builder->actualizarLeccion($leccion, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Lección actualizada correctamente.']);
    }

    public function destroy(Curso $curso, CursoModulo $modulo, Leccion $leccion): RedirectResponse
    {
        $this->authorize('update', $curso);
        abort_unless($modulo->curso_id === $curso->id && $leccion->curso_modulo_id === $modulo->id, 404);

        $this->builder->eliminarLeccion($leccion);

        return back()->with('toast', ['type' => 'success', 'message' => 'Lección eliminada correctamente.']);
    }

    public function mover(Request $request, Curso $curso, CursoModulo $modulo, Leccion $leccion): RedirectResponse
    {
        $this->authorize('update', $curso);
        abort_unless($modulo->curso_id === $curso->id && $leccion->curso_modulo_id === $modulo->id, 404);

        $request->validate(['direccion' => ['required', 'in:arriba,abajo']]);

        $this->builder->moverLeccion($modulo, $leccion, $request->string('direccion')->toString());

        return back();
    }
}
