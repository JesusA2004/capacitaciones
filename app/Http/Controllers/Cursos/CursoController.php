<?php

namespace App\Http\Controllers\Cursos;

use App\Enums\EstadoCurso;
use App\Enums\EstadoMultimedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cursos\StoreCursoRequest;
use App\Http\Requests\Cursos\UpdateCursoRequest;
use App\Models\Curso;
use App\Models\RecursoMultimedia;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CursoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Curso::class);

        $cursos = Curso::query()
            ->withCount('modulos')
            ->with('responsable:id,name,apellidos')
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->where('titulo', 'like', "%{$busqueda}%"))
            ->when($request->string('estado')->toString(), fn ($query, string $estado) => $query->where('estado', $estado))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Cursos/Index', [
            'cursos' => $cursos,
            'filtros' => $request->only('busqueda', 'estado'),
            'estados' => array_map(fn (EstadoCurso $estado) => ['value' => $estado->value, 'etiqueta' => $estado->etiqueta()], EstadoCurso::cases()),
        ]);
    }

    public function store(StoreCursoRequest $request): RedirectResponse
    {
        $curso = Curso::create($request->safe()->except('requisitos_previos'));
        $curso->requisitosPrevios()->sync($request->input('requisitos_previos', []));

        return redirect()
            ->route('cursos.edit', $curso)
            ->with('toast', ['type' => 'success', 'message' => 'Curso creado. Ahora agrega módulos y lecciones.']);
    }

    public function edit(Curso $curso): Response
    {
        $this->authorize('view', $curso);

        $curso->load(['modulos.lecciones.requisitos:id,titulo', 'requisitosPrevios:id,titulo']);

        return Inertia::render('Cursos/Constructor', [
            'curso' => $curso,
            'cursosDisponibles' => Curso::query()->where('id', '!=', $curso->id)->orderBy('titulo')->get(['id', 'titulo']),
            'responsablesDisponibles' => User::query()->orderBy('name')->get(['id', 'name', 'apellidos']),
            'recursosMultimediaDisponibles' => RecursoMultimedia::query()
                ->where('estado', EstadoMultimedia::Disponible->value)
                ->orderByDesc('created_at')
                ->get(['id', 'tipo', 'nombre_original']),
        ]);
    }

    public function update(UpdateCursoRequest $request, Curso $curso): RedirectResponse
    {
        $curso->update($request->safe()->except('requisitos_previos'));
        $curso->requisitosPrevios()->sync($request->input('requisitos_previos', []));

        return back()->with('toast', ['type' => 'success', 'message' => 'Curso actualizado correctamente.']);
    }

    public function destroy(Curso $curso): RedirectResponse
    {
        $this->authorize('delete', $curso);

        $curso->delete();

        return redirect()->route('cursos.index')->with('toast', ['type' => 'success', 'message' => 'Curso eliminado correctamente.']);
    }

    public function publicar(Curso $curso): RedirectResponse
    {
        $this->authorize('publicar', $curso);

        $curso->update(['estado' => EstadoCurso::Publicado, 'publicado_en' => $curso->publicado_en ?? now()]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Curso publicado correctamente.']);
    }

    public function archivar(Curso $curso): RedirectResponse
    {
        $this->authorize('publicar', $curso);

        $curso->update(['estado' => EstadoCurso::Archivado]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Curso archivado correctamente.']);
    }
}
