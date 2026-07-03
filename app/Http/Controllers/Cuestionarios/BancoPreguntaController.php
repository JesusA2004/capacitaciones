<?php

namespace App\Http\Controllers\Cuestionarios;

use App\Enums\TipoPregunta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuestionarios\StoreBancoPreguntaRequest;
use App\Http\Requests\Cuestionarios\UpdateBancoPreguntaRequest;
use App\Models\BancoPregunta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BancoPreguntaController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', BancoPregunta::class);

        $bancos = BancoPregunta::query()
            ->withCount('preguntas')
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->where('nombre', 'like', "%{$busqueda}%"))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Cuestionarios/BancosPreguntas/Index', [
            'bancos' => $bancos,
            'filtros' => $request->only('busqueda'),
        ]);
    }

    public function store(StoreBancoPreguntaRequest $request): RedirectResponse
    {
        BancoPregunta::create([...$request->validated(), 'creado_por' => $request->user()->id]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Banco de preguntas creado correctamente.']);
    }

    public function show(BancoPregunta $banco): Response
    {
        $this->authorize('view', $banco);

        $banco->load('preguntas.opciones');

        return Inertia::render('Cuestionarios/BancosPreguntas/Show', [
            'banco' => $banco,
            'tipos' => array_map(fn (TipoPregunta $tipo) => ['value' => $tipo->value, 'etiqueta' => $tipo->etiqueta()], TipoPregunta::cases()),
        ]);
    }

    public function update(UpdateBancoPreguntaRequest $request, BancoPregunta $banco): RedirectResponse
    {
        $banco->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Banco de preguntas actualizado correctamente.']);
    }

    public function destroy(BancoPregunta $banco): RedirectResponse
    {
        $this->authorize('delete', $banco);

        $banco->delete();

        return redirect()->route('bancos-preguntas.index')->with('toast', ['type' => 'success', 'message' => 'Banco de preguntas eliminado correctamente.']);
    }
}
