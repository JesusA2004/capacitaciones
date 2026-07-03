<?php

namespace App\Http\Controllers\Cursos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cursos\StoreCursoModuloRequest;
use App\Http\Requests\Cursos\UpdateCursoModuloRequest;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Services\Capacitacion\CursoBuilderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CursoModuloController extends Controller
{
    public function __construct(private readonly CursoBuilderService $builder) {}

    public function store(StoreCursoModuloRequest $request, Curso $curso): RedirectResponse
    {
        $this->builder->crearModulo($curso, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Módulo agregado correctamente.']);
    }

    public function update(UpdateCursoModuloRequest $request, Curso $curso, CursoModulo $modulo): RedirectResponse
    {
        abort_unless($modulo->curso_id === $curso->id, 404);

        $this->builder->actualizarModulo($modulo, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Módulo actualizado correctamente.']);
    }

    public function destroy(Curso $curso, CursoModulo $modulo): RedirectResponse
    {
        $this->authorize('update', $curso);
        abort_unless($modulo->curso_id === $curso->id, 404);

        $this->builder->eliminarModulo($modulo);

        return back()->with('toast', ['type' => 'success', 'message' => 'Módulo eliminado correctamente.']);
    }

    public function mover(Request $request, Curso $curso, CursoModulo $modulo): RedirectResponse
    {
        $this->authorize('update', $curso);
        abort_unless($modulo->curso_id === $curso->id, 404);

        $request->validate(['direccion' => ['required', 'in:arriba,abajo']]);

        $this->builder->moverModulo($curso, $modulo, $request->string('direccion')->toString());

        return back();
    }
}
