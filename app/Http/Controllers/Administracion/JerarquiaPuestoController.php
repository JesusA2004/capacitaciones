<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\ActualizarJerarquiaPuestoRequest;
use App\Models\Puesto;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class JerarquiaPuestoController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Puesto::class);

        $puestos = Puesto::query()
            ->with([
                'departamento:id,nombre',
                'puestoSuperior:id,nombre',
                'puestoCrecimiento:id,nombre',
                'respaldos:id,nombre',
                'puestosQuePuedeCubrir:id,nombre',
            ])
            ->withCount([
                'usuarios',
                'candidatos',
                'vacantes as vacantes_abiertas_count' => fn ($query) => $query->whereNotIn('estado', ['cubierta', 'cancelada']),
            ])
            ->orderBy('nivel_jerarquico')
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Administracion/JerarquiaPuestos/Index', [
            'puestos' => $puestos,
        ]);
    }

    public function actualizar(ActualizarJerarquiaPuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $datos = $request->safe()->except('respaldos');

        $puesto->update($datos);

        if ($request->has('respaldos')) {
            $puesto->respaldos()->sync($request->input('respaldos', []));
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Jerarquía del puesto actualizada correctamente.']);
    }
}
