<?php

namespace App\Http\Controllers\Reuniones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reuniones\StoreSesionEnVivoRequest;
use App\Http\Requests\Reuniones\UpdateSesionEnVivoRequest;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Services\Reuniones\SesionEnVivoService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SesionEnVivoController extends Controller
{
    public function __construct(private readonly SesionEnVivoService $service) {}

    public function edit(Curso $curso, CursoModulo $modulo, Leccion $leccion): Response
    {
        $this->authorize('create', SesionEnVivo::class);
        abort_unless($modulo->curso_id === $curso->id && $leccion->curso_modulo_id === $modulo->id, 404);

        $leccion->load('sesionEnVivo');

        return Inertia::render('Reuniones/Constructor', [
            'curso' => $curso,
            'leccion' => $leccion,
            'sesion' => $leccion->sesionEnVivo,
        ]);
    }

    public function store(StoreSesionEnVivoRequest $request, Curso $curso, CursoModulo $modulo, Leccion $leccion): RedirectResponse
    {
        abort_unless($modulo->curso_id === $curso->id && $leccion->curso_modulo_id === $modulo->id, 404);

        $this->service->crear($leccion, $request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Sesión en vivo programada correctamente.']);
    }

    public function update(UpdateSesionEnVivoRequest $request, SesionEnVivo $sesion): RedirectResponse
    {
        $this->service->actualizar($sesion, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Sesión actualizada correctamente.']);
    }

    public function destroy(SesionEnVivo $sesion): RedirectResponse
    {
        $this->authorize('delete', $sesion);

        $this->service->cancelar($sesion);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sesión cancelada correctamente.']);
    }
}
